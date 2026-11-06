<?php

namespace Tests\Unit;

use App\Domain\Integration\Accurate\AccurateOnlineClient;
use App\Models\BusinessUnit;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Penerjemahan dokumen posting Rasentra ke API Accurate Online. */
class AccurateOnlineClientTest extends TestCase
{
    private function client(): AccurateOnlineClient
    {
        $bu = new BusinessUnit(['accounting_system' => 'accurate', 'accurate_host' => 'https://zeus.accurate.id']);
        $bu->accurate_api_token = 'tok';
        $bu->accurate_signature_secret = 'sec';

        return new AccurateOnlineClient($bu);
    }

    public function test_invoice_is_posted_as_sales_invoice_with_signed_headers(): void
    {
        Http::fake(['zeus.accurate.id/accurate/api/sales-invoice/save.do' => Http::response(['s' => true, 'r' => ['id' => 501]])]);

        $result = $this->client()->create('Invoices', [
            'CardCode' => 'C-UMUM', 'DocDate' => '2026-11-05', 'NumAtCard' => 'MKS-261105-00001', 'DocTotal' => '90000.00',
            'DocumentLines' => [['ItemCode' => 'SIR-001', 'Quantity' => '0.5', 'UnitPrice' => '200000', 'LineTotal' => '90000.00', 'WarehouseCode' => 'Gudang MKS']],
            'U_POS_SRC' => 'order:abc',
        ]);

        $this->assertSame(501, $result['DocEntry']);
        Http::assertSent(function ($req) {
            $d = $req->data();

            return $req->hasHeader('Authorization', 'Bearer tok')
                && $req->hasHeader('X-Api-Signature', base64_encode(hash_hmac('sha256', $req->header('X-Api-Timestamp')[0], 'sec', true)))
                && $d['customerNo'] === 'C-UMUM' && $d['transDate'] === '05/11/2026'
                && $d['number'] === AccurateOnlineClient::number('Invoices', 'order:abc')
                && $d['detailItem[0].itemNo'] === 'SIR-001' && $d['detailItem[0].itemCashDiscount'] === '10000.00'
                && $d['detailItem[0].warehouseName'] === 'Gudang MKS';
        });
    }

    public function test_existing_document_is_found_by_derived_number(): void
    {
        $number = AccurateOnlineClient::number('IncomingPayments', 'order:abc');
        Http::fake(['zeus.accurate.id/accurate/api/sales-receipt/detail.do*' => Http::response(['s' => true, 'd' => ['id' => 77, 'number' => $number]])]);

        $this->assertSame(77, $this->client()->findBySourceRef('IncomingPayments', 'order:abc')['DocEntry']);
    }

    public function test_receipt_links_invoice_number_and_bank(): void
    {
        Http::fake(['*' => Http::response(['s' => true, 'r' => ['id' => 9]])]);
        $this->client()->create('IncomingPayments', [
            'DocDate' => '2026-11-05', 'DocTotal' => '90000.00', 'CashSum' => '90000.00', 'CashAccount' => '110101',
            'PaymentInvoices' => [['DocEntry' => 501, 'SumApplied' => '90000.00']], 'TransferAccounts' => [], 'U_POS_SRC' => 'order:abc',
        ]);
        Http::assertSent(fn ($req) => $req->data()['bankNo'] === '110101'
            && $req->data()['detailInvoice[0].invoiceNo'] === AccurateOnlineClient::number('Invoices', 'order:abc'));
    }

    public function test_journal_is_posted_as_journal_voucher(): void
    {
        Http::fake(['zeus.accurate.id/accurate/api/journal-voucher/save.do' => Http::response(['s' => true, 'r' => ['id' => 77]])]);

        $this->client()->create('JournalEntries', [
            'ReferenceDate' => '2026-11-05', 'Memo' => 'Ongkir SLO-1', 'DocTotal' => '12000.00', 'U_POS_SRC' => 'delivery:d1',
            'JournalEntryLines' => [
                ['AccountCode' => '6110001', 'Debit' => '12000.00', 'Credit' => '0.00'],
                ['AccountCode' => '1110004', 'Debit' => '0.00', 'Credit' => '12000.00'],
            ],
        ]);

        Http::assertSent(function ($req) {
            $d = $req->data();

            return $d['detailJournalVoucher[0].amountType'] === 'DEBIT' && $d['detailJournalVoucher[1].amountType'] === 'CREDIT'
                && $d['detailJournalVoucher[1].accountNo'] === '1110004' && $d['number'] === AccurateOnlineClient::number('JournalEntries', 'delivery:d1');
        });
    }
}
