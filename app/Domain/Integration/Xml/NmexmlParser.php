<?php

namespace App\Domain\Integration\Xml;

use App\Support\DomainException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parser NMEXML untuk MSI (SALESINVOICE) dan MSR (CUSTOMERRECEIPT).
 * Aman: DOCTYPE/entity ditolak, tanpa akses jaringan, ukuran dan jumlah elemen dibatasi.
 * Nilai dibiarkan sebagai string: field hilang (null), kosong ('') dan nol ('0') dibedakan,
 * dan kode barang dengan nol di depan tidak diubah menjadi angka.
 */
final class NmexmlParser
{
    /** @return array{branch_code: ?string, invoices: list<array>, receipts: list<array>, element_count: int} */
    public function parse(string $xml): array
    {
        if (strlen($xml) > config('rasentra.xml.max_bytes', 20 * 1024 * 1024)) {
            throw new DomainException('xml_too_large', 'Ukuran file XML melebihi batas.');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new DomainException('xml_doctype_forbidden', 'XML dengan DOCTYPE/ENTITY ditolak.');
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok || $errors !== []) {
            throw new DomainException('xml_invalid', 'Struktur XML tidak sah.');
        }

        $count = $doc->getElementsByTagName('*')->length;
        if ($count > config('rasentra.xml.max_elements', 500_000)) {
            throw new DomainException('xml_too_many_elements', 'Jumlah elemen XML melebihi batas.');
        }
        if ($doc->documentElement?->nodeName !== 'NMEXML') {
            throw new DomainException('xml_root_invalid', 'Akar dokumen harus NMEXML.');
        }

        $xpath = new DOMXPath($doc);
        $root = $doc->documentElement;
        $branch = $this->attrOrChild($root, 'BranchCode') ?? $this->attrOrChild($root, 'BRANCHCODE');

        $invoices = [];
        foreach ($xpath->query('//SALESINVOICE') as $node) {
            /** @var DOMElement $node */
            $lines = [];
            foreach ($xpath->query('./ITEMLINE', $node) as $line) {
                $lines[] = [
                    'source_line_key' => $this->attrOrChild($line, 'KeyID'),
                    'item_no' => $this->child($line, 'ITEMNO'),
                    'quantity' => $this->child($line, 'QUANTITY'),
                    'unit' => $this->child($line, 'ITEMUNIT'),
                    'unit_ratio' => $this->child($line, 'UNITRATIO'),
                    'unit_price' => $this->child($line, 'UNITPRICE'),
                    'discount_pct' => $this->child($line, 'ITEMDISCPC'),
                    'bruto_unit_price' => $this->child($line, 'BRUTOUNITPRICE'),
                    'dept_id' => $this->child($line, 'DEPTID'),
                    'raw' => $this->flatten($line),
                ];
            }
            $invoices[] = [
                'branch_code' => $this->attrOrChild($node, 'BranchCode') ?? $branch,
                'invoice_no' => $this->child($node, 'INVOICENO'),
                'invoice_date' => $this->child($node, 'INVOICEDATE'),
                'customer_id' => $this->child($node, 'CUSTOMERID'),
                'warehouse_id' => $this->child($node, 'WAREHOUSEID'),
                'cash_discount' => $this->child($node, 'CASHDISCOUNT'),
                'cash_discount_pct' => $this->child($node, 'CASHDISCPC'),
                'invoice_amount' => $this->child($node, 'INVOICEAMOUNT'),
                'tax_codes' => $this->child($node, 'TAXCODES'),
                'ar_account' => $this->child($node, 'ARACCOUNT'),
                'lines' => $lines,
                'raw' => $this->flatten($node, ['ITEMLINE']),
            ];
        }

        $receipts = [];
        foreach ($xpath->query('//CUSTOMERRECEIPT') as $node) {
            $allocations = [];
            foreach ($xpath->query('./InvoiceLine', $node) as $line) {
                $allocations[] = [
                    'invoice_no' => $this->child($line, 'ARINVOICEID'),
                    'amount' => $this->child($line, 'PAYMENTAMOUNT'),
                    'raw' => $this->flatten($line),
                ];
            }
            $receipts[] = [
                'branch_code' => $this->attrOrChild($node, 'BranchCode') ?? $branch,
                'sequence_no' => $this->child($node, 'SEQUENCENO'),
                'payment_date' => $this->child($node, 'PAYMENTDATE'),
                'bill_to_id' => $this->child($node, 'BILLTOID'),
                'bank_account' => $this->child($node, 'BANKACCOUNT'),
                'cheque_amount' => $this->child($node, 'CHEQUEAMOUNT'),
                'currency' => $this->child($node, 'CURRENCYNAME'),
                'rate' => $this->child($node, 'RATE'),
                'void' => $this->child($node, 'VOID'),
                'allocations' => $allocations,
                'raw' => $this->flatten($node, ['InvoiceLine']),
            ];
        }

        return ['branch_code' => $branch, 'invoices' => $invoices, 'receipts' => $receipts, 'element_count' => $count];
    }

    private function child(DOMElement $node, string $name): ?string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === $name) {
                return trim($child->textContent);
            }
        }

        return null;
    }

    private function attrOrChild(DOMElement $node, string $name): ?string
    {
        return $node->hasAttribute($name) ? $node->getAttribute($name) : $this->child($node, $name);
    }

    private function flatten(DOMElement $node, array $skip = []): array
    {
        $out = [];
        foreach ($node->attributes as $attr) {
            $out['@'.$attr->nodeName] = $attr->nodeValue;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && ! in_array($child->nodeName, $skip, true) && $child->childElementCount === 0) {
                $out[$child->nodeName] = $child->textContent;
            }
        }

        return $out;
    }
}
