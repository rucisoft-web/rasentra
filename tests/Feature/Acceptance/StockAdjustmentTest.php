<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Sync\InboxReceiver;
use App\Models\InboxEvent;
use App\Models\OutboxEvent;
use App\Models\Pack;
use App\Models\Product;
use App\Models\SapDocument;
use App\Models\SapMapping;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Rasentra Inventory: karantina, waste, pack hampir kedaluwarsa dan dokumen penyesuaian stok sampai SAP. */
class StockAdjustmentTest extends AcceptanceTestCase
{
    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function adjust(): StockAdjustmentService
    {
        return app(StockAdjustmentService::class);
    }

    public function test_quarantined_pack_is_released_by_head_and_can_be_sold_again(): void
    {
        $gudang = $this->user('gudang@uji.test');
        $this->actingAs($gudang);
        $this->postJson('/inventory/api/receiving', ['code' => 'PK-000511', 'action' => 'reject', 'reason' => 'Suhu tinggi'])->assertOk();
        $pack = $this->pack('PK-000511');

        $q = $this->getJson('/inventory/api/quarantine')->assertOk();
        $this->assertSame(['PK-000511'], array_column($q->json('packs'), 'pack_code'));
        $this->assertSame('Suhu tinggi', $q->json('packs.0.reason'));

        // Petugas gudang butuh PIN kepala outlet; tanpa alasan ditolak.
        $body = ['action' => 'release', 'code' => 'PK-000511', 'reason' => 'Suhu normal setelah dicek'];
        $this->postJson('/inventory/api/adjustments', $body)->assertStatus(403)->assertJsonPath('error', 'approval_required');
        $res = $this->postJson('/inventory/api/adjustments', $body + ['approval' => $this->approval])->assertCreated();
        $this->assertMatchesRegularExpression('/^UJI-RL\d{6}-0001$/', $res->json('doc_no'));

        $this->assertSame('received', $pack->fresh()->status);
        $this->assertSame('0.000000', StockLedger::balance($this->outlet->id, $pack->product_id, 'quarantine'));
        $this->assertSame('released', $pack->events()->latest('id')->value('event'));
        $doc = StockAdjustment::firstOrFail();
        $this->assertSame($this->head->id, (int) $doc->approved_by);
        $this->assertSame(1, OutboxEvent::where('event_type', 'stock.adjusted')->count());

        // Pack yang sudah dilepas bisa dijual lagi di kasir.
        $order = $this->newOrder();
        $this->orders()->addPack($order, 'PK-000511', $this->cashier);
        $this->assertSame('sold', $this->payAndFinalize($order)->lines()->first()->pack->status);
    }

    public function test_waste_from_shelf_and_quarantine_with_balance_checks(): void
    {
        $sausage = Product::where('barcode', '8991234500028')->firstOrFail();
        $before = StockLedger::balance($this->outlet->id, $sausage->id);

        $this->assertDomainError('approval_required', fn () => $this->adjust()->waste($this->outlet->id, $this->user('gudang@uji.test'), '8991234500028', '2', 'Kemasan sobek', null));
        $this->assertDomainError('quantity_exceeds', fn () => $this->adjust()->waste($this->outlet->id, $this->head, '8991234500028', '999', 'Kemasan sobek', null));
        $this->assertDomainError('pack_required', fn () => $this->adjust()->waste($this->outlet->id, $this->head, '0100101', '1', 'x', null));
        $this->assertDomainError('reason_required', fn () => $this->adjust()->waste($this->outlet->id, $this->head, 'PK-000184', null, ' ', null));

        // Kepala outlet memutuskan sendiri tanpa PIN kedua.
        $doc = $this->adjust()->waste($this->outlet->id, $this->head, '8991234500028', '2', 'Kemasan sobek', null);
        $this->assertMatchesRegularExpression('/^UJI-W\d{6}-0001$/', $doc->doc_no);
        $this->assertSame(Decimal::qty(Decimal::of($before)->minus('2')), StockLedger::balance($this->outlet->id, $sausage->id));
        $this->assertSame(['available', null], [$doc->lines[0]->from_state, $doc->lines[0]->to_state]);

        $pack = $this->adjust()->waste($this->outlet->id, $this->user('gudang@uji.test'), 'PK-000184', null, 'Warna berubah', $this->approval);
        $this->assertSame('1.500000', (string) $pack->lines[0]->quantity);
        $this->assertSame('wasted', $this->pack('PK-000184')->status);
        $this->assertDomainError('pack_not_wasteable', fn () => $this->adjust()->waste($this->outlet->id, $this->head, 'PK-000184', null, 'lagi', null));

        // Pack yang dibuang tidak bisa dijual.
        $order = $this->newOrder();
        $this->assertDomainError('pack_not_sellable', fn () => $this->orders()->addPack($order, 'PK-000184', $this->cashier));
        $this->assertDatabaseHas('audit_events', ['action' => 'stock.wasted', 'approver_id' => $this->head->id]);
    }

    public function test_expiring_packs_are_listed_and_can_be_held(): void
    {
        Pack::where('pack_code', 'PK-000191')->update(['expires_at' => now()->addDay()->toDateString()]);
        $this->actingAs($this->user('gudang@uji.test'));

        $codes = array_column($this->getJson('/inventory/api/quarantine')->json('expiring'), 'pack_code');
        $this->assertContains('PK-000191', $codes);
        $this->assertNotContains('PK-000184', $codes);

        $this->postJson('/inventory/api/adjustments', ['action' => 'hold', 'code' => 'PK-000191', 'reason' => 'Hampir kedaluwarsa'])
            ->assertOk()->assertJsonPath('status', 'hold');
        $order = $this->newOrder();
        $this->assertDomainError('approval_required', fn () => $this->orders()->addPack($order, 'PK-000191', $this->cashier));

        $lookup = $this->getJson('/inventory/api/lookup?code=8991234500011')->assertOk();
        $this->assertNotEmpty($lookup->json('locations'));
        $this->assertNotEmpty($lookup->json('movements'));
    }

    public function test_adjustments_reach_central_report_and_post_goods_issue_and_receipt(): void
    {
        $this->adjust()->waste($this->outlet->id, $this->head, '8991234500028', '2', 'Kemasan sobek', null);
        $this->adjust()->waste($this->outlet->id, $this->head, 'PK-000184', null, 'Warna berubah', null);

        // Opname: sayap ayam dihitung lebih 1 dari saldo sistem.
        $wings = Product::where('barcode', '8991234500011')->firstOrFail();
        $counts = app(StockCountService::class);
        $gudang = $this->user('gudang@uji.test');
        $session = $counts->open($gudang, $this->outlet->id, 'cycle', false, '1');
        $counts->record($session, $gudang, '8991234500011', (string) Decimal::of(StockLedger::balance($this->outlet->id, $wings->id))->plus('1'), 'GDG');
        $counts->review($session, $this->head);
        $counts->approve($session, $this->head);
        $count = StockAdjustment::where('kind', 'count')->firstOrFail();
        $this->assertSame([null, 'available'], [$count->lines[0]->from_state, $count->lines[0]->to_state]);

        $events = OutboxEvent::where('event_type', 'stock.adjusted')->orderBy('id')->get();
        $this->assertCount(3, $events);
        app(InboxReceiver::class)->receive($events->map(fn ($e) => $e->only(InboxReceiver::REQUIRED))->all(), $events->first()->source_id);
        $this->assertSame(3, DB::table('hq_stock_adjustments')->count());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/core/reports/stock')->assertOk()->assertSee('Kemasan sobek')->assertSee('Warna berubah');

        foreach (Product::all() as $p) {
            SapMapping::create(['company_db' => 'sap_solo', 'entity_type' => 'product', 'entity_key' => (string) $p->id, 'sap_code' => 'IT'.$p->code]);
        }
        SapMapping::create(['company_db' => 'sap_solo', 'entity_type' => 'account', 'entity_key' => 'waste', 'sap_code' => '6110201']);
        $this->app->instance(SapClient::class, new FakeSapClient);
        $this->assertSame(['posted' => 3], app(SapPostingService::class)->postInbox());

        $exits = SapDocument::where('sap_object', 'InventoryGenExits')->get();
        $this->assertCount(2, $exits);
        $this->assertSame('6110201', $exits->first()->request_payload['DocumentLines'][0]['AccountCode']);
        $entry = SapDocument::where('sap_object', 'InventoryGenEntries')->firstOrFail();
        $this->assertSame('1.000000', $entry->request_payload['DocumentLines'][0]['Quantity']);
        $this->assertSame(0, InboxEvent::where('event_type', 'stock.adjusted')->where('status', 'accepted')->count());
    }

    public function test_release_needs_no_sap_document(): void
    {
        $this->actingAs($this->user('gudang@uji.test'));
        $this->postJson('/inventory/api/receiving', ['code' => 'PK-000511', 'action' => 'reject', 'reason' => 'Suhu tinggi'])->assertOk();
        $doc = $this->adjust()->release($this->outlet->id, $this->head, 'PK-000511', null, 'Aman', null);
        $payload = $this->adjust()->payload($doc);
        $this->assertSame(['status' => 'not_required'], app(SapPostingService::class)->postStockAdjustment($payload));
    }
}
