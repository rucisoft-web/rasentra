<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Cash\ShiftService;
use App\Domain\Core\SalesReport;
use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Sales\ReturnService;
use App\Domain\Sync\DayCloser;
use App\Domain\Sync\InboxReceiver;
use App\Models\DailyReconciliation;
use App\Models\InboxEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\SapDocument;
use App\Models\SapMapping;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Support\Decimal;

/** Rasentra POS: retur penjualan dari struk final sampai laporan Central dan SAP. */
class ReturnTest extends AcceptanceTestCase
{
    private function sale(): Order
    {
        $order = $this->newOrder();
        $this->orders()->addPack($order, 'PK-000184', $this->cashier); // Sirloin 1,500 kg
        $this->orders()->addSku($order, Product::where('barcode', '8991234500011')->first(), '3');

        return $this->payAndFinalize($order);
    }

    private function line(Order $order, bool $pack): OrderLine
    {
        return $order->lines()->where('status', 'active')->when($pack, fn ($q) => $q->whereNotNull('pack_id'), fn ($q) => $q->whereNull('pack_id'))->firstOrFail();
    }

    private function returns(): ReturnService
    {
        return app(ReturnService::class);
    }

    public function test_return_needs_head_pin_quarantines_pack_restocks_sku_and_reduces_drawer(): void
    {
        $order = $this->sale();
        $pack = $this->line($order, true);
        $sku = $this->line($order, false);
        $shift = Shift::where('user_id', $this->cashier->id)->where('status', 'open')->firstOrFail();
        $cashBefore = app(ShiftService::class)->expected($shift)['cash'];
        $items = [['order_line_id' => $pack->id, 'quantity' => '0.1'], ['order_line_id' => $sku->id, 'quantity' => '1']];

        $this->assertDomainError('approval_required', fn () => $this->returns()->create($order, $this->cashier, $items, 'Daging berbau', ['method' => 'cash'], null));
        $this->assertDomainError('refund_method', fn () => $this->returns()->create($order, $this->cashier, $items, 'Daging berbau', ['method' => 'qris'], $this->approval));
        $this->assertDomainError('reason_required', fn () => $this->returns()->create($order, $this->cashier, $items, ' ', ['method' => 'cash'], $this->approval));

        $return = $this->returns()->create($order, $this->cashier, $items, 'Daging berbau', ['method' => 'cash'], $this->approval);

        // Pack ID selalu diretur utuh (1,500 kg), satu dari tiga sayap ayam kembali ke stok.
        $this->assertMatchesRegularExpression('/^UJI-R\d{6}-0001$/', $return->doc_no);
        $this->assertSame('1.500000', (string) $return->lines[0]->quantity);
        $this->assertSame(['quarantine', 'restock'], $return->lines->pluck('disposition')->all());
        $expected = Decimal::money(Decimal::of($pack->net_amount)->plus(Decimal::of($sku->net_amount)->dividedBy(3, 2, Decimal::rounding())));
        $this->assertSame($expected, (string) $return->net_amount);
        $this->assertSame('quarantine', $this->pack('PK-000184')->status);
        $this->assertSame(['quarantine', 'available'], StockMovement::where('source_type', 'sales_return')->orderBy('source_line_no')->pluck('stock_state')->all());
        $this->assertSame('1.000000', (string) $sku->fresh()->returned_quantity);
        $this->assertSame((string) $this->head->id, (string) $return->approved_by);

        // Uang tunai keluar dari laci kasir.
        $this->assertSame(Decimal::money(Decimal::of($cashBefore)->minus(Decimal::of($return->net_amount))), app(ShiftService::class)->expected($shift)['cash']);
        $this->assertSame(1, OutboxEvent::where('event_type', 'order.returned')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'order.returned', 'approver_id' => $this->head->id]);

        // Sisa: dua sayap ayam. Pack yang sudah diretur tidak bisa diretur lagi.
        $this->assertDomainError('return_quantity', fn () => $this->returns()->create($order, $this->cashier, [['order_line_id' => $pack->id, 'quantity' => '1']], 'x', ['method' => 'cash'], $this->approval));
        $this->assertDomainError('return_quantity', fn () => $this->returns()->create($order, $this->cashier, [['order_line_id' => $sku->id, 'quantity' => '3']], 'x', ['method' => 'cash'], $this->approval));
        $second = $this->returns()->create($order, $this->cashier, [['order_line_id' => $sku->id, 'quantity' => '2']], 'Salah beli', ['method' => 'cash'], $this->approval);
        $this->assertSame((string) $order->net_amount, Decimal::money(Decimal::of($return->net_amount)->plus(Decimal::of($second->net_amount))), 'Retur penuh mengembalikan tepat nilai struk');
        $this->assertSame('0.00', $this->returns()->refundable($order)['total']);
    }

    public function test_return_reaches_central_reports_reconciliation_and_sap_credit_memo(): void
    {
        $order = $this->sale();
        $return = $this->returns()->create($order, $this->cashier, [['order_line_id' => $this->line($order, false)->id, 'quantity' => '1']], 'Kemasan sobek', ['method' => 'cash'], $this->approval);
        app(DayCloser::class)->close($this->outlet, $order->business_date->toDateString());

        // Kirim semua outbox ke Central (satu basis data pada uji ini).
        $wire = fn (OutboxEvent $e) => $e->only(InboxReceiver::REQUIRED);
        $events = OutboxEvent::orderBy('id')->get();
        app(InboxReceiver::class)->receive($events->map($wire)->all(), $events->first()->source_id);

        $this->assertDatabaseHas('hq_orders', ['id' => $return->id, 'kind' => 'return', 'original_order_id' => $order->id, 'net_amount' => '-'.$return->net_amount]);
        $totals = SalesReport::range($order->business_date->toDateString(), $order->business_date->toDateString())->totals();
        $this->assertSame(1, $totals->orders, 'Retur tidak dihitung sebagai struk');
        $this->assertSame(1, $totals->returns);
        $this->assertSame(Decimal::money(Decimal::of($order->net_amount)->minus(Decimal::of($return->net_amount))), Decimal::money((string) $totals->net));

        $rec = DailyReconciliation::where('outlet_id', $this->outlet->id)->firstOrFail();
        $this->assertSame('matched', $rec->status);
        $this->assertSame([$return->doc_no], $rec->local_summary['return_doc_nos']);

        // SAP: retur menunggu invoice struk asal, lalu menjadi A/R Credit Memo + Outgoing Payment.
        foreach (Product::all() as $p) {
            SapMapping::create(['company_db' => 'sap_solo', 'entity_type' => 'product', 'entity_key' => (string) $p->id, 'sap_code' => 'IT'.$p->code]);
        }
        SapMapping::create(['company_db' => 'sap_solo', 'entity_type' => 'payment_method', 'entity_key' => 'cash', 'sap_code' => '1110004']);
        $fake = new FakeSapClient;
        $this->app->instance(SapClient::class, $fake);
        $sap = app(SapPostingService::class);

        $returnEvent = InboxEvent::where('event_type', 'order.returned')->firstOrFail();
        $this->assertSame('waiting_dependency', $sap->postEvent($returnEvent)['status']);
        $this->assertSame('waiting_dependency', SapDocument::where('source_type', 'sales_return')->value('status'));

        $this->assertSame(['posted' => 2], $sap->postInbox());
        $memo = SapDocument::where(['source_type' => 'sales_return', 'sap_object' => 'CreditNotes'])->firstOrFail();
        $invoice = SapDocument::where(['source_type' => 'order', 'sap_object' => 'Invoices'])->firstOrFail();
        $this->assertSame('posted', $memo->status);
        $this->assertSame($invoice->doc_entry, $memo->request_payload['DocumentLines'][0]['BaseEntry']);
        $this->assertSame(1, $memo->request_payload['DocumentLines'][0]['BaseLine'], 'Baris sayap ayam adalah baris kedua invoice');
        $refund = SapDocument::where(['source_type' => 'sales_return', 'sap_object' => 'VendorPayments'])->firstOrFail();
        $this->assertSame(['rCustomer', 'it_CredItnote'], [$refund->request_payload['DocType'], $refund->request_payload['PaymentInvoices'][0]['InvoiceType']]);
        $this->assertSame(0, InboxEvent::where('status', 'accepted')->whereIn('event_type', ['order.finalized', 'order.returned'])->count());
    }

    public function test_pos_history_detail_return_and_receipt_reprint_over_http(): void
    {
        $order = $this->sale();
        $this->actingAs($this->cashier);

        $this->getJson('/pos/api/history')->assertOk()->assertJsonPath('orders.0.doc_no', $order->doc_no);
        $this->getJson('/pos/api/history?q='.substr($order->doc_no, -4))->assertOk()->assertJsonCount(1, 'orders');
        $detail = $this->getJson("/pos/api/orders/{$order->id}/detail")->assertOk()->assertJsonPath('can_return', true)->json();
        $sku = collect($detail['lines'])->firstWhere('pack_code', null);
        $this->assertSame('3.000000', $sku['returnable_quantity']);

        // Struk pertama asli, berikutnya SALINAN dan tercatat di audit.
        $this->get("/pos/receipt/{$order->id}?auto=1")->assertOk()->assertSee($order->doc_no)->assertDontSee('SALINAN')->assertSee('window.print', false);
        $this->get("/pos/receipt/{$order->id}")->assertOk()->assertSee('SALINAN ke-1');
        $this->assertSame(2, $order->fresh()->print_count);
        $this->assertDatabaseHas('audit_events', ['action' => 'receipt.reprinted', 'actor_id' => $this->cashier->id]);

        $body = ['items' => [['order_line_id' => $sku['id'], 'quantity' => 1]], 'reason' => 'Rusak', 'refund' => ['method' => 'cash']];
        $this->postJson("/pos/api/orders/{$order->id}/returns", $body)->assertStatus(403)->assertJsonPath('error', 'approval_required');
        $res = $this->postJson("/pos/api/orders/{$order->id}/returns", $body + ['approval' => $this->approval])->assertCreated();
        $return = SalesReturn::findOrFail($res->json('return.id'));
        $this->get($res->json('receipt_url'))->assertOk()->assertSee('RETUR PENJUALAN')->assertSee($return->doc_no)->assertSee($this->head->name);
        $this->getJson("/pos/api/orders/{$order->id}/detail")->assertJsonPath('returns.0.doc_no', $return->doc_no);
        $this->get("/pos/receipt/{$order->id}")->assertSee('Retur '.$return->doc_no);

        // Laporan tutup shift memuat retur dan bisa dicetak.
        $shift = Shift::where('user_id', $this->cashier->id)->where('status', 'open')->firstOrFail();
        $closed = $this->postJson('/pos/api/shift/close', ['counts' => ['cash' => app(ShiftService::class)->expected($shift)['cash']]])->assertOk();
        $this->get($closed->json('report_url'))->assertOk()->assertSee('LAPORAN TUTUP SHIFT')->assertSee($return->doc_no);
    }

    public function test_pickup_and_delivery_need_customer_details(): void
    {
        $this->actingAs($this->cashier);
        app(ShiftService::class)->open($this->cashier, $this->outlet->id, '0');
        $id = $this->postJson('/pos/api/orders', ['channel' => 'delivery'])->assertCreated()->json('order.id');
        $this->postJson("/pos/api/orders/{$id}/scan", ['code' => '8991234500011'])->assertOk();
        $order = Order::findOrFail($id);
        $this->postJson("/pos/api/orders/{$id}/payments", ['method' => 'cash', 'amount' => (string) $order->net_amount, 'tendered' => (string) $order->net_amount])->assertOk();

        $this->postJson("/pos/api/orders/{$id}/finalize")->assertStatus(422)->assertJsonPath('error', 'customer_required');
        $this->postJson("/pos/api/orders/{$id}/customer", ['customer_name' => 'Bu Rina', 'customer_phone' => '0812'])->assertStatus(422);
        $this->postJson("/pos/api/orders/{$id}/customer", ['customer_name' => 'Bu Rina', 'customer_phone' => '0812', 'delivery_address' => 'Jl. Slamet Riyadi 10'])
            ->assertOk()->assertJsonPath('order.customer_name', 'Bu Rina');
        $res = $this->postJson("/pos/api/orders/{$id}/finalize")->assertOk();
        $this->get($res->json('receipt_url'))->assertSee('Bu Rina')->assertSee('Jl. Slamet Riyadi 10');
        $this->assertSame(['name' => 'Bu Rina', 'phone' => '0812'], OutboxEvent::where('entity_id', $id)->firstOrFail()->payload['customer']);
    }
}
