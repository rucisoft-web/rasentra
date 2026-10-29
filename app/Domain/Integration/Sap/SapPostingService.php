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
    /** Event inbox yang menghasilkan dokumen SAP. */
    public const EVENTS = ['order.finalized', 'order.returned', 'stock.adjusted'];

    public function __construct(private readonly SapClientFactory $clients) {}

    /**
     * Posting penjualan yang sudah diterima inbox Rasentra Central dan belum terposting.
     *
     * @return array<string, int> jumlah per status hasil
     */
    public function postInbox(int $limit = 200): array
    {
        $summary = [];
        $events = InboxEvent::whereIn('event_type', self::EVENTS)->where('status', 'accepted')->orderBy('id')->limit($limit)->get();
        foreach ($events as $event) {
            $result = $this->postEvent($event);
            if (in_array($result['status'], ['posted', 'not_required'], true)) {
                $event->update(['status' => 'processed']);
            }
            $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
        }

        return $summary;
    }

    public function postEvent(InboxEvent $event): array
    {
        return match ($event->event_type) {
            'order.returned' => $this->postReturn($event->payload),
            'stock.adjusted' => $this->postStockAdjustment($event->payload),
            default => $this->postSale($event->payload),
        };
    }

    /**
     * Posting penyesuaian stok outlet: barang keluar dari stok (waste, opname kurang) menjadi Goods Issue
     * (InventoryGenExits), barang masuk (opname lebih) menjadi Goods Receipt (InventoryGenEntries).
     * Lepas karantina hanya memindah status di outlet, jadi tidak perlu dokumen SAP.
     */
    public function postStockAdjustment(array $adj): array
    {
        $exits = array_values(array_filter($adj['lines'], fn ($l) => $l['to_state'] === null));
        $entries = array_values(array_filter($adj['lines'], fn ($l) => $l['from_state'] === null));
        if ($exits === [] && $entries === []) {
            return ['status' => 'not_required'];
        }
        $companyDb = Outlet::sapCompanyDb((int) $adj['outlet_id']);
        if (! $companyDb) {
            return ['status' => 'failed', 'missing' => ['company:sap_company_db']];
        }
        $missing = $this->missingMappings($companyDb, ['lines' => $adj['lines'], 'payments' => []]);
        if ($missing !== []) {
            IntegrationException::updateOrCreate(
                ['kind' => 'missing_mapping', 'source_type' => 'stock_adjustment', 'source_id' => $adj['id'], 'status' => 'open'],
                ['message' => "Mapping SAP {$companyDb} belum ada: ".implode(', ', $missing)],
            );

            return ['status' => 'failed', 'missing' => $missing];
        }
        $lock = Cache::lock("sap:stock_adjustment:{$adj['id']}", 120);
        if (! $lock->get()) {
            return ['status' => 'locked'];
        }

        try {
            $statuses = [];
            foreach (['InventoryGenExits' => $exits, 'InventoryGenEntries' => $entries] as $object => $lines) {
                if ($lines !== []) {
                    $statuses[] = $this->post($companyDb, 'stock_adjustment', $adj['id'], $object, $this->stockPayload($companyDb, $adj, $lines))->status;
                }
            }
            $posted = count(array_filter($statuses, fn ($s) => $s === 'posted'));

            return ['status' => $posted === count($statuses) ? 'posted' : ($posted > 0 ? 'partially_posted' : $statuses[0])];
        } finally {
            $lock->release();
        }
    }

    private function stockPayload(string $companyDb, array $adj, array $lines): array
    {
        $item = fn ($id) => SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'product', 'entity_key' => (string) $id])->value('sap_code');
        $warehouse = SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'outlet', 'entity_key' => (string) $adj['outlet_id']])->value('sap_code');
        // Akun lawan per jenis penyesuaian (mis. biaya waste); tanpa mapping SAP memakai akun bawaan gudang.
        $account = SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'account', 'entity_key' => $adj['kind']])->value('sap_code');
        $labels = ['waste' => 'Waste', 'count' => 'Selisih stok opname', 'release' => 'Lepas karantina'];

        return [
            'DocDate' => $adj['business_date'],
            'Reference2' => $adj['doc_no'],
            'Comments' => trim(($labels[$adj['kind']] ?? $adj['kind']).' '.$adj['doc_no'].': '.($adj['reason'] ?? '')),
            'DocumentLines' => array_map(fn ($l) => array_filter([
                'ItemCode' => $item($l['product_id']),
                'Quantity' => $l['quantity'],
                'WarehouseCode' => $warehouse,
                'AccountCode' => $account,
                'U_POS_PACK' => $l['pack_id'],
            ], fn ($v) => $v !== null), $lines),
        ];
    }

    /**
     * Posting retur (payload event order.returned): A/R Credit Memo yang merujuk invoice struk asal,
     * lalu Outgoing Payment ke pelanggan untuk uang yang dikembalikan. Bila invoice asal belum
     * terposting, retur menunggu (waiting_dependency) dan dicoba lagi pada putaran berikutnya.
     */
    public function postReturn(array $return): array
    {
        $companyDb = Outlet::sapCompanyDb((int) $return['outlet_id']);
        if (! $companyDb) {
            return ['status' => 'failed', 'missing' => ['company:sap_company_db']];
        }
        $lock = Cache::lock("sap:sales_return:{$return['id']}", 120);
        if (! $lock->get()) {
            return ['status' => 'locked'];
        }

        try {
            $missing = $this->missingMappings($companyDb, ['lines' => $return['lines'], 'payments' => [['method' => $return['refund']['method']]]]);
            if ($missing !== []) {
                IntegrationException::updateOrCreate(
                    ['kind' => 'missing_mapping', 'source_type' => 'sales_return', 'source_id' => $return['id'], 'status' => 'open'],
                    ['message' => "Mapping SAP {$companyDb} belum ada: ".implode(', ', $missing)],
                );

                return ['status' => 'failed', 'missing' => $missing];
            }

            $invoice = SapDocument::where(['company_db' => $companyDb, 'source_type' => 'order', 'source_id' => $return['original_order_id'], 'sap_object' => 'Invoices', 'status' => 'posted'])->first();
            if ($invoice === null) {
                SapDocument::updateOrCreate(
                    ['company_db' => $companyDb, 'source_type' => 'sales_return', 'source_id' => $return['id'], 'sap_object' => 'CreditNotes'],
                    ['status' => 'waiting_dependency', 'last_error' => "Menunggu invoice struk {$return['original_doc_no']} terposting."],
                );

                return ['status' => 'waiting_dependency'];
            }

            $memo = $this->post($companyDb, 'sales_return', $return['id'], 'CreditNotes', $this->creditNotePayload($companyDb, $return, $invoice));
            if ($memo->status !== 'posted') {
                return ['status' => $memo->status];
            }
            $payment = $this->post($companyDb, 'sales_return', $return['id'], 'VendorPayments', $this->refundPayload($companyDb, $return, $memo->doc_entry));

            return ['status' => $payment->status === 'posted' ? 'posted' : 'partially_posted'];
        } finally {
            $lock->release();
        }
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
        if ($doc->request_payload === null) {
            $doc->update(['request_payload' => $payload]); // dokumen yang sebelumnya menunggu dependensi
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

    private function creditNotePayload(string $companyDb, array $return, SapDocument $invoice): array
    {
        $item = fn ($id) => SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'product', 'entity_key' => (string) $id])->first();
        $customer = $invoice->request_payload['CardCode'] ?? 'C-UMUM';

        return [
            'CardCode' => $customer,
            'DocDate' => $return['business_date'],
            'NumAtCard' => $return['doc_no'],
            'Comments' => "Retur struk {$return['original_doc_no']}: {$return['reason']}",
            'DocTotal' => $return['net_amount'],
            'DocumentLines' => array_map(fn ($l) => array_filter([
                'ItemCode' => $item($l['product_id'])->sap_code,
                'Quantity' => $l['quantity'],
                'UnitPrice' => $l['unit_price'],
                'LineTotal' => $l['net_amount'],
                'WarehouseCode' => $invoice->request_payload['DocumentLines'][0]['WarehouseCode'] ?? null,
                'TaxCode' => $item($l['product_id'])->attributes['tax_code'] ?? null,
                // Menyalin dari invoice asal agar SAP menautkan retur ke penjualannya.
                'BaseType' => $l['order_line_no'] !== null ? 13 : null,
                'BaseEntry' => $l['order_line_no'] !== null ? $invoice->doc_entry : null,
                'BaseLine' => $l['order_line_no'] !== null ? $this->invoiceLineIndex($invoice, $l) : null,
                'U_POS_LINE_TYPE' => $l['line_type'],
                'U_POS_PACK' => $l['pack_id'],
                'U_POS_DISPOSITION' => $l['disposition'],
            ], fn ($v) => $v !== null), $return['lines']),
        ];
    }

    /** Posisi baris di invoice SAP (berurutan seperti baris aktif struk). */
    private function invoiceLineIndex(SapDocument $invoice, array $line): ?int
    {
        $order = DB::table('hq_order_lines')->where('hq_order_id', $invoice->source_id)->orderBy('line_no')->pluck('line_no')->values()->all();
        $index = array_search($line['order_line_no'], $order, true);

        return $index === false ? null : $index;
    }

    private function refundPayload(string $companyDb, array $return, int $creditNoteDocEntry): array
    {
        $method = $return['refund']['method'];
        $account = SapMapping::where(['company_db' => $companyDb, 'entity_type' => 'payment_method', 'entity_key' => $method])->value('sap_code');
        $customer = SapDocument::where(['company_db' => $companyDb, 'source_type' => 'sales_return', 'source_id' => $return['id'], 'sap_object' => 'CreditNotes'])->first()?->request_payload['CardCode'] ?? 'C-UMUM';

        return array_filter([
            'DocType' => 'rCustomer',
            'CardCode' => $customer,
            'DocDate' => $return['business_date'],
            'DocTotal' => $return['net_amount'],
            'CashSum' => $method === 'cash' ? $return['net_amount'] : null,
            'CashAccount' => $method === 'cash' ? $account : null,
            'TransferSum' => $method !== 'cash' ? $return['net_amount'] : null,
            'TransferAccount' => $method !== 'cash' ? $account : null,
            'TransferReference' => $return['refund']['ref'] ?? null,
            'PaymentInvoices' => [['DocEntry' => $creditNoteDocEntry, 'InvoiceType' => 'it_CredItnote', 'SumApplied' => $return['net_amount']]],
        ], fn ($v) => $v !== null);
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
