<?php

namespace App\Domain\Integration\Sap;

use App\Models\InboxEvent;
use App\Models\IntegrationException;
use App\Models\Outlet;
use App\Models\SapDocument;
use App\Models\SapMapping;
use App\Support\Decimal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SAP Bridge di Rasentra Central. Penjualan diposting ke instance SAP milik CV pemilik
 * outlet (mis. sap_solo atau sap_lombok); mapping item, gudang dan akun juga per instance.
 * Satu sap_documents per objek SAP per sumber, sehingga invoice dan pembayaran berhasil/gagal
 * sendiri-sendiri. Sebelum membuat dokumen, cari dulu berdasarkan referensi sumber agar
 * timeout atau crash setelah commit SAP tidak menggandakan.
 */
final class SapPostingService
{
    public function __construct(private readonly SapClientFactory $clients) {}

    /**
     * Posting penjualan yang sudah diterima inbox Rasentra Central dan belum terposting.
     *
     * @return array<string, int> jumlah per status hasil
     */
    public function postInbox(int $limit = 200): array
    {
        $summary = [];
        $events = InboxEvent::where('event_type', 'order.finalized')->where('status', 'accepted')->orderBy('id')->limit($limit)->get();
        foreach ($events as $event) {
            $result = $this->postSale($event->payload);
            if ($result['status'] === 'posted') {
                $event->update(['status' => 'processed']);
            }
            $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
        }

        return $summary;
    }

    /** Posting penjualan (payload event order.finalized): A/R Invoice lalu Incoming Payment. */
    public function postSale(array $order): array
    {
        $companyDb = Outlet::sapCompanyDb((int) $order['outlet_id']);
        if (! $companyDb) {
            IntegrationException::updateOrCreate(
                ['kind' => 'missing_mapping', 'source_type' => 'order', 'source_id' => $order['id'], 'status' => 'open'],
                ['message' => 'Perusahaan pemilik outlet belum punya database SAP.'],
            );

            return ['status' => 'failed', 'missing' => ['company:sap_company_db']];
        }
        $lock = Cache::lock("sap:order:{$order['id']}", 120);
        if (! $lock->get()) {
            return ['status' => 'locked'];
        }

        try {
            $missing = $this->missingMappings($companyDb, $order);
            if ($missing !== []) {
                // Satu catatan terbuka per order, agar posting berkala tidak menumpuk duplikat.
                IntegrationException::updateOrCreate(
                    ['kind' => 'missing_mapping', 'source_type' => 'order', 'source_id' => $order['id'], 'status' => 'open'],
                    ['message' => "Mapping SAP {$companyDb} belum ada: ".implode(', ', $missing)],
                );

                return ['status' => 'failed', 'missing' => $missing];
            }

            $invoice = $this->post($companyDb, 'order', $order['id'], 'Invoices', $this->invoicePayload($companyDb, $order));
            if ($invoice->status !== 'posted') {
                return ['status' => $invoice->status];
            }

            $payment = $this->post($companyDb, 'order', $order['id'], 'IncomingPayments', $this->paymentPayload($companyDb, $order, $invoice->doc_entry));

            return ['status' => $payment->status === 'posted' ? 'posted' : 'partially_posted'];
        } finally {
            $lock->release();
        }
    }

    private function post(string $companyDb, string $sourceType, string $sourceId, string $object, array $payload): SapDocument
    {
        $doc = DB::transaction(fn () => SapDocument::firstOrCreate(
            ['company_db' => $companyDb, 'source_type' => $sourceType, 'source_id' => $sourceId, 'sap_object' => $object],
            ['status' => 'pending', 'request_payload' => $payload],
        ));

        if ($doc->status === 'posted') {
            return $doc; // retry melanjutkan tahap yang belum selesai saja
        }

        $ref = "{$sourceType}:{$sourceId}";
        try {
            // Pemeriksaan hasil sebelum (ulang) posting.
            $existing = $this->clients->for($companyDb)->findBySourceRef($object, $ref);
            if ($existing) {
                $doc->update(['status' => 'posted', 'doc_entry' => $existing['DocEntry'], 'doc_num' => $existing['DocNum'], 'response_payload' => $existing]);

                return $doc;
            }

            $doc->update(['status' => 'posting', 'attempts' => $doc->attempts + 1]);
            $result = $this->clients->for($companyDb)->create($object, $payload + ['U_POS_SRC' => $ref]);
            $doc->update([
                'status' => 'posted', 'doc_entry' => $result['DocEntry'], 'doc_num' => $result['DocNum'],
                'response_payload' => $result, 'posted_amount' => $payload['DocTotal'] ?? null, 'posting_date' => now()->toDateString(),
            ]);
        } catch (SapTimeout|ConnectionException $e) {
            // Hasil belum pasti: jangan buat ulang; job pemulihan memeriksa referensi di SAP.
            $doc->update(['status' => 'needs_review', 'last_error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $doc->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
        }

        return $doc->fresh();
    }

    private function missingMappings(string $companyDb, array $order): array
    {
        $missing = [];
        foreach ($order['lines'] as $line) {
            if (! SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'product', 'entity_key' => (string) $line['product_id']])->exists()) {
                $missing[] = 'product:'.$line['product_id'];
            }
        }
        foreach ($order['payments'] as $payment) {
            if (! SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'payment_method', 'entity_key' => $payment['method']])->exists()) {
                $missing[] = 'payment_method:'.$payment['method'];
            }
        }

        return array_values(array_unique($missing));
    }

    private function invoicePayload(string $companyDb, array $order): array
    {
        $item = fn ($id) => SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'product', 'entity_key' => (string) $id])->first();
        $warehouse = SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'outlet', 'entity_key' => (string) $order['outlet_id']])->value('sap_code');
        $customer = SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'customer', 'entity_key' => 'walk_in'])->value('sap_code') ?? 'C-UMUM';

        return [
            'CardCode' => $customer,
            'DocDate' => $order['business_date'],
            'NumAtCard' => $order['doc_no'],
            'DocTotal' => $order['net_amount'],
            'DocumentLines' => array_map(fn ($l) => array_filter([
                'ItemCode' => $item($l['product_id'])->sap_code,
                'Quantity' => $l['quantity'],
                'UnitPrice' => $l['unit_price'],
                'LineTotal' => $l['net_amount'],
                'WarehouseCode' => $warehouse,
                'TaxCode' => $item($l['product_id'])->attributes['tax_code'] ?? null,
                'U_POS_LINE_TYPE' => $l['line_type'],
                'U_POS_PACK' => $l['pack_id'],
            ], fn ($v) => $v !== null), $order['lines']),
        ];
    }

    private function paymentPayload(string $companyDb, array $order, int $invoiceDocEntry): array
    {
        $account = fn ($method) => SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'payment_method', 'entity_key' => $method])->value('sap_code');
        $cash = Decimal::sum(collect($order['payments'])->where('method', 'cash')->pluck('amount'));
        $transfers = collect($order['payments'])->where('method', '!=', 'cash');

        return [
            'DocDate' => $order['business_date'],
            'DocTotal' => $order['net_amount'],
            'CashSum' => $cash->isPositive() ? Decimal::money($cash) : null,
            'CashAccount' => $cash->isPositive() ? $account('cash') : null,
            'PaymentInvoices' => [['DocEntry' => $invoiceDocEntry, 'SumApplied' => $order['net_amount']]],
            'TransferAccounts' => $transfers->map(fn ($p) => ['Account' => $account($p['method']), 'Sum' => $p['amount'], 'Reference' => $p['provider_ref']])->values()->all(),
        ];
    }
}
