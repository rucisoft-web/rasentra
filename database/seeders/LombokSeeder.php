<?php

namespace Database\Seeders;

use App\Models\CookingFeeRule;
use App\Models\DiningTable;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\PriceItem;
use App\Models\PriceVersion;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\PromotionVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Outlet demo Lombok (CV Lombok Kulina Utama, instance sap_lombok, zona waktu WITA):
 * Meatshop di depan dan restoran El Primo. Katalog dan resep sama dengan Solo; harga,
 * promo, meja, cooking fee dan petugas milik outlet ini sendiri. Riwayat penjualannya
 * dibuat oleh CoreSeeder.
 */
class LombokSeeder extends Seeder
{
    public function run(): void
    {
        $outlet = Outlet::where('code', 'LBK')->first();
        $solo = Outlet::where('code', 'SLO')->first();
        if (! $outlet || ! $solo || User::where('email', 'kasir@lombok.test')->exists()) {
            return;
        }
        $now = Carbon::now();

        foreach ([['ETL', 'Etalase daging', 'sales'], ['ESF', 'Etalase es ikan laut', 'sales'], ['RAK', 'Rak grocery', 'sales'],
            ['CHL', 'Chiller belakang', 'storage'], ['FRZ', 'Freezer -18°C', 'storage'], ['DPR', 'Dapur El Primo', 'kitchen'], ['BAR', 'Bar El Primo', 'kitchen']] as [$c, $n, $t]) {
            Location::updateOrCreate(['outlet_id' => $outlet->id, 'code' => $c], ['name' => $n, 'type' => $t]);
        }

        foreach ([
            'kasir' => ['Baiq Nurul (Kasir Meatshop)', 'cashier', '1111', 0],
            'kasir2' => ['Lalu Ahmad (Kasir El Primo)', 'cashier', '1212', 0],
            'kepala' => ['Made Sudarsana (Kepala Outlet)', 'outlet_head', '9999', 5000000],
            'pelayan' => ['Wayan (Pelayan)', 'waiter', '3333', 0],
            'chef' => ['Chef Rizal', 'chef', '2222', 0],
            'gudang' => ['Hamdi (Gudang)', 'storekeeper', '4444', 0],
        ] as $key => [$name, $role, $pin, $limit]) {
            User::create([
                'name' => $name, 'email' => $key.'@lombok.test', 'password' => Hash::make('password'), 'role' => $role,
                'outlet_id' => $outlet->id, 'pin_hash' => Hash::make($pin), 'approval_limit' => $limit, 'is_active' => true,
            ]);
        }

        // Harga Lombok: harga Solo plus 5% ongkos kirim antarpulau, dibulatkan ke Rp500.
        $base = PriceVersion::where('outlet_id', $solo->id)->where('status', 'published')->latest('effective_from')->firstOrFail();
        $pv = PriceVersion::create(['code' => 'LBK-2026-10', 'outlet_id' => $outlet->id, 'effective_from' => $now->copy()->subDays(7), 'status' => 'published', 'note' => 'Harga awal Lombok']);
        foreach ($base->items as $item) {
            PriceItem::create(['price_version_id' => $pv->id, 'product_id' => $item->product_id, 'unit_price' => (int) (ceil((float) $item->unit_price * 1.05 / 500) * 500)]);
        }

        foreach ([['E1', 'Indoor', 4], ['E2', 'Indoor', 4], ['E3', 'Indoor', 6], ['E4', 'Indoor', 2], ['T1', 'Teras', 4], ['T2', 'Teras', 4], ['T3', 'Teras', 6], ['VIP', 'Ruang VIP', 10]] as [$code, $area, $cap]) {
            DiningTable::create(['outlet_id' => $outlet->id, 'code' => $code, 'area' => $area, 'capacity' => $cap]);
        }

        foreach (CookingFeeRule::where('outlet_id', $solo->id)->get() as $rule) {
            $copy = $rule->replicate();
            $copy->fill(['code' => str_replace('SLO-', 'LBK-', $rule->code), 'outlet_id' => $outlet->id, 'effective_from' => $now->copy()->subDays(7)])->save();
        }

        foreach ([
            ['LBK-SEAFOOD-10', 'Seafood segar Meatshop Lombok diskon 10%', 'percent', 20, ['categories' => ['Ikan Laut'], 'percent' => 10], ['retail']],
            ['LBK-STEAK-ELPRIMO', 'Steak El Primo dine-in hemat 15%', 'percent', 15, ['categories' => ['Steak'], 'percent' => 15, 'max_discount' => 50000], ['dine_in']],
        ] as [$code, $name, $type, $priority, $rules, $channels]) {
            $promo = Promotion::create(['code' => $code, 'name' => $name]);
            $version = PromotionVersion::create([
                'promotion_id' => $promo->id, 'version' => 1, 'type' => $type, 'scope' => 'item', 'rules' => $rules, 'channels' => $channels,
                'priority' => $priority, 'stacking' => 'exclusive', 'starts_at' => $now->copy()->subDays(10), 'ends_at' => $now->copy()->addDays(30),
                'status' => 'published', 'published_at' => $now->copy()->subDays(10),
            ]);
            PromotionTarget::create(['promotion_version_id' => $version->id, 'outlet_id' => $outlet->id, 'ack_status' => 'applied', 'acked_at' => $now->copy()->subDays(10)]);
        }
    }
}
