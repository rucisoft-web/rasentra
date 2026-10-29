<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Kitchen\KitchenBoard;
use App\Models\CookingFeeRule;
use App\Models\CountSession;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\PriceVersion;
use App\Models\Product;
use App\Models\PromotionVersion;
use App\Models\Recipe;
use App\Models\SapDocument;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data demo Solo: seeder memutar satu hari operasional lewat service domain, jadi
 * semua layar punya isi yang masuk akal.
 */
class IndogunaDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_day_fills_every_screen(): void
    {
        $this->seed(DatabaseSeeder::class);
        $outlet = Outlet::where('code', 'SLO')->firstOrFail();
        $board = app(KitchenBoard::class);

        $this->assertGreaterThanOrEqual(13, Order::where('outlet_id', $outlet->id)->where('channel', 'retail')->where('status', 'paid')->count());
        $this->assertSame(1, Order::where('outlet_id', $outlet->id)->where('status', 'held')->count());
        $this->assertNotEmpty($board->board($outlet->id, 'grill')['tickets']);
        $this->assertNotEmpty($board->board($outlet->id, null)['tickets']);
        $queue = $board->queue($outlet->id);
        $this->assertCount(1, $queue['ready']);
        $this->assertCount(2, $queue['preparing']);
        $this->assertGreaterThan(20, $board->analytics($outlet->id, now()->subDay(), now()->addDay())['overall']['count']);
        $this->assertSame(5, Pack::where('outlet_id', $outlet->id)->where('status', 'shipped')->count());
        $this->assertSame('counting', CountSession::where('outlet_id', $outlet->id)->value('status'));

        // Katalog kasir Solo hanya berisi produk berharga di outlet ini, tanpa bahan dapur.
        $this->actingAs(User::where('email', 'kasir@solo.test')->firstOrFail());
        $catalog = collect($this->getJson('/pos/api/catalog')->assertOk()->json());
        $this->assertTrue($catalog->contains('name', 'Kakap Merah Utuh'));
        $this->assertTrue($catalog->contains('name', 'Selat Solo'));
        $this->assertFalse($catalog->contains('name', 'Sirloin Australia'), 'Produk UJI tidak tampil di Solo');
        $this->assertFalse($catalog->contains('type', 'ingredient'));
        $boot = $this->getJson('/pos/api/bootstrap')->assertOk();
        $this->assertContains('bakar', collect($boot->json('cooking_methods'))->pluck('method')->all());
        $this->assertContains('Ikan Laut', $boot->json('categories'));

        // Rasentra Core: riwayat 7 hari tersinkron, SAP dengan kasus review, dan semua halaman admin terisi.
        $this->assertGreaterThan(300, \DB::table('hq_orders')->count());
        $this->assertSame(1, SapDocument::where('status', 'needs_review')->count());
        $this->assertSame(['draft', 'simulated'], PromotionVersion::whereIn('status', ['draft', 'simulated'])->orderBy('id')->pluck('status')->all());
        $this->actingAs(User::where('email', 'admin@rasentra.test')->firstOrFail());
        $pages = ['core.dashboard', 'core.outlets.index', 'core.users.index', 'core.users.create', 'core.devices.index', 'core.products.index', 'core.products.create',
            'core.prices.index', 'core.promotions.index', 'core.promotions.create', 'core.recipes.index', 'core.cooking-fees.index', 'core.monitor.sync',
            'core.monitor.sap', 'core.monitor.exceptions', 'core.sap-mappings.index', 'core.xml.index', 'core.reports.sales', 'core.reports.promotions',
            'core.reports.kitchen', 'core.reports.stock', 'core.audit'];
        foreach ($pages as $name) {
            $this->get(route($name))->assertOk();
        }
        $this->get(route('core.outlets.edit', $outlet))->assertOk()->assertSee('R&amp;B Solo', false);
        $this->get(route('core.prices.show', PriceVersion::where('code', 'SLO-2026-11')->firstOrFail()))->assertOk();
        $this->get(route('core.promotions.show', PromotionVersion::firstOrFail()))->assertOk();
        $this->get(route('core.recipes.show', Recipe::firstOrFail()))->assertOk();
        $this->get(route('core.cooking-fees.edit', CookingFeeRule::firstOrFail()))->assertOk();
        $this->get(route('core.monitor.sap.show', SapDocument::where('status', 'needs_review')->firstOrFail()))->assertOk();
        $this->get(route('core.products.edit', Product::firstOrFail()))->assertOk();
        $this->get(route('core.users.edit', User::firstOrFail()))->assertOk();
        $this->get(route('core.reports.sales'))->assertSee('CV Lombok Kulina Utama');
    }
}
