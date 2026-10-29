<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Sales\PaymentService;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\TicketItem;
use App\Models\User;
use App\Models\Visit;

/**
 * Rasentra Mobile Terminal: peta meja, kunjungan bertoken, menu dengan modifier, kirim dapur
 * per gelombang, void dengan PIN, antar hidangan siap, minta bayar lalu dibayar di kasir.
 */
class MobileTerminalTest extends AcceptanceTestCase
{
    private function waiter(): User
    {
        return User::where('email', 'pelayan@uji.test')->firstOrFail();
    }

    private function table(string $code): DiningTable
    {
        return DiningTable::where('outlet_id', $this->outlet->id)->where('code', $code)->firstOrFail();
    }

    private function product(string $code): Product
    {
        return Product::where('code', $code)->firstOrFail();
    }

    private function openTable(string $code, int $guests = 2): string
    {
        return $this->postJson("/mobile/api/tables/{$this->table($code)->id}/open", ['guests' => $guests])->assertCreated()->json('visit.token');
    }

    public function test_waiter_lands_on_mobile_terminal_and_opens_a_table_once(): void
    {
        $this->post('/pos/login', ['user_id' => $this->waiter()->id, 'pin' => '3333'])->assertRedirect('/mobile');
        $this->get('/mobile')->assertOk()->assertSee('Rasentra Mobile Terminal');
        $this->getJson('/mobile/api/bootstrap')->assertOk()->assertJsonPath('user.role', 'waiter');

        $map = collect($this->getJson('/mobile/api/tables')->assertOk()->json('tables'));
        $this->assertSame('empty', $map->firstWhere('code', 'A1')['status']);

        $token = $this->openTable('A1', 4);
        $this->postJson("/mobile/api/tables/{$this->table('A1')->id}/open", ['guests' => 2])->assertStatus(409)->assertJsonPath('error', 'table_occupied');

        $a1 = collect($this->getJson('/mobile/api/tables')->json('tables'))->firstWhere('code', 'A1');
        $this->assertSame('occupied', $a1['status']);
        $this->assertSame(4, $a1['visit']['guests']);
        $this->assertSame($token, $a1['visit']['token']);
        $this->assertNull(Order::where('visit_id', Visit::where('visit_token', $token)->value('id'))->value('shift_id'));
    }

    public function test_menu_modifiers_courses_and_kitchen_waves(): void
    {
        config(['rasentra.menu_modifiers.Menu Steak' => [
            ['group' => 'doneness', 'label' => 'Kematangan', 'required' => true, 'options' => [['medium', 'Medium'], ['well_done', 'Well done']]],
        ]]);
        $this->actingAs($this->waiter());
        $token = $this->openTable('A2');
        $steak = $this->product('0700701');
        $tea = $this->product('0700703');

        $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $steak->id])->assertStatus(422)->assertJsonPath('error', 'modifier_required');
        $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $steak->id, 'modifiers' => ['medium', 'extra_cheese']])
            ->assertStatus(422)->assertJsonPath('error', 'modifier_unknown');

        $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $tea->id, 'quantity' => 2, 'course' => 'starter', 'note' => 'Tanpa es'])->assertOk();
        $res = $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $steak->id, 'quantity' => 2, 'modifiers' => ['medium'], 'course' => 'main'])->assertOk();
        $res->assertJsonCount(2, 'lines')->assertJsonPath('lines.1.modifiers.0', 'Medium')->assertJsonPath('lines.1.status', 'unsent');

        // Gelombang pertama: hanya pembuka.
        $this->postJson("/mobile/api/visits/{$token}/send", ['course' => 'starter'])->assertOk()->assertJsonPath('tickets', 1);
        $this->assertSame(['bar'], KitchenTicket::pluck('station')->all());
        $this->assertSame('Meja A2', app(KitchenBoard::class)->board($this->outlet->id, 'bar')['tickets'][0]['label']);

        // Gelombang berikutnya: sisanya.
        $detail = $this->postJson("/mobile/api/visits/{$token}/send")->assertOk()->assertJsonPath('tickets', 1);
        $this->assertSame(['queued', 'queued'], collect($detail->json('lines'))->pluck('status')->all());
        $this->assertSame(['Medium'], collect(TicketItem::whereHas('ticket', fn ($q) => $q->where('station', 'grill'))->first()->instructions['modifiers'])->pluck('label')->all());
    }

    public function test_void_needs_pin_only_after_sending_and_ready_items_are_served(): void
    {
        $this->actingAs($this->waiter());
        $token = $this->openTable('B1');
        $tea = $this->product('0700703');
        $steak = $this->product('0700701');
        $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $tea->id])->assertOk();
        $this->postJson("/mobile/api/visits/{$token}/send")->assertOk();
        $lines = $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $steak->id])->json('lines');
        [$sentTea, $unsentSteak] = [$lines[0]['id'], $lines[1]['id']];

        // Belum dikirim: hapus bebas. Sudah dikirim: butuh PIN kepala outlet.
        $this->postJson("/mobile/api/visits/{$token}/lines/{$unsentSteak}/void", ['reason' => 'Salah input'])->assertOk()->assertJsonCount(1, 'lines');
        $this->postJson("/mobile/api/visits/{$token}/lines/{$sentTea}/void", ['reason' => 'Tamu batal'])->assertStatus(403)->assertJsonPath('error', 'approval_required');

        // Antar: tidak ada yang siap, lalu dapur menyelesaikan teh.
        $this->postJson("/mobile/api/visits/{$token}/serve")->assertStatus(409)->assertJsonPath('error', 'nothing_ready');
        $item = TicketItem::firstOrFail();
        $board = app(KitchenBoard::class);
        $board->bumpItem($item, $this->head);
        $board->bumpItem($item->fresh(), $this->head);
        $b1 = collect($this->getJson('/mobile/api/tables')->json('tables'))->firstWhere('code', 'B1');
        $this->assertSame(1, $b1['visit']['ready']);
        $this->getJson("/mobile/api/visits/{$token}")->assertJsonPath('lines.0.status', 'ready');
        $this->postJson("/mobile/api/visits/{$token}/serve")->assertOk()->assertJsonPath('served', 1)->assertJsonPath('lines.0.status', 'served');

        // Pesanan lain di outlet tidak bisa diubah lewat token meja ini.
        $other = $this->newOrder('dine_in');
        $line = $this->orders()->addSku($other, $tea);
        $this->postJson("/mobile/api/visits/{$token}/lines/{$line->id}/void")->assertStatus(404);
    }

    public function test_bill_request_cashier_payment_closes_visit_and_token(): void
    {
        $this->actingAs($this->waiter());
        $token = $this->openTable('VIP', 6);
        $this->postJson("/mobile/api/visits/{$token}/bill")->assertStatus(409)->assertJsonPath('error', 'order_empty');
        $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $this->product('0700703')->id, 'quantity' => 3])->assertOk();
        $this->postJson("/mobile/api/visits/{$token}/send")->assertOk();
        $this->postJson("/mobile/api/visits/{$token}/bill")->assertOk()->assertJsonPath('visit.bill_requested', true);
        $this->assertSame('billing', collect($this->getJson('/mobile/api/tables')->json('tables'))->firstWhere('code', 'VIP')['status']);

        // Kasir melihat meja yang minta bayar di atas, mengambilnya ke shift-nya, lalu membayar.
        $this->newOrder('retail'); // memastikan shift kasir terbuka
        $this->actingAs($this->cashier);
        $list = $this->getJson('/pos/api/tables')->assertOk();
        $list->assertJsonPath('0.code', 'VIP')->assertJsonPath('0.status', 'billing');
        $orderId = $list->json('0.order_id');
        $this->postJson("/pos/api/orders/{$orderId}/take")->assertOk()->assertJsonPath('order.table', 'VIP');
        $order = Order::findOrFail($orderId);
        $this->assertSame(Shift::where('user_id', $this->cashier->id)->where('status', 'open')->value('id'), $order->shift_id);
        app(PaymentService::class)->add($order, 'cash', (string) $order->net_amount, ['tendered' => (string) $order->net_amount]);
        $this->orders()->finalize($order->fresh(), $this->cashier);

        $this->assertSame('closed', Visit::where('visit_token', $token)->value('status'));
        $this->actingAs($this->waiter());
        $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $this->product('0700703')->id])->assertStatus(409)->assertJsonPath('error', 'visit_closed');
        $this->assertSame('empty', collect($this->getJson('/mobile/api/tables')->json('tables'))->firstWhere('code', 'VIP')['status']);
        $this->assertNotSame($token, $this->openTable('VIP'));
    }

    public function test_move_table_and_close_empty_visit(): void
    {
        $this->actingAs($this->waiter());
        $token = $this->openTable('A3');
        $this->openTable('B2');
        $this->postJson("/mobile/api/visits/{$token}/move", ['table_id' => $this->table('B2')->id])->assertStatus(409)->assertJsonPath('error', 'table_occupied');
        $this->postJson("/mobile/api/visits/{$token}/move", ['table_id' => $this->table('A1')->id])->assertOk()->assertJsonPath('visit.table.code', 'A1');
        $this->postJson("/mobile/api/visits/{$token}/guests", ['guests' => 5])->assertOk()->assertJsonPath('visit.guests', 5);

        $this->postJson("/mobile/api/visits/{$token}/items", ['product_id' => $this->product('0700703')->id])->assertOk();
        $this->postJson("/mobile/api/visits/{$token}/close")->assertStatus(409)->assertJsonPath('error', 'order_not_empty');
        $lineId = $this->getJson("/mobile/api/visits/{$token}")->json('lines.0.id');
        $this->postJson("/mobile/api/visits/{$token}/lines/{$lineId}/void")->assertOk();
        $this->postJson("/mobile/api/visits/{$token}/close")->assertOk();
        $this->assertSame('empty', collect($this->getJson('/mobile/api/tables')->json('tables'))->firstWhere('code', 'A1')['status']);
    }
}
