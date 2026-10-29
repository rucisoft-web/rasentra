<?php

namespace App\Domain\Integration\Xml;

use App\Models\SourceDocument;
use App\Models\XmlImportBatch;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Impor MSI/MSR ke staging. File asli disimpan immutable beserta SHA-256. Keunikan dokumen
 * ditentukan oleh (sistem, cabang, jenis, nomor sumber), bukan nama atau hash file, sehingga
 * file yang sama dengan nama berbeda tidak menghasilkan dokumen ganda.
 */
final class NmexmlImporter
{
    public function __construct(private readonly NmexmlParser $parser) {}

    public function import(string $xml, string $fileName, string $kind): XmlImportBatch
    {
        $sha = hash('sha256', $xml);
        $path = "xml-archive/{$kind}/".now()->format('Y/m/d')."/{$sha}.xml";
        Storage::disk('local')->put($path, $xml);

        $batch = XmlImportBatch::create([
            'kind' => $kind, 'file_name' => $fileName, 'sha256' => $sha, 'storage_path' => $path, 'status' => 'received',
        ]);

        $parsed = $this->parser->parse($xml);
        $summary = ['invoices' => 0, 'lines' => 0, 'fractional_lines' => 0, 'discount_lines' => 0, 'receipts' => 0,
            'invoice_total' => '0', 'payment_total' => '0', 'rounding_differences' => [], 'duplicates' => [], 'waiting_dependency' => []];
        $invoiceTotal = BigDecimal::zero();
        $paymentTotal = BigDecimal::zero();
        $created = 0;
        $dupes = 0;

        DB::transaction(function () use ($parsed, $batch, &$summary, &$invoiceTotal, &$paymentTotal, &$created, &$dupes) {
            foreach ($parsed['invoices'] as $inv) {
                $calc = BigDecimal::zero();
                foreach ($inv['lines'] as $l) {
                    $pct = Decimal::of($l['discount_pct'] ?: '0');
                    // Dasar per baris: qty x harga x (1 - diskon%/100), sebelum diskon header dan pajak.
                    $calc = $calc->plus(Decimal::mul($l['quantity'] ?? '0', $l['unit_price'] ?? '0')
                        ->multipliedBy(BigDecimal::of(100)->minus($pct))->dividedBy(100, 6, Decimal::rounding()));
                    $summary['lines']++;
                    if (! Decimal::of($l['quantity'] ?? '0')->isEqualTo(Decimal::of($l['quantity'] ?? '0')->toScale(0, RoundingMode::Down))) {
                        $summary['fractional_lines']++;
                    }
                    if (($l['discount_pct'] ?? '') !== '' && ! Decimal::of($l['discount_pct'])->isZero()) {
                        $summary['discount_lines']++;
                    }
                }
                $source = Decimal::of($inv['invoice_amount'] ?? '0');
                $diff = $source->minus($calc);
                if (! $diff->isZero()) {
                    $summary['rounding_differences'][] = ['invoice_no' => $inv['invoice_no'], 'source' => (string) $source, 'calculated' => (string) $calc->toScale(4, Decimal::rounding()), 'difference' => (string) $diff->toScale(4, Decimal::rounding())];
                }

                $doc = $this->stage($batch, 'SALESINVOICE', $inv['branch_code'], $inv['invoice_no'], [
                    'business_date' => $this->date($inv['invoice_date']),
                    'legacy_customer_code' => $inv['customer_id'],
                    'legacy_warehouse_code' => $inv['warehouse_id'],
                    'document_total_source' => (string) $source,
                    'document_total_calculated' => (string) $calc->toScale(4, Decimal::rounding()),
                    'rounding_difference' => (string) $diff->toScale(4, Decimal::rounding()),
                    'header' => $inv['raw'],
                ]);
                if ($doc === null) {
                    $dupes++;
                    $summary['duplicates'][] = $inv['invoice_no'];

                    continue;
                }
                foreach ($inv['lines'] as $l) {
                    $doc->lines()->create([
                        'source_line_key' => $l['source_line_key'],
                        'legacy_item_code' => $l['item_no'],
                        'quantity' => $l['quantity'] === null || $l['quantity'] === '' ? null : Decimal::qty($l['quantity']),
                        'uom' => $l['unit'],
                        'unit_price' => $l['unit_price'] === null || $l['unit_price'] === '' ? null : (string) Decimal::of($l['unit_price'])->toScale(4, Decimal::rounding()),
                        'line_discount_pct' => $l['discount_pct'] === null || $l['discount_pct'] === '' ? null : $l['discount_pct'],
                        'raw' => $l['raw'],
                    ]);
                }
                $created++;
                $summary['invoices']++;
                $invoiceTotal = $invoiceTotal->plus($source);
            }

            foreach ($parsed['receipts'] as $rcp) {
                $amount = Decimal::sum(array_map(fn ($a) => $a['amount'] ?? '0', $rcp['allocations']));
                $waiting = collect($rcp['allocations'])->contains(fn ($a) => ! SourceDocument::where('doc_type', 'SALESINVOICE')
                    ->where('branch_code', $rcp['branch_code'] ?? '')->where('source_no', $a['invoice_no'])->exists());

                $doc = $this->stage($batch, 'CUSTOMERRECEIPT', $rcp['branch_code'], $rcp['sequence_no'], [
                    'business_date' => $this->date($rcp['payment_date']),
                    'legacy_customer_code' => $rcp['bill_to_id'],
                    'document_total_source' => $rcp['cheque_amount'] === null || $rcp['cheque_amount'] === '' ? null : (string) Decimal::of($rcp['cheque_amount']),
                    'document_total_calculated' => (string) $amount,
                    'rounding_difference' => (string) Decimal::of($rcp['cheque_amount'] ?: '0')->minus($amount),
                    'header' => $rcp['raw'],
                    'status' => $waiting ? 'waiting_dependency' : 'staged',
                ]);
                if ($doc === null) {
                    $dupes++;
                    $summary['duplicates'][] = $rcp['sequence_no'];

                    continue;
                }
                foreach ($rcp['allocations'] as $a) {
                    $doc->lines()->create(['ref_invoice_no' => $a['invoice_no'], 'amount' => $a['amount'] === null || $a['amount'] === '' ? null : Decimal::money($a['amount']), 'raw' => $a['raw']]);
                }
                if ($waiting) {
                    $summary['waiting_dependency'][] = $rcp['sequence_no'];
                }
                $created++;
                $summary['receipts']++;
                $paymentTotal = $paymentTotal->plus($amount);
            }
        });

        $summary['invoice_total'] = Decimal::money($invoiceTotal);
        $summary['payment_total'] = Decimal::money($paymentTotal);

        $batch->update([
            'document_count' => $created,
            'duplicate_count' => $dupes,
            'status' => 'staged',
            'summary' => $summary,
        ]);

        // Pembayaran yang menunggu invoice dari file sebelumnya diperiksa ulang.
        if ($kind === 'MSI') {
            $this->releaseWaitingReceipts();
        }

        return $batch->fresh();
    }

    public function releaseWaitingReceipts(): int
    {
        $released = 0;
        foreach (SourceDocument::where('doc_type', 'CUSTOMERRECEIPT')->where('status', 'waiting_dependency')->with('lines')->get() as $rcp) {
            $ready = $rcp->lines->every(fn ($l) => SourceDocument::where('doc_type', 'SALESINVOICE')
                ->where('branch_code', $rcp->branch_code)->where('source_no', $l->ref_invoice_no)->exists());
            if ($ready) {
                $rcp->update(['status' => 'staged']);
                $released++;
            }
        }

        return $released;
    }

    private function stage(XmlImportBatch $batch, string $type, ?string $branch, ?string $no, array $attributes): ?SourceDocument
    {
        $branch = (string) $branch;
        $no = (string) $no;
        if (SourceDocument::where(['system' => 'NMEXML', 'branch_code' => $branch, 'doc_type' => $type, 'source_no' => $no])->exists()) {
            return null;
        }

        return SourceDocument::create($attributes + [
            'id' => (string) Str::uuid7(),
            'xml_import_batch_id' => $batch->id,
            'system' => 'NMEXML',
            'branch_code' => $branch,
            'doc_type' => $type,
            'source_no' => $no,
        ]);
    }

    private function date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $head = substr($value, 0, 10);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $d = \DateTime::createFromFormat('!'.$format, $head);
            if ($d !== false && $d->format($format) === $head) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }
}
