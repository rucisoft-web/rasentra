<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Lokal\LokalSettings;
use App\Domain\Sync\HeartbeatSender;
use App\Domain\Sync\OutboxDispatcher;
use App\Models\DailyReconciliation;
use App\Models\Device;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\SystemState;

/**
 * Konsol Rasentra Lokal: akses kepala outlet, pemasangan ke Central, antrean kirim, tutup
 * hari dari layar, publikasi yang berlaku, indikator di POS dan health check.
 */
class LokalTest extends AcceptanceTestCase
{
    use RoutesHttpToCentral;

    private const TOKEN = 'token-server-uji-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Device::create([
            'outlet_id' => $this->outlet->id, 'code' => 'SRV-UJI', 'app' => 'outlet_server', 'name' => 'Server Lokal UJI',
            'token_hash' => hash('sha256', self::TOKEN),
        ]);
        config(['rasentra.outlet_code' => 'UJI', 'rasentra.source_id' => 'SRV-UJI', 'rasentra.central.url' => 'http://central.test', 'rasentra.central.token' => self::TOKEN]);
        $this->routeHttpToCentral();
    }

    public function test_console_is_for_the_outlet_head(): void
    {
        $this->get(route('lokal.index'))->assertRedirect(route('pos.login'));
        $this->actingAs($this->cashier)->get(route('lokal.index'))->assertForbidden();

        $other = User::factory()->create(['role' => 'outlet_head', 'outlet_id' => Outlet::where('code', 'LBK')->value('id')]);
        $this->actingAs($other)->get(route('lokal.index'))->assertForbidden();

        $this->actingAs($this->head)->get(route('lokal.index'))->assertOk()
            ->assertSee('Status server')->assertSee('Belum dicek')->assertSee('Outlet Uji');
        $this->actingAs($this->head)->get(route('pos'))->assertSee('Server outlet');
        $this->actingAs($this->cashier)->get(route('pos'))->assertDontSee('Server outlet');

        $this->getJson(route('lokal.health'))->assertOk()->assertJson(['status' => 'ok', 'outlet' => 'UJI', 'central' => 'unknown']);
    }

    public function test_pairing_checks_the_token_then_bootstraps_from_central(): void
    {
        config(['rasentra.central.url' => null, 'rasentra.central.token' => null]);

        // Token kasir dan token server outlet lain ditolak.
        Device::create(['outlet_id' => $this->outlet->id, 'code' => 'POS-UJI-01', 'app' => 'pos', 'name' => 'Kasir', 'token_hash' => hash('sha256', 'token-pos-uji-0123456789')]);
        $lbk = Outlet::where('code', 'LBK')->firstOrFail();
        Device::create(['outlet_id' => $lbk->id, 'code' => 'SRV-LBK', 'app' => 'outlet_server', 'name' => 'Server LBK', 'token_hash' => hash('sha256', 'token-server-lbk-0123456789')]);

        $this->actingAs($this->head)->post(route('lokal.setup.store'), ['central_url' => 'http://central.test', 'token' => 'token-pos-uji-0123456789'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'bukan token server outlet'));
        $this->actingAs($this->head)->post(route('lokal.setup.store'), ['central_url' => 'http://central.test', 'token' => 'token-server-lbk-0123456789'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'milik outlet LBK'));
        $this->actingAs($this->head)->post(route('lokal.setup.store'), ['central_url' => 'http://central.test', 'token' => 'salah-salah-salah-salah'])
            ->assertSessionHas('error');
        $this->assertNull(config('rasentra.central.token'));

        $this->actingAs($this->head)->post(route('lokal.setup.store'), ['central_url' => 'http://central.test/', 'token' => self::TOKEN])
            ->assertRedirect(route('lokal.setup'))->assertSessionHas('ok');
        $saved = LokalSettings::get();
        $this->assertSame(['http://central.test', self::TOKEN, 'UJI', 'SRV-UJI'], [$saved['central_url'], $saved['token'], $saved['outlet_code'], $saved['source_id']]);
        $this->assertStringNotContainsString(self::TOKEN, json_encode(SystemState::get('lokal:settings')), 'token disimpan terenkripsi');

        $this->actingAs($this->head)->post(route('lokal.setup.bootstrap'))->assertSessionHas('ok', fn ($m) => str_contains($m, 'Snapshot diterapkan'));
        $this->actingAs($this->head)->get(route('lokal.setup'))->assertOk()->assertSee('Terhubung');

        // Kasir tidak boleh mengubah pairing setelah server berisi petugas.
        $this->actingAs($this->cashier)->get(route('lokal.setup'))->assertForbidden();
    }

    public function test_setup_is_open_only_while_the_server_has_no_staff(): void
    {
        $this->get(route('lokal.setup'))->assertRedirect(route('pos.login'));

        User::whereNotNull('outlet_id')->update(['pin_hash' => null]);
        $this->get(route('lokal.setup'))->assertOk()->assertSee('Hubungkan ke Rasentra Central')->assertSee('Ambil snapshot');
    }

    public function test_outbox_can_be_requeued_and_pushed_from_the_console(): void
    {
        OutboxEvent::query()->delete();
        $order = $this->newOrder();
        $this->orders()->addSku($order, Product::where('barcode', '8991234500011')->first(), '1');
        $order = $this->payAndFinalize($order);
        $event = OutboxEvent::firstOrFail();
        $event->update(['status' => 'parked', 'attempts' => 20, 'last_error' => 'cURL error 28']);

        $this->actingAs($this->cashier)->getJson('/pos/api/sync-status')->assertOk()->assertJson(['central' => 'unknown', 'queued' => 1, 'parked' => 1]);
        $this->actingAs($this->head)->get(route('lokal.outbox'))->assertOk()->assertSee($order->doc_no)->assertSee('Berhenti dicoba')->assertSee('cURL error 28');

        $this->actingAs($this->head)->post(route('lokal.outbox.retry', $event))->assertRedirect();
        $this->assertSame('pending', $event->fresh()->status);
        $this->actingAs($this->head)->post(route('lokal.push'))->assertSessionHas('ok', '1 event terkirim ke Central.');
        $this->assertSame('acked', $event->fresh()->status);

        $this->actingAs($this->head)->post(route('lokal.heartbeat'))->assertSessionHas('ok');
        $this->actingAs($this->cashier)->getJson('/pos/api/sync-status')->assertJson(['central' => 'online', 'queued' => 0]);
    }

    public function test_head_closes_the_day_from_the_console_and_central_reconciles_it(): void
    {
        OutboxEvent::query()->delete();
        $order = $this->newOrder();
        $this->orders()->addSku($order, Product::where('barcode', '8991234500011')->first(), '2');
        $order = $this->payAndFinalize($order);
        app(OutboxDispatcher::class)->dispatch();
        $date = $order->business_date->toDateString();

        $this->actingAs($this->head)->get(route('lokal.close-day', ['date' => $date]))->assertOk()
            ->assertSee('Belum ditutup')->assertSee('masih buka')->assertSee('data-confirm', false);
        $this->actingAs($this->head)->post(route('lokal.close-day.store'), ['date' => $date])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'sudah diterima Central'));

        $rec = DailyReconciliation::where('outlet_id', $this->outlet->id)->firstOrFail();
        $this->assertSame('matched', $rec->status);
        $this->assertSame(1, $rec->open_shifts);
        $this->actingAs($this->head)->get(route('lokal.close-day', ['date' => $date]))->assertSee('Ditutup v1')->assertSee('Diterima Central');

        $this->actingAs($this->head)->post(route('lokal.close-day.store'), ['date' => now()->addDays(2)->toDateString()])->assertSessionHasErrors('date');
    }

    public function test_publications_status_and_backup_pages(): void
    {
        app(HeartbeatSender::class)->send();
        $this->actingAs($this->head)->post(route('lokal.pull'))->assertSessionHas('ok');

        $this->actingAs($this->head)->get(route('lokal.publications'))->assertOk()
            ->assertSee('HRG-2026-10')->assertSee('UJI-SAPI-2KG');
        $this->actingAs($this->head)->get(route('lokal.index'))->assertOk()->assertSee('Terhubung');

        // Database uji berada di memori, jadi backup menolak dengan pesan yang jelas.
        $this->actingAs($this->head)->post(route('lokal.backup'))->assertSessionHas('error', fn ($m) => str_contains($m, 'Backup gagal'));
    }
}
