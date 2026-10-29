<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Integration\Xml\NmexmlImporter;
use App\Models\SourceDocument;
use App\Models\SourceDocumentLine;
use Illuminate\Support\Facades\Storage;

/**
 * Sampel di sini adalah tiruan berstruktur NMEXML dengan angka dari spesifikasi
 * (bukan file MSI/MSR asli 28 Oktober 2026). Uji U32 penuh dijalankan dengan file asli.
 */
class XmlImportTest extends AcceptanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function importer(): NmexmlImporter
    {
        return app(NmexmlImporter::class);
    }

    /** U33 dan U36: selisih pembulatan terdeteksi, kode dengan nol depan utuh, satuan kosong tersimpan kosong. */
    public function test_u33_u36_rounding_and_leading_zero(): void
    {
        $batch = $this->importer()->import(file_get_contents(base_path('tests/Fixtures/msi_sample.xml')), 'MSI 2026-10-28.xml', 'MSI');

        $this->assertSame(2, $batch->summary['invoices']);
        $this->assertSame(3, $batch->summary['lines']);
        $this->assertSame(3, $batch->summary['fractional_lines']);
        $this->assertSame('2737645.00', $batch->summary['invoice_total']);
        $this->assertCount(1, $batch->summary['rounding_differences']);
        $this->assertSame('M26000010702', $batch->summary['rounding_differences'][0]['invoice_no']);
        $this->assertSame('-0.4000', $batch->summary['rounding_differences'][0]['difference']);

        $this->assertSame('0100101', SourceDocumentLine::first()->legacy_item_code);
        $this->assertSame('', SourceDocumentLine::first()->uom);
        $this->assertSame('749280972', SourceDocument::first()->branch_code);
    }

    /** U34: file sama dengan nama berbeda tidak menggandakan dokumen. */
    public function test_u34_same_documents_different_file_name(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/msi_sample.xml'));
        $this->importer()->import($xml, 'MSI 2026-10-28.xml', 'MSI');
        $again = $this->importer()->import($xml, 'MSI-ulang (1).xml', 'MSI');

        $this->assertSame(0, $again->document_count);
        $this->assertSame(2, $again->duplicate_count);
        $this->assertSame(2, SourceDocument::where('doc_type', 'SALESINVOICE')->count());
    }

    /** U35: MSR datang sebelum MSI menunggu dependensi, lalu dilepas setelah MSI tiba. */
    public function test_u35_receipt_before_invoice_waits(): void
    {
        $msr = $this->importer()->import(file_get_contents(base_path('tests/Fixtures/msr_sample.xml')), 'MSR.xml', 'MSR');
        $this->assertSame(['R-0001', 'R-0002'], $msr->summary['waiting_dependency']);
        $this->assertSame('2737645.00', $msr->summary['payment_total']);

        $this->importer()->import(file_get_contents(base_path('tests/Fixtures/msi_sample.xml')), 'MSI.xml', 'MSI');
        $this->assertSame(0, SourceDocument::where('status', 'waiting_dependency')->count());
    }

    /** U38: XML berbahaya (XXE) ditolak tanpa memproses isinya. */
    public function test_u38_xxe_is_rejected(): void
    {
        $evil = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><NMEXML><SALESINVOICE><INVOICENO>&e;</INVOICENO></SALESINVOICE></NMEXML>';
        $this->assertDomainError('xml_doctype_forbidden', fn () => $this->importer()->import($evil, 'evil.xml', 'MSI'));
        $this->assertDomainError('xml_invalid', fn () => $this->importer()->import('<NMEXML><SALESINVOICE>', 'rusak.xml', 'MSI'));
        $this->assertSame(0, SourceDocument::count());
    }
}
