<?php

namespace Database\Seeders;

use App\Domain\Inventory\StockLedger;
use App\Models\CookingFeeRule;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\PriceItem;
use App\Models\PriceVersion;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\PromotionVersion;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Uom;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Data contoh untuk mencoba Rasentra POS di outlet Pangkalan Jati. Harga dan resep adalah
 * angka uji dari spesifikasi, bukan harga atau resep operasional yang disahkan.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $outlet = Outlet::where('code', 'PJT')->firstOrFail();
        $outlet->update(['has_restaurant' => true, 'has_cooking_fee' => true, 'sap_warehouse_code' => 'WH-PJT']);

        foreach ([['KG', 'Kilogram', 3], ['PCS', 'Pieces', 0], ['PORSI', 'Porsi', 0], ['LTR', 'Liter', 3]] as [$c, $n, $s]) {
            Uom::firstOrCreate(['code' => $c], ['name' => $n, 'display_scale' => $s]);
        }

        $users = [
            ['Sari (Kasir)', 'kasir@pjt.test', 'cashier', '1111', 0],
            ['Budi (Kepala Outlet)', 'kepala@pjt.test', 'outlet_head', '9999', 5000000],
            ['Rudi (Pelayan)', 'pelayan@pjt.test', 'waiter', '3333', 0],
            ['Chef Andi', 'chef@pjt.test', 'chef', '2222', 0],
            ['Dewi (Gudang)', 'gudang@pjt.test', 'storekeeper', '4444', 0],
            ['Eko (Gudang)', 'gudang2@pjt.test', 'storekeeper', '5555', 0],
        ];
        foreach ($users as [$name, $email, $role, $pin, $limit]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name, 'password' => 'password', 'role' => $role, 'outlet_id' => $outlet->id,
                'pin_hash' => Hash::make($pin), 'approval_limit' => $limit, 'is_active' => true,
            ]);
        }

        $p = fn (string $code, array $attrs) => Product::updateOrCreate(['code' => $code], $attrs);
        $sirloin = $p('0100101', ['name' => 'Sirloin Australia', 'type' => 'weighed', 'uom_code' => 'KG', 'is_weighed' => true, 'category' => 'Daging Sapi']);
        $tenderloin = $p('0100102', ['name' => 'Tenderloin Australia', 'type' => 'weighed', 'uom_code' => 'KG', 'is_weighed' => true, 'category' => 'Daging Sapi']);
        $ribeye = $p('0100103', ['name' => 'Ribeye Wagyu MB5', 'type' => 'weighed', 'uom_code' => 'KG', 'is_weighed' => true, 'category' => 'Daging Sapi']);
        $wings = $p('0200201', ['name' => 'Sayap Ayam 500 g', 'barcode' => '8991234500011', 'type' => 'retail', 'uom_code' => 'PCS', 'category' => 'Unggas']);
        $sausage = $p('0300301', ['name' => 'Sosis Bratwurst 300 g', 'barcode' => '8991234500028', 'type' => 'retail', 'uom_code' => 'PCS', 'category' => 'Olahan']);
        $bbq = $p('0400401', ['name' => 'Saus BBQ Botol', 'barcode' => '8991234500035', 'type' => 'retail', 'uom_code' => 'PCS', 'category' => 'Bumbu & Saus']);
        $potatoRaw = $p('0500501', ['name' => 'Kentang (bahan)', 'type' => 'ingredient', 'uom_code' => 'KG', 'is_weighed' => true]);
        $veg = $p('0500502', ['name' => 'Sayuran (bahan)', 'type' => 'ingredient', 'uom_code' => 'KG', 'is_weighed' => true]);
        $butter = $p('0500503', ['name' => 'Butter (bahan)', 'type' => 'ingredient', 'uom_code' => 'KG', 'is_weighed' => true]);
        $sauce = $p('0600601', ['name' => 'Saus Lada Hitam (prep)', 'type' => 'prep', 'uom_code' => 'KG', 'is_weighed' => true]);
        $steakMeat = $p('0600602', ['name' => 'Daging Steak Siap Masak (prep)', 'type' => 'prep', 'uom_code' => 'KG', 'is_weighed' => true]);
        $steak = $p('0700701', ['name' => 'Sirloin Steak 200 g', 'type' => 'menu', 'uom_code' => 'PORSI', 'is_stocked' => false, 'category' => 'Menu Steak', 'kitchen_station' => 'grill']);
        $fries = $p('0700702', ['name' => 'Kentang Goreng', 'type' => 'menu', 'uom_code' => 'PORSI', 'is_stocked' => false, 'category' => 'Pendamping', 'kitchen_station' => 'hot']);
        $tea = $p('0700703', ['name' => 'Es Teh Tani', 'type' => 'menu', 'uom_code' => 'PORSI', 'is_stocked' => false, 'category' => 'Minuman', 'kitchen_station' => 'bar']);
        $cookFee = $p('0900901', ['name' => 'Jasa Masak (Cooking Fee)', 'type' => 'service', 'uom_code' => 'PCS', 'is_stocked' => false, 'kitchen_station' => 'grill']);

        $pv = PriceVersion::updateOrCreate(['code' => 'HRG-2026-10'], ['outlet_id' => $outlet->id, 'effective_from' => now()->subDay(), 'status' => 'published']);
        foreach ([[$sirloin, 200000], [$tenderloin, 320000], [$ribeye, 650000], [$wings, 32000], [$sausage, 45000], [$bbq, 28000],
            [$steak, 135000], [$fries, 25000], [$tea, 12000]] as [$prod, $price]) {
            PriceItem::updateOrCreate(['price_version_id' => $pv->id, 'product_id' => $prod->id], ['unit_price' => $price]);
        }

        $packs = [['PK-000184', $sirloin, '1.500'], ['PK-000191', $sirloin, '1.750'], ['PK-000203', $sirloin, '0.985'],
            ['PK-000310', $tenderloin, '1.240'], ['PK-000311', $tenderloin, '0.860'], ['PK-000402', $ribeye, '0.620'], ['PK-000999', $sirloin, '1.100']];
        foreach ($packs as [$code, $prod, $weight]) {
            $pack = Pack::firstOrCreate(['pack_code' => $code], [
                'product_id' => $prod->id, 'net_weight' => $weight, 'uom_code' => 'KG', 'weigh_source' => 'SCALE-GDG-01',
                'packed_at' => now()->subDays(2), 'expires_at' => now()->addDays(5)->toDateString(),
                'location_type' => 'outlet', 'outlet_id' => $outlet->id, 'status' => $code === 'PK-000999' ? 'hold' : 'received',
            ]);
            if ($pack->wasRecentlyCreated) {
                StockLedger::move($outlet->id, $prod->id, $weight, 'KG', 'opening_balance', 'opening', $pack->id, packId: $pack->id);
            }
        }
        // Kiriman gudang pusat yang masih dalam perjalanan: diterima lewat Rasentra Inventory.
        foreach ([['PK-000510', $sirloin, '1.320'], ['PK-000511', $tenderloin, '0.940']] as [$code, $prod, $weight]) {
            Pack::firstOrCreate(['pack_code' => $code], [
                'product_id' => $prod->id, 'net_weight' => $weight, 'uom_code' => 'KG', 'weigh_source' => 'SCALE-GDG-01',
                'packed_at' => now()->subDay(), 'expires_at' => now()->addDays(6)->toDateString(),
                'location_type' => 'transit', 'outlet_id' => $outlet->id, 'status' => 'shipped',
            ]);
        }
        foreach ([[$wings, 40], [$sausage, 30], [$bbq, 24], [$potatoRaw, 25], [$veg, 10], [$butter, 3], [$sauce, 4], [$steakMeat, 8]] as $i => [$prod, $qty]) {
            if (! \App\Models\StockMovement::where('source_type', 'opening')->where('product_id', $prod->id)->where('outlet_id', $outlet->id)->exists()) {
                StockLedger::move($outlet->id, $prod->id, (string) $qty, $prod->uom_code, 'opening_balance', 'opening', '00000000-0000-7000-8000-'.str_pad((string) $prod->id, 12, '0', STR_PAD_LEFT));
            }
        }

        // Resep ilustrasi spesifikasi Bab 12: 1 porsi steak.
        $recipe = Recipe::firstOrCreate(['product_id' => $steak->id], ['name' => 'Sirloin Steak 200 g']);
        $rv = RecipeVersion::firstOrCreate(['recipe_id' => $recipe->id, 'version' => 1], ['yield_quantity' => 1, 'yield_uom' => 'PORSI', 'kind' => 'menu', 'status' => 'approved', 'effective_from' => now()->subDay()]);
        if ($rv->lines()->count() === 0) {
            foreach ([[$steakMeat, '0.200'], [$potatoRaw, '0.150'], [$sauce, '0.050'], [$veg, '0.080'], [$butter, '0.010']] as [$c, $q]) {
                $rv->lines()->create(['component_product_id' => $c->id, 'quantity' => $q, 'uom_code' => 'KG']);
            }
        }
        $friesRecipe = Recipe::firstOrCreate(['product_id' => $fries->id], ['name' => 'Kentang Goreng']);
        $frv = RecipeVersion::firstOrCreate(['recipe_id' => $friesRecipe->id, 'version' => 1], ['yield_quantity' => 1, 'kind' => 'menu', 'status' => 'approved']);
        if ($frv->lines()->count() === 0) {
            $frv->lines()->create(['component_product_id' => $potatoRaw->id, 'quantity' => '0.150', 'uom_code' => 'KG']);
        }

        // Resep jasa masak: hanya bahan tambahan, tidak memotong daging pelanggan.
        $svcRecipe = Recipe::firstOrCreate(['product_id' => $cookFee->id], ['name' => 'Bahan jasa masak per kg']);
        $svc = RecipeVersion::firstOrCreate(['recipe_id' => $svcRecipe->id, 'version' => 1], ['yield_quantity' => 1, 'yield_uom' => 'PCS', 'kind' => 'cooking_service', 'status' => 'approved']);
        if ($svc->lines()->count() === 0) {
            $svc->lines()->create(['component_product_id' => $butter->id, 'quantity' => '0.020', 'uom_code' => 'KG']);
            $svc->lines()->create(['component_product_id' => $sauce->id, 'quantity' => '0.050', 'uom_code' => 'KG']);
        }
        CookingFeeRule::updateOrCreate(['code' => 'CF-KG-STD'], [
            'basis' => 'per_kg', 'rate' => 40000, 'minimum_fee' => 30000, 'service_product_id' => $cookFee->id,
            'recipe_version_id' => $svc->id, 'effective_from' => now()->subDay(), 'included_items' => ['butter', 'saus lada hitam'],
        ]);

        foreach (['A1', 'A2', 'A3', 'B1', 'B2', 'VIP'] as $t) {
            DiningTable::firstOrCreate(['outlet_id' => $outlet->id, 'code' => $t], ['area' => str_starts_with($t, 'A') ? 'Indoor' : 'Teras', 'capacity' => $t === 'VIP' ? 10 : 4]);
        }

        // Promo contoh: diskon 10% daging sapi untuk pembelian total minimal 2 kg di Jakarta.
        $promo = Promotion::firstOrCreate(['code' => 'JKT-SAPI-2KG'], ['name' => 'Hemat Daging Sapi 2 kg']);
        $version = PromotionVersion::firstOrCreate(['promotion_id' => $promo->id, 'version' => 1], [
            'type' => 'weight_tier', 'scope' => 'item', 'stacking' => 'exclusive', 'priority' => 10,
            'rules' => ['categories' => ['Daging Sapi'], 'tiers' => [['min_weight' => '2.000', 'percent' => 10]]],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'published', 'published_at' => now(),
        ]);
        foreach (Outlet::whereHas('region', fn ($q) => $q->where('code', 'JKT'))->get() as $o) {
            PromotionTarget::firstOrCreate(['promotion_version_id' => $version->id, 'outlet_id' => $o->id]);
        }
    }
}
