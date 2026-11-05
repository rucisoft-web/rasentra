<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Access\Permissions;
use App\Domain\Membership\MembershipService;
use App\Domain\Operations\DeliveryService;
use App\Domain\Operations\StaffService;
use App\Domain\Sales\Approval;
use App\Domain\Sales\PaymentService;
use App\Domain\Sales\TableService;
use App\Domain\Settings\Settings;
use App\Domain\Sync\PublicationApplier;
use App\Domain\Sync\Publications;
use App\Models\BusinessUnit;
use App\Models\Crm\AccurateConnection;
use App\Models\Delivery;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberTier;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\PublicationVersion;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShiftTemplate;
use App\Models\StaffRoster;
use App\Models\User;
use App\Models\Visit;
use Database\Seeders\OperationsSeeder;
use Illuminate\Support\Carbon;

/**
 * Fitur operasional grup: membership & poin, self ordering QR, delivery order, roster & absensi,
 * pengaturan berjenjang, matriks izin, serta halaman Rasentra Core dan Lokal yang baru.
 */
class OperationsTest extends AcceptanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OperationsSeeder::class);
    }

    private function members(): MembershipService
    {
        return app(MembershipService::class);
    }

    public function test_member_earns_points_with_tier_multiplier_and_redeems_as_payment(): void
    {
        $member = $this->members()->enroll(['name' => 'Tamu Setia', 'phone' => '0812-9999-0001'], $this->outlet->id, $this->cashier);
        $this->assertSame('6281299990001', $member->phone);
        $this->assertGreaterThanOrEqual(MembershipService::PROVISIONAL_BASE, $member->id);
        $this->assertTrue(OutboxEvent::where('event_type', 'member.enrolled')->exists());
        $this->assertSame($member->id, $this->members()->find('081299990001')->id);

        // Belanja 3 × Rp32.000 = Rp96.000 → 9 poin (Silver, 1×).
        $order = $this->newOrder();
        $this->orders()->addSku($order, Product::where('code', '0200201')->first(), '3');
        $this->members()->attach($order->fresh(), $member);
        $order = $this->payAndFinalize($order);
        $this->assertSame('9.00', (string) $order->points_earned);
        $this->assertSame('9.00', (string) $member->fresh()->points_balance);
        $event = OutboxEvent::where('event_type', 'member.points')->latest('id')->first();
        $this->assertCount(1, $event->payload['entries']);
        $this->assertSame($member->id, $event->payload['member_id']);

        // Tukar 150 poin (Rp15.000 dengan nilai poin Rp100).
        $member->update(['points_balance' => 500]);
        $order2 = $this->newOrder();
        $this->orders()->addSku($order2, Product::where('code', '0200201')->first(), '1');
        $this->members()->attach($order2->fresh(), $member);
        $payments = app(PaymentService::class);
        $this->assertDomainError('points_below_min', fn () => $payments->add($order2->fresh(), 'points', '5000'));
        $payments->add($order2->fresh(), 'points', '15000');
        $payments->add($order2->fresh(), 'cash', '17000', ['tendered' => '17000']);
        $order2 = $this->orders()->finalize($order2->fresh(), $this->cashier);
        $this->assertSame('150.00', (string) $order2->points_redeemed);
        // 500 − 150 + floor(17.000/10.000) = 351
        $this->assertSame('351.00', (string) $member->fresh()->points_balance);
        $this->assertSame(2, MemberPointEntry::where('order_id', $order2->id)->count());
    }

    public function test_points_payment_requires_member_and_enough_balance(): void
    {
        $order = $this->newOrder();
        $this->orders()->addSku($order, Product::where('code', '0200201')->first(), '1');
        $this->assertDomainError('member_required', fn () => app(PaymentService::class)->add($order->fresh(), 'points', '10000'));

        $member = $this->members()->enroll(['name' => 'Saldo Tipis', 'phone' => '081299990002'], $this->outlet->id);
        $this->members()->attach($order->fresh(), $member);
        $this->assertDomainError('points_insufficient', fn () => app(PaymentService::class)->add($order->fresh(), 'points', '20000'));
    }

    public function test_central_applies_point_entries_once_and_upgrades_tier(): void
    {
        $member = Member::create(['id' => 77, 'phone' => '6281200000077', 'name' => 'Pusat', 'code' => 'MPUSAT', 'is_member' => true, 'member_spend' => 4_900_000]);
        $entries = [['id' => '019a0000-0000-7000-8000-000000000001', 'outlet_id' => $this->outlet->id, 'order_id' => null, 'type' => 'earn', 'points' => '20.00', 'order_amount' => '200000.00', 'business_date' => now()->toDateString()]];
        $this->members()->applyAtCentral($entries, 77);
        $this->members()->applyAtCentral($entries, 77);
        $member->refresh();
        $this->assertSame('20.00', (string) $member->points_balance);
        $this->assertSame('GOLD', MemberTier::find($member->member_tier_id)->code);
    }

    public function test_publication_replaces_provisional_member_with_central_id(): void
    {
        $member = $this->members()->enroll(['name' => 'Baru', 'phone' => '081299990003'], $this->outlet->id);
        $order = $this->newOrder();
        $order->update(['crm_customer_id' => $member->id]);

        app(PublicationApplier::class)->apply(['entity_type' => 'member', 'entity_key' => '501', 'payload' => [
            'id' => 501, 'code' => $member->code, 'phone' => $member->phone, 'name' => 'Baru', 'is_member' => true, 'points_balance' => '0.00',
        ]], $this->outlet->id);

        $this->assertNull(Member::find($member->id));
        $this->assertSame(501, (int) $order->fresh()->crm_customer_id);
    }

    public function test_self_order_waits_for_waiter_then_lands_on_table_and_kitchen(): void
    {
        $table = DiningTable::where('outlet_id', $this->outlet->id)->where('code', 'A1')->first();
        $this->assertNotNull($table->qr_token);
        $steak = Product::where('code', '0700701')->first();

        $this->get('/order/'.$table->qr_token)->assertOk()->assertSee('Meja A1');
        $this->getJson('/order/'.$table->qr_token.'/menu')->assertOk()->assertJsonFragment(['name' => 'Sirloin Steak 200 g']);
        $this->getJson('/order/salah-token/menu')->assertNotFound();

        $id = $this->postJson('/order/'.$table->qr_token, ['items' => [['product_id' => $steak->id, 'quantity' => 2]], 'name' => 'Rani', 'guests' => 3])
            ->assertCreated()->json('id');
        $this->assertFalse(Visit::where('dining_table_id', $table->id)->where('status', 'open')->exists());

        $waiter = User::where('email', 'pelayan@uji.test')->first();
        $this->actingAs($waiter)->get(route('lokal.self-orders'))->assertOk()->assertSee('Rani');
        $this->actingAs($waiter)->post(route('lokal.self-orders.accept', $id))->assertRedirect();

        $visit = Visit::where('dining_table_id', $table->id)->where('status', 'open')->firstOrFail();
        $order = app(TableService::class)->order($visit);
        $this->assertSame('2.000000', (string) $order->lines()->first()->quantity);
        $this->assertTrue(KitchenTicket::where('order_id', $order->id)->exists());
        $this->getJson('/order/'.$table->qr_token.'/status/'.$id)->assertJson(['status' => 'accepted']);

        Setting::create(['scope' => 'outlet', 'scope_id' => $this->outlet->id, 'key' => 'self_order.enabled', 'value' => false]);
        $this->get('/order/'.$table->qr_token)->assertForbidden();
    }

    public function test_delivery_order_is_assigned_tracked_and_sent_to_central(): void
    {
        $order = $this->newOrder('delivery');
        $this->orders()->addSku($order, Product::where('code', '0200201')->first(), '1');
        $this->orders()->setCustomer($order->fresh(), ['customer_name' => 'Pak Joko', 'customer_phone' => '0811', 'delivery_address' => 'Jl. Slamet Riyadi 1']);
        $order = $this->payAndFinalize($order);
        $courier = User::where('outlet_id', $this->outlet->id)->where('role', 'courier')->firstOrFail();

        $this->actingAs($this->cashier)->get(route('lokal.deliveries'))->assertOk()->assertSee('Pak Joko');
        $this->actingAs($this->cashier)->post(route('lokal.deliveries.assign', $order), ['provider' => 'internal', 'courier_id' => $courier->id, 'fee' => 12000])->assertRedirect();
        $delivery = Delivery::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('assigned', $delivery->status);

        $svc = app(DeliveryService::class);
        $this->assertDomainError('delivery_status', fn () => $svc->advance($delivery, 'delivered', $this->cashier));
        $svc->advance($delivery, 'on_the_way', $courier);
        $svc->advance($delivery->fresh(), 'delivered', $courier);
        $this->assertNotNull($delivery->fresh()->delivered_at);
        $this->assertSame(3, OutboxEvent::where('event_type', 'delivery.updated')->where('entity_id', $delivery->id)->count());
    }

    public function test_attendance_marks_late_against_roster(): void
    {
        $tz = $this->outlet->timezone;
        Carbon::setTestNow(Carbon::parse(now($tz)->toDateString().' 07:25', $tz));
        $pagi = ShiftTemplate::where('code', 'PAGI')->first();
        StaffRoster::updateOrCreate(['user_id' => $this->cashier->id, 'work_date' => now($tz)->toDateString()], ['outlet_id' => $this->outlet->id, 'shift_template_id' => $pagi->id]);

        $staff = app(StaffService::class);
        $in = $staff->clock($this->cashier, $this->outlet->id);
        $this->assertSame(25, $in->late_minutes);
        $this->assertSame('PAGI', $in->shift_code);

        Carbon::setTestNow(now()->addHours(8));
        $out = $staff->clock($this->cashier, $this->outlet->id);
        $this->assertSame($in->id, $out->id);
        $this->assertSame(480, $out->worked_minutes);
        Carbon::setTestNow();

        config(['rasentra.outlet_code' => $this->outlet->code]);
        $this->get(route('attendance'))->assertOk()->assertSee($this->cashier->name);
        $this->post(route('attendance.clock'), ['user_id' => $this->cashier->id, 'pin' => '0000'])->assertSessionHasErrors('pin');
    }

    public function test_settings_resolve_outlet_then_company_then_group(): void
    {
        $settings = app(Settings::class);
        $this->assertSame('10000', $settings->get('member.earn_per_amount', $this->outlet->id));
        Setting::create(['scope' => 'global', 'key' => 'member.earn_per_amount', 'value' => 20000]);
        Setting::create(['scope' => 'company', 'scope_id' => $this->outlet->business_unit_id, 'key' => 'member.earn_per_amount', 'value' => 15000]);
        $this->assertSame('15000', app(Settings::class)->get('member.earn_per_amount', $this->outlet->id));
        Setting::create(['scope' => 'outlet', 'scope_id' => $this->outlet->id, 'key' => 'member.earn_per_amount', 'value' => 5000]);
        $this->assertSame('5000', app(Settings::class)->get('member.earn_per_amount', $this->outlet->id));
        $this->assertSame('20000', app(Settings::class)->get('member.earn_per_amount'));
    }

    public function test_permission_matrix_overrides_defaults_and_is_published(): void
    {
        $this->assertTrue(Permissions::allows($this->head, 'pos.approve'));
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-ops@uji.test', 'password' => 'x', 'role' => 'admin', 'is_active' => true]);

        $perm = Role::all()->mapWithKeys(fn ($r) => [$r->code => $r->permissions])->all();
        $perm['outlet_head'] = array_values(array_diff($perm['outlet_head'], ['pos.approve']));
        $this->actingAs($admin)->put(route('core.access.update'), ['perm' => $perm])->assertRedirect();

        $this->assertFalse(Permissions::allows($this->head->fresh(), 'pos.approve'));
        $this->assertDomainError('approval_invalid', fn () => Approval::verify($this->approval, $this->cashier->id, $this->outlet->id));
        $this->assertTrue(PublicationVersion::where('entity_type', 'role')->exists());
    }

    public function test_core_and_lokal_pages_render(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-ops@uji.test', 'password' => 'x', 'role' => 'admin', 'is_active' => true]);
        foreach (['core.organization', 'core.access.index', 'core.settings.index', 'core.payment-methods.index', 'core.tables.index',
            'core.shift-templates.index', 'core.members.index', 'core.member-tiers.index', 'core.reports.group', 'core.reports.deliveries', 'core.reports.attendance'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
        $this->actingAs($admin)->get(route('core.settings.index', ['scope' => 'outlet', 'scope_id' => $this->outlet->id]))->assertOk();
        $this->actingAs($admin)->get(route('core.tables.print', $this->outlet))->assertOk()->assertSee('/order/', false);
        $member = Member::where('is_member', true)->first();
        $this->actingAs($admin)->get(route('core.members.show', $member))->assertOk();

        $this->actingAs($admin)->put(route('core.settings.update'), ['scope' => 'outlet', 'scope_id' => $this->outlet->id, 'values' => ['receipt.footer' => 'Sampai jumpa']])->assertRedirect();
        $this->assertSame('Sampai jumpa', app(Settings::class)->get('receipt.footer', $this->outlet->id));
        $this->assertSame([$this->outlet->id], PublicationVersion::where('entity_type', 'setting')->latest('id')->first()->outlet_ids);

        $this->actingAs($this->head)->get(route('lokal.staff'))->assertOk();
        $this->actingAs($this->cashier)->get(route('lokal.staff'))->assertForbidden();
        $this->actingAs($this->cashier)->getJson('/pos/api/bootstrap')->assertOk()->assertJsonPath('member.enabled', true)
            ->assertJsonFragment(['code' => 'transfer']);

        $snapshot = app(Publications::class)->snapshot($this->outlet->id);
        $types = collect($snapshot['changes'])->pluck('entity_type')->unique()->all();
        foreach (['role', 'payment_method', 'member_tier', 'shift_template', 'member', 'setting'] as $t) {
            $this->assertContains($t, $types);
        }
    }

    public function test_pos_member_endpoints(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/api/members', ['name' => 'Lewat POS', 'phone' => '081299990009'])->assertCreated()->assertJsonPath('member.tier', 'Silver');
        $m = $this->actingAs($this->cashier)->getJson('/pos/api/members?q=6281299990009')->assertOk()->json('member');
        $order = $this->newOrder();
        $this->actingAs($this->cashier)->postJson("/pos/api/orders/{$order->id}/member", ['member_id' => $m['id']])->assertOk()->assertJsonPath('order.crm_customer_id', $m['id']);
        $chef = User::where('email', 'chef@uji.test')->first();
        $this->actingAs($chef)->postJson('/pos/api/members', ['name' => 'X', 'phone' => '0812999900010'])->assertForbidden();
    }

    public function test_accurate_posting_reuses_the_crm_connection_token(): void
    {
        $bu = BusinessUnit::query()->firstOrFail();
        $bu->update(['accounting_system' => 'accurate']);
        $this->assertFalse($bu->fresh()->hasAccurateCredentials());

        AccurateConnection::create(['business_unit_id' => $bu->id, 'api_token' => 'crm-tok', 'signature_secret' => 'crm-sec']);
        $creds = $bu->fresh()->accurateCredentials();
        $this->assertSame(['crm-tok', 'crm-sec'], [$creds['token'], $creds['secret']]);
    }
}
