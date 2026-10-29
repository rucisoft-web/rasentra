<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Kitchen\KitchenService;
use App\Models\KitchenTicket;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\TicketItem;
use App\Models\User;
use App\Support\Barcode;

/**
 * Rasentra Kitchen: kiriman delta, permintaan batal dari kasir, tiket cetak dengan barcode,
 * penanda perubahan papan dan layar analisis.
 */
class KitchenFlowTest extends AcceptanceTestCase
{
    private function chef(): User
    {
        return User::where('email', 'chef@uji.test')->firstOrFail();
    }

    private function steak(): Product
    {
        return Product::where('code', '0700701')->firstOrFail();
    }

    public function test_revised_line_sends_only_the_added_quantity(): void
    {
        $order = $this->newOrder('dine_in');
        $line = $this->orders()->addSku($order, $this->steak(), '2');
        $this->assertCount(1, app(KitchenService::class)->send($order));

        // Tambah 1 porsi ke baris yang sama: dapur hanya menerima tambahan 1, bukan 3.
        $this->orders()->addSku($order->fresh(), $this->steak(), '1');
        $tickets = app(KitchenService::class)->send($order->fresh());
        $this->assertCount(1, $tickets);
        $this->assertSame('1.000000', (string) $tickets[0]->items()->first()->quantity);
        $this->assertSame(2, KitchenTicket::where('order_id', $order->id)->count());

        // Ubah harga menaikkan revisi tetapi tidak menambah porsi: tidak ada tiket baru.
        $this->orders()->overridePrice($line->fresh(), '150000', 'Promo pembukaan', $this->cashier, $this->approval);
        $this->assertSame([], app(KitchenService::class)->send($order->fresh()));
    }

    public function test_cashier_void_cancels_queued_items_and_requests_cancel_for_cooking_items(): void
    {
        $order = $this->newOrder('dine_in');
        $queued = $this->orders()->addSku($order, $this->steak(), '1');
        $cooking = $this->orders()->addSku($order->fresh(), Product::where('code', '0700703')->firstOrFail(), '1'); // bar
        app(KitchenService::class)->send($order);
        $barItem = TicketItem::where('order_line_id', $cooking->id)->firstOrFail();
        app(KitchenBoard::class)->bumpItem($barItem, $this->chef()); // mulai dibuat

        // Belum dimasak: langsung batal, tanpa waste.
        $this->orders()->voidLine($queued->fresh(), $this->cashier, 'Salah input', $this->approval);
        $this->assertSame('cancelled', TicketItem::where('order_line_id', $queued->id)->value('status'));

        // Sudah dibuat: menjadi minta batal dan tampil di papan station sampai dikonfirmasi.
        $this->orders()->voidLine($cooking->fresh(), $this->cashier, 'Tamu batal', $this->approval);
        $this->assertSame('cancel_requested', $barItem->fresh()->status);

        $this->actingAs($this->chef());
        $board = $this->getJson('/kitchen/api/board/bar')->assertOk();
        $board->assertJsonPath('tickets.0.items.0.status', 'cancel_requested')->assertJsonPath('tickets.0.items.0.cancel_reason', 'Tamu batal');
        $this->postJson("/kitchen/api/items/{$barItem->id}/bump")->assertStatus(409);

        $before = OutboxEvent::where('event_type', 'stock.adjusted')->count();
        $this->postJson("/kitchen/api/items/{$barItem->id}/confirm-cancel")->assertOk();
        $this->assertSame('cancelled', $barItem->fresh()->status);
        $this->getJson('/kitchen/api/board/bar')->assertJsonCount(0, 'tickets');
        $this->postJson("/kitchen/api/items/{$barItem->id}/confirm-cancel")->assertStatus(409);

        // Es teh tidak punya resep, jadi tidak ada bahan yang dicatat waste.
        $this->assertFalse(StockAdjustment::where('source_type', 'ticket_item')->where('source_id', $barItem->id)->exists());
        $this->assertSame($before, OutboxEvent::where('event_type', 'stock.adjusted')->count());
    }

    public function test_cancel_after_cook_records_waste_document(): void
    {
        $order = $this->newOrder('dine_in');
        $this->orders()->addSku($order, $this->steak(), '1');
        app(KitchenService::class)->send($order);
        $item = TicketItem::firstOrFail();
        app(KitchenBoard::class)->bumpItem($item, $this->chef());

        $this->actingAs($this->chef())->postJson("/kitchen/api/items/{$item->id}/cancel", ['reason' => 'Jatuh'])->assertOk();
        $doc = StockAdjustment::where('source_type', 'ticket_item')->where('source_id', $item->id)->with('lines')->firstOrFail();
        $this->assertSame('waste', $doc->kind);
        $this->assertStringContainsString('Jatuh', $doc->reason);
        $this->assertNotEmpty($doc->lines);
        $this->assertNull($doc->lines->first()->to_state);
    }

    public function test_board_version_changes_on_every_update(): void
    {
        $this->actingAs($this->chef());
        $v0 = $this->getJson('/kitchen/api/version')->assertOk()->json('v');

        $order = $this->newOrder('dine_in');
        $this->orders()->addSku($order, $this->steak(), '1');
        app(KitchenService::class)->send($order);
        $v1 = $this->getJson('/kitchen/api/version')->json('v');
        $this->assertNotSame($v0, $v1);

        $this->travel(2)->seconds();
        app(KitchenBoard::class)->bumpItem(TicketItem::firstOrFail(), $this->chef());
        $this->assertNotSame($v1, $this->getJson('/kitchen/api/version')->json('v'));
    }

    public function test_printed_ticket_carries_scannable_bump_code(): void
    {
        $order = $this->newOrder('takeaway');
        $this->orders()->addSku($order, $this->steak(), '2');
        $ticket = app(KitchenService::class)->send($order)[0];

        $this->actingAs($this->cashier)->get("/kitchen/print/{$order->id}")->assertOk()
            ->assertSee('Grill')->assertSee('No. '.$order->fresh()->queue_no)->assertSee('#'.$ticket->bump_code)->assertSee('<svg', false);

        // Code 128 set C: start C, pasangan digit, checksum mod 103.
        $this->assertSame([105, 12, 34, 56, 78, 47], Barcode::code128Values('12345678'));
        $this->assertSame([104, 33, 75, 1, 84], Barcode::code128Values('Ak!'));

        // Scan kode di tiket cetak memajukan tiket di station.
        $this->actingAs($this->chef())->postJson('/kitchen/api/scan', ['code' => $ticket->bump_code])->assertOk();
        $this->assertSame('in_preparation', TicketItem::firstOrFail()->status);
    }

    public function test_analytics_screen_and_local_day_range(): void
    {
        $this->actingAs($this->chef());
        $this->get('/kitchen')->assertOk()->assertSee('Analisis waktu saji');
        $this->get('/kitchen/analytics')->assertOk()->assertSee('Per menu');
    }
}
