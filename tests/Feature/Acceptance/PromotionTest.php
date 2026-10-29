<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Promotion\ActivePromotions;
use App\Domain\Promotion\PromotionPublisher;
use App\Models\Outlet;
use App\Models\Promotion;
use App\Models\PromotionVersion;
use App\Models\User;

class PromotionTest extends AcceptanceTestCase
{
    /** U05: promo Lampung hanya diterapkan di Antasari, Diponegoro, Citraland dan Kemiling. */
    public function test_u05_lampung_promotion_targets_only_lampung(): void
    {
        $creator = User::factory()->create(['role' => 'merchandiser']);
        $admin = User::factory()->create(['role' => 'admin']);
        $promo = Promotion::create(['code' => 'LPG-BBQ', 'name' => 'Promo Lampung']);
        $version = PromotionVersion::create([
            'promotion_id' => $promo->id, 'version' => 1, 'type' => 'percent', 'scope' => 'item',
            'rules' => ['categories' => ['Bumbu & Saus'], 'percent' => 20], 'starts_at' => now()->subHour(),
            'created_by' => $creator->id,
        ]);

        $this->assertDomainError('approval_self', fn () => app(PromotionPublisher::class)->publish($version, $creator, ['regions' => ['LPG']]));
        $published = app(PromotionPublisher::class)->publish($version, $admin, ['regions' => ['LPG']]);

        $codes = Outlet::whereIn('id', $published->targets->pluck('outlet_id'))->orderBy('code')->pluck('code')->all();
        $this->assertSame(['ANT', 'CTL', 'DPN', 'KML'], $codes);

        $active = app(ActivePromotions::class);
        $this->assertContains($version->id, array_column($active->for(Outlet::where('code', 'KML')->value('id'), 'retail'), 'id'));
        $jakarta = array_column($active->for($this->outlet->id, 'retail'), 'id');
        $this->assertNotContains($version->id, $jakarta);
        $this->assertNotEmpty($jakarta, 'Promo Jakarta tetap berlaku');

        $this->assertDomainError('already_published', fn () => app(PromotionPublisher::class)->publish($published, $admin, ['all' => true]));
    }

    /** U06: syarat berat memakai total berat desimal; di bawah 2 kg tidak mendapat diskon. */
    public function test_u06_weight_condition_uses_decimal_weight(): void
    {
        $order = $this->newOrder();
        $this->orders()->addPack($order, 'PK-000184', $this->cashier); // 1,500 kg
        $this->assertSame('0.00', (string) $order->fresh()->discount_amount);

        $this->orders()->addPack($order, 'PK-000203', $this->cashier); // + 0,985 = 2,485 kg
        $order->refresh();
        $this->assertSame('497000.00', (string) $order->gross_amount);
        $this->assertSame('49700.00', (string) $order->discount_amount);

        // Alokasi diskon tersimpan per baris untuk retur dan posting SAP.
        $this->assertSame('49700.00', \App\Support\Decimal::money(\App\Support\Decimal::sum(
            \App\Models\DiscountAllocation::whereIn('order_line_id', $order->lines()->pluck('id'))->pluck('amount')
        )));
    }
}
