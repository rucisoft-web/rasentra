<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Kitchen\KitchenService;
use App\Models\ConsumptionEvent;
use App\Models\KitchenTicket;
use App\Models\Product;
use App\Models\TicketItem;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Rasentra Kitchen: papan station, bump sentuh/scan, timer, expeditor, antrean pickup
 * dan analisis waktu saji.
 */
class KitchenBoardTest extends AcceptanceTestCase
{
    private function chef(): User
    {
        return User::where('email', 'chef@uji.test')->firstOrFail();
    }

    private function product(string $code): Product
    {
        return Product::where('code', $code)->firstOrFail();
    }

    public function test_station_board_bump_flow_and_timer_levels(): void
    {
        Date::setTestNow('2026-10-29 12:00:00');
        $order = $this->newOrder('dine_in');
        $this->orders()->addSku($order, $this->product('0700701'), '2'); // grill
        $this->orders()->addSku($order, $this->product('0700703'), '1'); // bar
        app(KitchenService::class)->send($order);

        $this->actingAs($this->chef());
        $board = $this->getJson('/kitchen/api/board/grill')->assertOk();
        $board->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.level', 'ok')->assertJsonPath('tickets.0.items.0.status', 'acknowledged');
        $this->assertSame(1, TicketItem::where('status', 'acknowledged')->count(), 'Bar belum dibuka, jadi item bar tetap Baru.');

        // Target grill 15 menit: kuning di 75%, merah setelah lewat target.
        Date::setTestNow('2026-10-29 12:12:00');
        $this->getJson('/kitchen/api/board/grill')->assertJsonPath('tickets.0.level', 'warn');
        Date::setTestNow('2026-10-29 12:16:00');
        $this->getJson('/kitchen/api/board/grill')->assertJsonPath('tickets.0.level', 'late');

        $ticketId = $board->json('tickets.0.id');
        $this->postJson("/kitchen/api/tickets/{$ticketId}/bump")->assertOk();
        $this->assertSame('in_preparation', TicketItem::whereHas('ticket', fn ($q) => $q->where('station', 'grill'))->value('status'));
        Date::setTestNow('2026-10-29 12:18:00');
        $this->postJson("/kitchen/api/tickets/{$ticketId}/bump")->assertOk();

        // Tiket siap hilang dari papan station; station tidak bisa menandai Disajikan.
        $this->getJson('/kitchen/api/board/grill')->assertJsonCount(0, 'tickets');
        $this->postJson("/kitchen/api/tickets/{$ticketId}/bump")->assertStatus(409)->assertJsonPath('error', 'ticket_done');

        // Expeditor: pesanan belum lengkap karena bar belum siap.
        $expo = $this->getJson('/kitchen/api/board/expo')->assertOk();
        $expo->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.complete', false);

        $bar = KitchenTicket::where('station', 'bar')->firstOrFail();
        $this->postJson('/kitchen/api/scan', ['code' => $bar->bump_code])->assertOk()->assertJsonPath('station', 'bar');
        $this->postJson('/kitchen/api/scan', ['code' => $bar->bump_code])->assertOk();
        $this->getJson('/kitchen/api/board/expo')->assertJsonPath('tickets.0.complete', true);

        foreach (TicketItem::all() as $item) {
            $this->postJson("/kitchen/api/items/{$item->id}/bump", ['expo' => true])->assertOk()->assertJsonPath('status', 'served');
        }
        $this->getJson('/kitchen/api/board/expo')->assertJsonCount(0, 'tickets');
        $this->postJson('/kitchen/api/scan', ['code' => '00000000'])->assertStatus(404)->assertJsonPath('error', 'ticket_unknown');
    }

    public function test_pickup_queue_number_and_auto_status(): void
    {
        $order = $this->newOrder('takeaway');
        $this->orders()->addSku($order, $this->product('0700702'), '1'); // hot
        $this->orders()->addSku($order, $this->product('0700703'), '1'); // bar
        app(KitchenService::class)->send($order);
        $order->refresh();
        $this->assertSame(1, $order->queue_no);

        $next = $this->newOrder('takeaway');
        $this->orders()->addSku($next, $this->product('0700703'), '1');
        app(KitchenService::class)->send($next);
        $this->assertSame(2, $next->fresh()->queue_no);

        $this->actingAs($this->chef());
        $this->getJson('/kitchen/api/queue')->assertOk()
            ->assertJsonPath('preparing.0.no', 1)->assertJsonPath('preparing.1.no', 2)->assertJsonCount(0, 'ready');

        $board = app(KitchenBoard::class);
        foreach (KitchenTicket::where('order_id', $order->id)->get() as $ticket) {
            $board->bumpTicket($ticket, $this->chef());
            $board->bumpTicket($ticket, $this->chef());
        }
        $this->getJson('/kitchen/api/queue')->assertJsonPath('ready.0.no', 1)->assertJsonPath('ready.0.served', false)->assertJsonCount(1, 'preparing');
        $this->get('/kitchen/queue')->assertOk()->assertSee('Silakan ambil');
    }

    public function test_recall_cancel_and_serving_time_analytics(): void
    {
        Date::setTestNow('2026-10-29 11:00:00');
        $order = $this->newOrder('dine_in');
        $this->orders()->addSku($order, $this->product('0700701'), '1');
        app(KitchenService::class)->send($order);
        $item = TicketItem::firstOrFail();
        $board = app(KitchenBoard::class);

        Date::setTestNow('2026-10-29 11:03:00');
        $board->bumpItem($item, $this->chef());
        Date::setTestNow('2026-10-29 11:13:00');
        $board->bumpItem($item->fresh(), $this->chef());
        $this->assertSame('ready', $item->fresh()->status);

        $this->actingAs($this->chef());
        $this->postJson("/kitchen/api/items/{$item->id}/recall")->assertOk();
        $this->assertSame('in_preparation', $item->fresh()->status);
        $board->bumpItem($item->fresh(), $this->chef());

        $stats = $this->getJson('/kitchen/api/analytics?from=2026-10-29&to=2026-10-29')->assertOk();
        $stats->assertJsonPath('overall.count', 1)
            ->assertJsonPath('by_station.0.station', 'grill')
            ->assertJsonPath('by_station.0.avg_seconds', 780)
            ->assertJsonPath('by_station.0.avg_wait_seconds', 180)
            ->assertJsonPath('by_station.0.late', 0)
            ->assertJsonPath('by_hour.0.hour', 18);

        // Batal setelah dimasak tercatat sebagai waste dan wajib alasan.
        $this->postJson("/kitchen/api/items/{$item->id}/cancel")->assertStatus(422);
        $this->postJson("/kitchen/api/items/{$item->id}/cancel", ['reason' => 'Tamu batal'])->assertOk();
        $this->assertSame('cancelled', $item->fresh()->status);
        $this->assertTrue(ConsumptionEvent::where('source_id', $item->id)->where('reason', 'waste')->exists());

        $this->get('/kitchen')->assertOk()->assertSee('Expeditor');
        $this->get('/kitchen/s/grill')->assertOk()->assertSee('Grill');
        $this->get('/kitchen/s/unknown')->assertStatus(404);
    }

    public function test_chef_pin_login_lands_on_kitchen(): void
    {
        $this->post('/pos/login', ['user_id' => $this->chef()->id, 'pin' => '2222'])->assertRedirect('/kitchen');
    }
}
