<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Promotion\PromotionDesigner;
use App\Domain\Sync\InboxReceiver;
use App\Models\Device;
use App\Models\OutboxEvent;
use App\Models\PriceVersion;
use App\Models\Product;
use App\Models\PublicationVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Rasentra Core: hak akses peran pusat, maker-checker harga dan promo, proyeksi penjualan. */
class CoreTest extends AcceptanceTestCase
{
    private User $admin;

    private User $merch;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $make = fn (string $name, string $role) => User::create(['name' => $name, 'email' => $role.'@hq.test', 'password' => Hash::make('rahasia'), 'role' => $role, 'is_active' => true]);
        $this->admin = $make('Admin', 'admin');
        $this->merch = $make('Merch', 'hq_merch');
        $this->finance = $make('Finance', 'hq_finance');
    }

    public function test_login_and_role_based_access(): void
    {
        $this->get('/core')->assertRedirect(route('core.login'));
        $this->post('/core/login', ['email' => 'kasir@pjt.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/core/login', ['email' => 'admin@hq.test', 'password' => 'rahasia'])->assertRedirect(route('core.dashboard'));
        $this->get('/core')->assertOk()->assertSee('Penjualan bersih hari ini');

        $this->actingAs($this->merch)->get(route('core.promotions.index'))->assertOk();
        $this->actingAs($this->merch)->get(route('core.monitor.sync'))->assertForbidden();
        $this->actingAs($this->merch)->get(route('core.users.index'))->assertForbidden();
        $this->actingAs($this->finance)->get(route('core.monitor.sap'))->assertOk();
        $this->actingAs($this->finance)->get(route('core.audit'))->assertOk();
        $this->actingAs($this->finance)->get(route('core.prices.index'))->assertForbidden();

        // Petugas outlet yang punya sesi web tidak bisa masuk Core.
        $this->actingAs($this->cashier)->get('/core')->assertRedirect(route('core.login'));
    }

    public function test_price_version_needs_a_second_person_to_publish(): void
    {
        $base = PriceVersion::where('code', 'HRG-2026-10')->firstOrFail();
        $product = $base->items()->firstOrFail();

        $this->actingAs($this->merch)->post(route('core.prices.store'), [
            'code' => 'HRG-2026-11', 'outlet_id' => $this->outlet->id, 'effective_from' => now()->addDay()->toDateString(), 'copy_from' => $base->id,
        ])->assertRedirect();
        $draft = PriceVersion::where('code', 'HRG-2026-11')->firstOrFail();
        $this->assertSame('draft', $draft->status);

        $this->actingAs($this->merch)->put(route('core.prices.update', $draft), ['prices' => [$product->product_id => '99000']])->assertRedirect();
        $this->assertEquals('99000.00', $draft->items()->where('product_id', $product->product_id)->value('unit_price'));

        $this->actingAs($this->merch)->from(route('core.prices.show', $draft))->post(route('core.prices.publish', $draft))
            ->assertRedirect(route('core.prices.show', $draft))->assertSessionHas('error');
        $this->assertSame('draft', $draft->fresh()->status);

        $this->actingAs($this->admin)->post(route('core.prices.publish', $draft))->assertRedirect();
        $this->assertSame('published', $draft->fresh()->status);
        $this->assertTrue(PublicationVersion::where('entity_type', 'price_version')->where('entity_key', (string) $draft->id)->exists());
    }

    public function test_promotion_must_be_simulated_then_published_by_someone_else(): void
    {
        $product = Product::where('barcode', '8991234500011')->firstOrFail();
        $version = app(PromotionDesigner::class)->create($this->merch, 'UJI-15', 'Uji diskon 15%', [
            'type' => 'percent', 'scope' => 'item', 'rules' => ['product_ids' => [$product->id], 'percent' => '15'],
            'channels' => ['retail'], 'priority' => 50, 'stacking' => 'exclusive', 'funded_by' => 'supplier', 'starts_at' => now(), 'ends_at' => now()->addWeek(),
        ]);

        $this->actingAs($this->admin)->post(route('core.promotions.publish', $version), ['target' => 'all'])->assertSessionHas('error');
        $this->assertSame('draft', $version->fresh()->status);

        $this->actingAs($this->merch)->post(route('core.promotions.simulate', $version), [
            'outlet_id' => $this->outlet->id, 'channel' => 'retail', 'product' => [$product->id], 'qty' => ['2'],
        ])->assertRedirect()->assertSessionHas('simulation');
        $this->assertSame('simulated', $version->fresh()->status);
        $this->actingAs($this->merch)->get(route('core.promotions.show', $version))->assertOk()->assertSee('UJI-15');

        $this->actingAs($this->merch)->post(route('core.promotions.publish', $version), ['target' => 'all'])->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('core.promotions.publish', $version), ['target' => 'outlets', 'outlets' => ['PJT']])->assertRedirect();

        $version->refresh();
        $this->assertSame('published', $version->status);
        $this->assertSame([$this->outlet->id], $version->targets()->pluck('outlet_id')->all());
    }

    public function test_finalized_orders_reach_core_reports_through_the_inbox(): void
    {
        $order = $this->newOrder();
        $this->orders()->addSku($order, Product::where('barcode', '8991234500011')->first(), '2');
        $order = $this->payAndFinalize($order);
        $event = OutboxEvent::where('event_type', 'order.finalized')->firstOrFail();
        Device::create(['outlet_id' => $this->outlet->id, 'code' => $event->source_id, 'app' => 'outlet_server', 'name' => 'Server']);

        $wire = $event->only(['event_id', 'source_id', 'outlet_id', 'entity_type', 'entity_id', 'entity_version', 'event_type', 'payload', 'checksum']);
        app(InboxReceiver::class)->receive([$wire], $event->source_id);
        app(InboxReceiver::class)->receive([$wire], $event->source_id); // kirim ulang tidak menggandakan

        $this->assertSame(1, DB::table('hq_orders')->count());
        $this->assertEquals($order->net_amount, DB::table('hq_orders')->value('net_amount'));
        $this->assertSame(1, DB::table('hq_order_payments')->count());

        $this->actingAs($this->finance)->get(route('core.reports.sales'))->assertOk()->assertSee($this->outlet->name);
        $csv = $this->actingAs($this->finance)->get(route('core.reports.sales.csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString($order->doc_no, $csv);

        $this->artisan('rasentra:rebuild-sales')->assertSuccessful();
        $this->assertSame(1, DB::table('hq_orders')->count());
    }

    public function test_device_token_is_shown_once_and_stored_hashed(): void
    {
        $this->actingAs($this->admin)->post(route('core.devices.store'), [
            'outlet_id' => $this->outlet->id, 'code' => 'POS-PJT-09', 'app' => 'pos', 'name' => 'Kasir 9',
        ])->assertRedirect()->assertSessionHas('token');

        $token = session('token');
        $plain = is_array($token) ? $token['token'] : $token;
        $device = Device::where('code', 'POS-PJT-09')->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $device->token_hash);

        $this->actingAs($this->admin)->post(route('core.devices.revoke', $device))->assertRedirect();
        $this->assertNull($device->fresh()->token_hash);
    }

    public function test_hq_user_cannot_lock_themselves_out(): void
    {
        $this->actingAs($this->admin)->put(route('core.users.update', $this->admin), [
            'name' => 'Admin', 'email' => 'admin@hq.test', 'role' => 'hq_merch', 'is_active' => '0',
        ]);
        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertTrue((bool) $this->admin->fresh()->is_active);
    }
}
