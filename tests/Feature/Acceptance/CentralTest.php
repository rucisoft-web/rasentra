<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Cash\ShiftService;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Sync\CentralClient;
use App\Domain\Sync\ChangePuller;
use App\Domain\Sync\DayCloser;
use App\Domain\Sync\HeartbeatSender;
use App\Domain\Sync\OutboxDispatcher;
use App\Domain\Sync\PublicationApplier;
use App\Domain\Sync\Publications;
use App\Models\DailyReconciliation;
use App\Models\Device;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\OutletSyncState;
use App\Models\Product;
use App\Models\PublicationVersion;
use App\Models\Shift;
use App\Models\User;
use App\Support\SystemState;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * Rasentra Central dan pasangannya di outlet: heartbeat, snapshot dan publikasi ke Rasentra
 * Lokal (database terpisah), tutup hari dan rekonsiliasi, serta halaman Central di Core.
 */
class CentralTest extends AcceptanceTestCase
{
    private const TOKEN = 'token-server-uji';

    private Device $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = Device::create([
            'outlet_id' => $this->outlet->id, 'code' => config('rasentra.source_id'), 'app' => 'outlet_server',
            'name' => 'Server Lokal UJI', 'token_hash' => hash('sha256', self::TOKEN),
        ]);
        config(['rasentra.central.url' => 'http://central.test', 'rasentra.central.token' => self::TOKEN, 'rasentra.outlet_code' => 'UJI']);
        $this->routeHttpToCentral();
    }

    public function test_heartbeat_reports_queue_version_clock_and_cursor(): void
    {
        OutboxEvent::query()->delete();
        $order = $this->newOrder();
        $this->orders()->addSku($order, Product::where('barcode', '8991234500011')->first(), '1');
        $this->payAndFinalize($order);
        OutboxEvent::query()->update(['status' => 'parked']);
        config(['rasentra.version' => '1.4.2']);

        $result = app(HeartbeatSender::class)->send();
        $this->assertTrue($result['ok']);

        $state = OutletSyncState::where('device_id', $this->server->id)->firstOrFail();
        $this->assertSame('1.4.2', $state->app_version);
        $this->assertSame(1, $state->outbox_parked);
        $this->assertSame(1, $state->open_shifts);
        $this->assertLessThanOrEqual(2, abs($state->clock_skew_seconds));
        $this->assertNotNull($state->last_heartbeat_at);

        // Jam server outlet maju 5 menit: Central mencatat selisihnya.
        $this->withToken(self::TOKEN)->postJson('/api/v1/sync/heartbeat', ['sent_at' => now()->addMinutes(5)->toIso8601ZuluString()])
            ->assertOk()->assertJsonStructure(['server_time', 'central_cursor']);
        $this->assertEqualsWithDelta(300, $state->fresh()->clock_skew_seconds, 2);

        // Perangkat kasir boleh heartbeat tetapi tidak menimpa kondisi server outlet.
        Device::create(['outlet_id' => $this->outlet->id, 'code' => 'POS-UJI-01', 'app' => 'pos', 'name' => 'Kasir', 'token_hash' => hash('sha256', 'pos')]);
        $this->withToken('pos')->postJson('/api/v1/sync/heartbeat', [])->assertOk();
        $this->assertSame(1, OutletSyncState::count());
    }

    public function test_new_lokal_is_bootstrapped_from_snapshot_then_follows_publications(): void
    {
        $lokal = $this->lokalDatabase();
        $puller = new ChangePuller(app(CentralClient::class), new PublicationApplier('lokal'));

        $result = $puller->bootstrap();
        $this->assertArrayNotHasKey('error', $result['snapshot']);
        $this->assertSame((int) PublicationVersion::max('id'), $puller->pull()['cursor']);

        // Hanya outlet sendiri beserta perusahaannya; petugas dengan PIN, tanpa remember_token.
        $this->assertSame(['UJI'], $lokal->table('outlets')->pluck('code')->all());
        $this->assertSame('SKU', $lokal->table('business_units')->value('code'));
        $staff = User::where('outlet_id', $this->outlet->id)->orderBy('id')->get();
        $this->assertSame($staff->pluck('email')->all(), $lokal->table('users')->orderBy('id')->pluck('email')->all());
        $this->assertTrue(Hash::check('9999', $lokal->table('users')->where('email', 'kepala@uji.test')->value('pin_hash')));
        $this->assertNull($lokal->table('users')->whereNotNull('remember_token')->value('id'));
        $this->assertSame(Product::count(), $lokal->table('products')->count());
        $this->assertSame(DB::table('price_items')->count(), $lokal->table('price_items')->count());
        $this->assertSame(1, $lokal->table('promotion_targets')->where('outlet_id', $this->outlet->id)->count());
        $this->assertSame(Location::where('outlet_id', $this->outlet->id)->count(), $lokal->table('locations')->count());

        // Perubahan di Central: produk diganti nama, petugas baru, lokasi di outlet lain.
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@hq.test', 'password' => Hash::make('rahasia-pusat'), 'role' => 'admin', 'is_active' => true]);
        $product = Product::where('barcode', '8991234500011')->firstOrFail();
        $product->update(['name' => 'Saus Lada Hitam 250 ml']);
        app(Publications::class)->master('product', $product->id);
        $this->actingAs($admin)->post(route('core.users.store'), [
            'name' => 'Kasir Baru', 'email' => 'kasir3@uji.test', 'role' => 'cashier', 'outlet_id' => $this->outlet->id, 'pin' => '4321', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertNull(PublicationVersion::where('entity_type', 'user')->where('entity_key', (string) $admin->id)->first(), 'akun pusat tidak dikirim ke outlet');
        $lbk = Outlet::where('code', 'LBK')->firstOrFail();
        $this->actingAs($admin)->post(route('core.outlets.locations.store', $lbk), ['code' => 'CHILL2', 'name' => 'Chiller 2', 'type' => 'storage'])->assertRedirect();

        $pulled = $puller->pull();
        $this->assertSame(2, $pulled['applied']);
        $this->assertSame((int) PublicationVersion::max('id'), $pulled['cursor'], 'cursor melewati publikasi outlet lain');
        $this->assertSame('Saus Lada Hitam 250 ml', $lokal->table('products')->where('id', $product->id)->value('name'));
        $this->assertTrue(Hash::check('4321', $lokal->table('users')->where('email', 'kasir3@uji.test')->value('pin_hash')));
        $this->assertFalse($lokal->table('locations')->where('code', 'CHILL2')->exists());
        $this->assertSame($pulled['cursor'], OutletSyncState::where('device_id', $this->server->id)->value('applied_cursor'));

        $this->assertSame(0, $puller->pull()['applied'], 'tarik ulang tidak menerapkan apa pun');

        // Snapshot berisi PIN petugas: hanya untuk server outlet. Perangkat lain tidak menerima data petugas.
        Device::create(['outlet_id' => $this->outlet->id, 'code' => 'POS-UJI-01', 'app' => 'pos', 'name' => 'Kasir', 'token_hash' => hash('sha256', 'pos')]);
        $this->withToken('pos')->getJson('/api/v1/sync/snapshot')->assertForbidden();
        $types = collect($this->withToken('pos')->getJson('/api/v1/sync/changes?cursor=0')->assertOk()->json('changes'))->pluck('entity_type');
        $this->assertNotContains('user', $types->all());
    }

    public function test_failed_publication_holds_the_cursor_until_it_applies(): void
    {
        $lokal = $this->lokalDatabase();
        $puller = new ChangePuller(app(CentralClient::class), new PublicationApplier('lokal'));
        $puller->bootstrap();
        $before = $puller->pull()['cursor'];

        // Versi harga yang merujuk produk yang belum ada di outlet: gagal, cursor tidak maju.
        PublicationVersion::create(['entity_type' => 'price_version', 'entity_key' => '9001', 'outlet_ids' => null, 'payload' => [
            'id' => 9001, 'code' => 'HRG-RUSAK', 'outlet_id' => null, 'effective_from' => now()->toIso8601ZuluString(), 'status' => 'published',
            'items' => [['product_id' => 990001, 'unit_price' => '1000.00']],
        ]]);
        $later = app(Publications::class)->master('outlet', $this->outlet->id);

        $result = $puller->pull();
        $this->assertStringContainsString('price_version 9001', $result['error']);
        $this->assertSame($before, $result['cursor']);
        $this->assertFalse($lokal->table('price_versions')->where('id', 9001)->exists(), 'transaksi dibatalkan utuh');
        $this->assertStringContainsString('price_version 9001', OutletSyncState::where('device_id', $this->server->id)->value('last_error'));

        $lokal->table('products')->insert(['id' => 990001, 'code' => 'X-990001', 'name' => 'Produk susulan', 'type' => 'retail', 'uom_code' => 'pcs', 'created_at' => now(), 'updated_at' => now()]);
        $result = $puller->pull();
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($later->id, $result['cursor']);
        $this->assertTrue($lokal->table('price_items')->where('price_version_id', 9001)->exists());
        $this->assertNull(OutletSyncState::where('device_id', $this->server->id)->value('last_error'));
    }

    public function test_close_day_is_reconciled_against_sales_received_by_central(): void
    {
        OutboxEvent::query()->delete();
        $sku = Product::where('barcode', '8991234500011')->firstOrFail();
        $orders = collect([1, 2])->map(function ($qty) use ($sku) {
            $order = $this->newOrder();
            $this->orders()->addSku($order, $sku, (string) $qty);

            return $this->payAndFinalize($order);
        });
        $shift = Shift::where('user_id', $this->cashier->id)->where('status', 'open')->firstOrFail();
        $expected = app(ShiftService::class)->expected($shift)['cash'];
        app(ShiftService::class)->close($shift, $this->cashier, ['cash' => (string) ((float) $expected - 5000)]);

        $date = $orders->first()->business_date->toDateString();
        app(DayCloser::class)->close($this->outlet, $date, $this->head->id);

        // Struk kedua tertahan di outlet (mis. gagal kirim): Central menunggu struk itu.
        $held = OutboxEvent::where('entity_id', $orders->last()->id)->firstOrFail();
        $held->update(['status' => 'held']);
        app(OutboxDispatcher::class)->dispatch();

        $rec = DailyReconciliation::where('outlet_id', $this->outlet->id)->firstOrFail();
        $this->assertSame('waiting', $rec->status);
        $this->assertSame([$orders->last()->doc_no], $rec->missing_doc_nos);
        $this->assertSame(2, $rec->local_orders);
        $this->assertSame(1, $rec->central_orders);
        $this->assertSame('-5000.00', (string) $rec->cash_difference);

        $held->update(['status' => 'pending']);
        app(OutboxDispatcher::class)->dispatch();
        $rec->refresh();
        $this->assertSame('matched', $rec->status);
        $this->assertSame($rec->local_net, $rec->central_net);

        // Tutup ulang: versi baru menggantikan ringkasan lama.
        app(DayCloser::class)->close($this->outlet, $date);
        app(OutboxDispatcher::class)->dispatch();
        $this->assertSame(2, $rec->fresh()->closing_version);

        $finance = User::create(['name' => 'Finance', 'email' => 'fin@hq.test', 'password' => Hash::make('rahasia-pusat'), 'role' => 'hq_finance', 'is_active' => true]);
        $this->actingAs($finance)->get(route('core.reconciliation', ['to' => $date]))->assertOk()->assertSee('Cocok');
        $this->actingAs($finance)->get(route('core.reconciliation', ['to' => $date, 'show' => $rec->id]))->assertOk()->assertSee('Selisih kas shift')->assertSee('-Rp5.000');
        $this->actingAs($finance)->post(route('core.reconciliation.recheck', $rec))->assertRedirect();
    }

    public function test_central_page_shows_outlet_health_and_checks_each_sap_instance(): void
    {
        app(HeartbeatSender::class)->send();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@hq.test', 'password' => Hash::make('rahasia-pusat'), 'role' => 'admin', 'is_active' => true]);
        $merch = User::create(['name' => 'Merch', 'email' => 'merch@hq.test', 'password' => Hash::make('rahasia-pusat'), 'role' => 'hq_merch', 'is_active' => true]);

        $this->actingAs($admin)->get(route('core.central'))->assertOk()
            ->assertSee('Rasentra Lokal per outlet')->assertSee('sap_solo')->assertSee('sap_lombok')->assertSee('Sehat');
        $this->actingAs($merch)->get(route('core.central'))->assertForbidden();

        $this->actingAs($admin)->post(route('core.central.sap-check'))->assertSessionHas('ok');
        $this->assertTrue(SystemState::get('sap:sap_solo')['ok']);

        app(SapClient::class)->unreachable = true;
        $this->actingAs($admin)->post(route('core.central.sap-check'))->assertSessionHas('error');
        $this->assertFalse(SystemState::get('sap:sap_lombok')['ok']);
        $this->artisan('rasentra:sap-check')->assertFailed();
    }

    /** Semua panggilan HTTP Rasentra Lokal ke Central diteruskan ke aplikasi ini sendiri. */
    private function routeHttpToCentral(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = parse_url($request->url());
            $path = $url['path'].(isset($url['query']) ? '?'.$url['query'] : '');
            $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
            $response = $this->withToken($token)->json($request->method(), $path, $request->method() === 'GET' ? [] : $request->data());

            return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
        });
    }

    /** Database Rasentra Lokal yang kosong, terpisah dari database Central uji. */
    private function lokalDatabase(): Connection
    {
        config(['database.connections.lokal' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('lokal');
        Artisan::call('migrate', ['--database' => 'lokal', '--force' => true]);

        return DB::connection('lokal');
    }
}
