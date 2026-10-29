<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Kitchen\KitchenBoard;
use App\Models\CountSession;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data demo J&M Solo: seeder memutar satu hari operasional lewat service domain, jadi
 * semua layar punya isi yang masuk akal.
 */
class JmSoloDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_day_fills_every_screen(): void
    {
        $this->seed(DatabaseSeeder::class);
        $outlet = Outlet::where('code', 'JMS')->firstOrFail();
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

        // Katalog kasir J&M Solo hanya berisi produk berharga di outlet ini, tanpa bahan dapur.
        $this->actingAs(User::where('email', 'kasir@jms.test')->firstOrFail());
        $catalog = collect($this->getJson('/pos/api/catalog')->assertOk()->json());
        $this->assertTrue($catalog->contains('name', 'Kakap Merah Utuh'));
        $this->assertTrue($catalog->contains('name', 'Selat Solo'));
        $this->assertFalse($catalog->contains('name', 'Sirloin Australia'), 'Produk PJT tidak tampil di J&M Solo');
        $this->assertFalse($catalog->contains('type', 'ingredient'));
        $boot = $this->getJson('/pos/api/bootstrap')->assertOk();
        $this->assertContains('bakar', collect($boot->json('cooking_methods'))->pluck('method')->all());
        $this->assertContains('Ikan Laut', $boot->json('categories'));
    }
}
