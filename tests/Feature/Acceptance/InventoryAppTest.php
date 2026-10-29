<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Inventory\StockLedger;
use App\Models\Pack;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Rasentra Inventory: penerimaan per Pack ID, stok opname blind count dengan hitung ulang
 * oleh petugas berbeda, dan cek stok.
 */
class InventoryAppTest extends AcceptanceTestCase
{
    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_receiving_packs_is_idempotent_and_rejects_to_quarantine(): void
    {
        $sirloin = Product::where('code', '0100101')->firstOrFail();
        $before = StockLedger::balance($this->outlet->id, $sirloin->id);
        $this->actingAs($this->user('gudang@pjt.test'));

        $this->getJson('/inventory/api/receiving')->assertOk()->assertJsonCount(2);
        $this->postJson('/inventory/api/receiving', ['code' => 'PK-000510'])->assertOk()
            ->assertJsonPath('status', 'received')->assertJsonPath('remaining', 1);
        $this->postJson('/inventory/api/receiving', ['code' => 'PK-000510'])->assertStatus(409)->assertJsonPath('error', 'pack_already_received');
        $this->assertSame('1.320000', (string) \App\Support\Decimal::of(StockLedger::balance($this->outlet->id, $sirloin->id))->minus($before)->toScale(6));

        $this->postJson('/inventory/api/receiving', ['code' => 'PK-000511', 'action' => 'reject'])->assertStatus(422)->assertJsonPath('error', 'reason_required');
        $this->postJson('/inventory/api/receiving', ['code' => 'PK-000511', 'action' => 'reject', 'reason' => 'Vakum bocor'])->assertOk()->assertJsonPath('status', 'damaged');
        $tenderloin = Pack::where('pack_code', 'PK-000511')->value('product_id');
        $this->assertSame('0.940000', StockLedger::balance($this->outlet->id, $tenderloin, 'quarantine'));

        // Pack outlet lain ditolak.
        Pack::where('pack_code', 'PK-000203')->update(['status' => 'shipped', 'outlet_id' => \App\Models\Outlet::where('code', 'PSM')->value('id')]);
        $this->postJson('/inventory/api/receiving', ['code' => 'PK-000203'])->assertStatus(409)->assertJsonPath('error', 'pack_other_outlet');

        $this->getJson('/inventory/api/lookup?code=PK-000510')->assertOk()->assertJsonPath('kind', 'pack')
            ->assertJsonPath('pack.status', 'received')->assertJsonPath('events.0.event', 'received');
        $this->getJson('/inventory/api/lookup?code=8991234500011')->assertOk()->assertJsonPath('available', '40.000000');
        $this->getJson('/inventory/api/lookup?code=XYZ')->assertStatus(404);
    }

    public function test_blind_count_recount_by_different_counter_then_approve(): void
    {
        $wings = Product::where('code', '0200201')->firstOrFail();
        $sirloin = Product::where('code', '0100101')->firstOrFail();

        $this->actingAs($this->cashier);
        $this->postJson('/inventory/api/counts', [])->assertStatus(403);

        $this->actingAs($this->user('gudang@pjt.test'));
        $sessionId = $this->postJson('/inventory/api/counts', ['type' => 'cycle'])->assertCreated()->json('id');

        // Penjualan sesudah cutoff tidak memengaruhi saldo pembanding.
        $this->travel(1)->minutes();
        StockLedger::move($this->outlet->id, $wings->id, '-2', 'PCS', 'sale', 'order', '00000000-0000-7000-8000-000000000001');

        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => '8991234500011', 'location' => 'XXX', 'quantity' => 1])->assertStatus(404)->assertJsonPath('error', 'location_unknown');
        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => '8991234500011', 'location' => 'ETL'])->assertStatus(422)->assertJsonPath('error', 'quantity_required');
        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => '8991234500011', 'location' => 'ETL', 'quantity' => 30])->assertCreated();
        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => '8991234500011', 'location' => 'GDG', 'quantity' => 5])->assertCreated();
        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => '0100101', 'location' => 'ETL'])->assertStatus(422)->assertJsonPath('error', 'scan_pack');
        foreach (['PK-000184', 'PK-000191'] as $pack) {
            $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => $pack, 'location' => 'ETL'])->assertCreated();
        }
        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => 'PK-000184', 'location' => 'ETL'])->assertStatus(409)->assertJsonPath('error', 'pack_already_counted');

        // Blind: progres petugas tidak memuat saldo sistem.
        $progress = $this->getJson("/inventory/api/counts/{$sessionId}")->assertOk();
        $this->assertStringNotContainsString('system', $progress->getContent());
        $this->postJson("/inventory/api/counts/{$sessionId}/review")->assertStatus(403);

        // Review kepala outlet: sayap 35 vs 40 dan sirloin kurang 1 pack -> hitung ulang.
        $this->actingAs($this->head);
        $review = $this->postJson("/inventory/api/counts/{$sessionId}/review")->assertOk();
        $review->assertJsonPath('session.status', 'recount')->assertJsonPath('session.current_round', 2);
        $lines = collect($review->json('lines'))->keyBy('code');
        $this->assertSame('-5.000000', $lines['0200201']['variance']);
        $this->assertSame(['PK-000203', 'PK-000999'], $lines['0100101']['missing_packs']);
        $this->postJson("/inventory/api/counts/{$sessionId}/approve")->assertStatus(409)->assertJsonPath('error', 'count_not_reviewed');

        // Hitung ulang oleh petugas yang sama ditolak; petugas lain diterima.
        $this->actingAs($this->user('gudang@pjt.test'));
        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => '8991234500011', 'location' => 'ETL', 'quantity' => 38])
            ->assertStatus(409)->assertJsonPath('error', 'recount_same_counter');
        $this->actingAs($this->user('gudang2@pjt.test'));
        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => '8991234500011', 'location' => 'ETL', 'quantity' => 38])->assertCreated();
        foreach (['PK-000184', 'PK-000191', 'PK-000203', 'PK-000999'] as $pack) {
            $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => $pack, 'location' => 'ETL'])->assertCreated();
        }
        $this->postJson("/inventory/api/counts/{$sessionId}/lines", ['code' => '0300301', 'location' => 'ETL', 'quantity' => 1])
            ->assertStatus(409)->assertJsonPath('error', 'not_in_recount');

        $this->actingAs($this->head);
        $this->postJson("/inventory/api/counts/{$sessionId}/review")->assertOk()->assertJsonPath('session.status', 'review');
        $balanceBefore = StockLedger::balance($this->outlet->id, $wings->id);
        $this->postJson("/inventory/api/counts/{$sessionId}/approve")->assertOk()->assertJsonPath('status', 'approved');

        // Penyesuaian = 38 - 40 = -2; penjualan sesudah cutoff tetap tercatat terpisah.
        $this->assertSame('-2.000000', (string) StockMovement::where('source_type', 'count_session')->where('product_id', $wings->id)->value('quantity'));
        $this->assertSame('36.000000', StockLedger::balance($this->outlet->id, $wings->id));
        $this->assertSame('38.000000', $balanceBefore);
        $this->assertFalse(StockMovement::where('source_type', 'count_session')->where('product_id', $sirloin->id)->exists());
        $this->postJson("/inventory/api/counts/{$sessionId}/approve")->assertStatus(409);
    }

    public function test_storekeeper_pin_login_lands_on_inventory(): void
    {
        $this->post('/pos/login', ['user_id' => $this->user('gudang@pjt.test')->id, 'pin' => '4444'])->assertRedirect('/inventory');
        $this->get('/inventory')->assertOk()->assertSee('Rasentra Inventory')->assertSee('GDG');
    }
}
