<?php

namespace Database\Seeders;

use App\Domain\Cash\ShiftService;
use App\Domain\CookingService\CookingService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Kitchen\KitchenService;
use App\Domain\Recipe\RecipeService;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\PaymentService;
use App\Models\CookingFeeRule;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Location;
use App\Models\Order;
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
use App\Models\Shift;
use App\Models\TicketItem;
use App\Models\Uom;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Outlet demo Solo (CV Solo Kulina Utama): Meatshop & Grocery (daging, ikan laut dan aneka
 * makanan) di depan, restoran R&B Solo (grill & seafood) di belakang. Selain master (produk, harga, resep, promo, cooking fee, meja,
 * petugas), seeder ini memutar satu hari operasional lewat service domain yang sama
 * dengan aplikasi: penjualan retail, pesanan resto yang sudah selesai dan yang sedang
 * dimasak, antrean takeaway, kiriman gudang pusat dan sesi opname. Semua harga, resep
 * dan nama petugas adalah data contoh.
 */
class SoloSeeder extends Seeder
{
    private Outlet $outlet;

    private Carbon $now;

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, User> */
    private array $users = [];

    public function run(): void
    {
        if (User::where('email', 'kasir@solo.test')->exists()) {
            return; // data demo hanya dibuat sekali; jalankan migrate:fresh --seed untuk mengulang
        }

        $this->now = Carbon::now();
        $this->master();
        $this->simulateDay();
        Carbon::setTestNow();
    }

    // ------------------------------------------------------------------ master

    private function master(): void
    {
        $this->outlet = Outlet::where('code', 'SLO')->firstOrFail();
        foreach ([['ETL', 'Etalase daging', 'sales'], ['ESF', 'Etalase es ikan laut', 'sales'], ['RAK', 'Rak makanan & bumbu', 'sales'],
            ['CHL', 'Chiller belakang', 'storage'], ['FRZ', 'Freezer -18°C', 'storage'], ['GDG', 'Gudang kering', 'storage'],
            ['DPR', 'Dapur resto', 'kitchen'], ['BAR', 'Bar minuman', 'kitchen'], ['KRT', 'Karantina', 'quarantine']] as [$c, $n, $t]) {
            Location::updateOrCreate(['outlet_id' => $this->outlet->id, 'code' => $c], ['name' => $n, 'type' => $t]);
        }
        Location::where('outlet_id', $this->outlet->id)->where('code', 'ETL')->update(['name' => 'Etalase daging']);

        foreach ([['KG', 'Kilogram', 3], ['PCS', 'Pieces', 0], ['PORSI', 'Porsi', 0], ['LTR', 'Liter', 3], ['PAK', 'Pak', 0], ['BTL', 'Botol', 0]] as [$c, $n, $s]) {
            Uom::firstOrCreate(['code' => $c], ['name' => $n, 'display_scale' => $s]);
        }

        $this->users();
        $this->catalog();
        $this->recipes();
        $this->cookingFees();
        $this->tables();
        $this->promotions();
        $this->openingStock();
    }

    private function users(): void
    {
        $list = [
            'kasir' => ['Rina (Kasir Retail)', 'cashier', '1111', 0],
            'kasir2' => ['Tono (Kasir Resto)', 'cashier', '1212', 0],
            'kepala' => ['Hendra (Kepala Outlet)', 'outlet_head', '9999', 5000000],
            'wakil' => ['Maya (Asisten Kepala)', 'outlet_head', '8888', 1000000],
            'pelayan' => ['Lusi (Pelayan)', 'waiter', '3333', 0],
            'pelayan2' => ['Agus (Pelayan)', 'waiter', '3434', 0],
            'chef' => ['Chef Bambang', 'chef', '2222', 0],
            'cook' => ['Sri (Cook Hot Kitchen)', 'chef', '2323', 0],
            'barista' => ['Dimas (Bar)', 'kitchen', '2424', 0],
            'gudang' => ['Wawan (Gudang)', 'storekeeper', '4444', 0],
            'gudang2' => ['Yuni (Gudang)', 'storekeeper', '5555', 0],
        ];
        foreach ($list as $key => [$name, $role, $pin, $limit]) {
            $this->users[$key] = User::create([
                'name' => $name, 'email' => $key.'@solo.test', 'password' => Hash::make('password'), 'role' => $role,
                'outlet_id' => $this->outlet->id, 'pin_hash' => Hash::make($pin), 'approval_limit' => $limit, 'is_active' => true,
            ]);
        }
    }

    private function catalog(): void
    {
        // [kode, nama, tipe, uom, kategori, harga, opsi]
        $items = [
            // Daging sapi, kambing, unggas: dijual per Pack ID dengan berat aktual.
            ['2101001', 'Sirloin Lokal', 'weighed', 'KG', 'Daging Sapi', 165000],
            ['2101002', 'Tenderloin Australia', 'weighed', 'KG', 'Daging Sapi', 335000],
            ['2101003', 'Ribeye Australia', 'weighed', 'KG', 'Daging Sapi', 295000],
            ['2101004', 'Wagyu Striploin MB5', 'weighed', 'KG', 'Daging Sapi', 780000],
            ['2101005', 'Iga Sapi Potong', 'weighed', 'KG', 'Daging Sapi', 145000],
            ['2101006', 'Buntut Sapi', 'weighed', 'KG', 'Daging Sapi', 175000],
            ['2101007', 'Daging Giling Premium 500 g', 'retail', 'PCS', 'Daging Sapi', 72000, ['barcode' => '8992101007005']],
            ['2102001', 'Iga Kambing', 'weighed', 'KG', 'Daging Kambing', 155000],
            ['2102002', 'Daging Kambing Has', 'weighed', 'KG', 'Daging Kambing', 168000],
            ['2103001', 'Ayam Kampung Utuh', 'weighed', 'KG', 'Unggas', 78000],
            ['2103002', 'Dada Ayam Fillet 500 g', 'retail', 'PCS', 'Unggas', 38000, ['barcode' => '8992103002008']],
            ['2103003', 'Sayap Ayam 500 g', 'retail', 'PCS', 'Unggas', 31000, ['barcode' => '8992103003005']],
            // Ikan laut segar: ditimbang dan diberi Pack ID di etalase es.
            ['2201001', 'Kakap Merah Utuh', 'weighed', 'KG', 'Ikan Laut', 115000],
            ['2201002', 'Kerapu Macan Hidup', 'weighed', 'KG', 'Ikan Laut', 245000],
            ['2201003', 'Bawal Bintang', 'weighed', 'KG', 'Ikan Laut', 135000],
            ['2201004', 'Salmon Fillet Norwegia', 'weighed', 'KG', 'Ikan Laut', 420000],
            ['2201005', 'Tuna Saku Sashimi', 'weighed', 'KG', 'Ikan Laut', 285000],
            ['2201006', 'Udang Vaname Segar', 'weighed', 'KG', 'Ikan Laut', 98000],
            ['2201007', 'Cumi-cumi Segar', 'weighed', 'KG', 'Ikan Laut', 88000],
            ['2201008', 'Kepiting Bakau', 'weighed', 'KG', 'Ikan Laut', 210000],
            ['2201009', 'Kerang Hijau', 'weighed', 'KG', 'Ikan Laut', 38000],
            // Seafood beku kemasan.
            ['2202001', 'Udang Kupas Beku 500 g', 'retail', 'PCS', 'Seafood Beku', 69000, ['barcode' => '8992202001006']],
            ['2202002', 'Fillet Ikan Dori 1 kg', 'retail', 'PCS', 'Seafood Beku', 58000, ['barcode' => '8992202002003']],
            ['2202003', 'Bakso Ikan Tenggiri 500 g', 'retail', 'PCS', 'Seafood Beku', 42000, ['barcode' => '8992202003000']],
            ['2202004', 'Otak-otak Bandeng 10 pcs', 'retail', 'PCS', 'Seafood Beku', 35000, ['barcode' => '8992202004007']],
            // Aneka makanan olahan, bumbu dan oleh-oleh Solo.
            ['2301001', 'Sosis Bratwurst 300 g', 'retail', 'PCS', 'Olahan', 45000, ['barcode' => '8992301001009']],
            ['2301002', 'Smoked Beef 250 g', 'retail', 'PCS', 'Olahan', 52000, ['barcode' => '8992301002006']],
            ['2301003', 'Nugget Ayam 500 g', 'retail', 'PCS', 'Olahan', 39000, ['barcode' => '8992301003003']],
            ['2301004', 'Kentang Shoestring 1 kg', 'retail', 'PCS', 'Olahan', 52000, ['barcode' => '8992301004000']],
            ['2301005', 'Keju Mozzarella 250 g', 'retail', 'PCS', 'Olahan', 48000, ['barcode' => '8992301005007']],
            ['2302001', 'Saus Lada Hitam Botol', 'retail', 'PCS', 'Bumbu & Saus', 29000, ['barcode' => '8992302001008']],
            ['2302002', 'Saus BBQ Botol', 'retail', 'PCS', 'Bumbu & Saus', 28000, ['barcode' => '8992302002005']],
            ['2302003', 'Bumbu Rendang Instan', 'retail', 'PCS', 'Bumbu & Saus', 15000, ['barcode' => '8992302003002']],
            ['2302004', 'Sambal Bawang Botol', 'retail', 'PCS', 'Bumbu & Saus', 27000, ['barcode' => '8992302004009']],
            ['2303001', 'Beras Rojolele Delanggu 5 kg', 'retail', 'PCS', 'Sembako', 89000, ['barcode' => '8992303001001']],
            ['2303002', 'Minyak Goreng 2 L', 'retail', 'PCS', 'Sembako', 38000, ['barcode' => '8992303002008']],
            ['2303003', 'Telur Ayam 1 Tray (30)', 'retail', 'PCS', 'Sembako', 58000, ['barcode' => '8992303003005']],
            ['2304001', 'Abon Sapi Solo 250 g', 'retail', 'PCS', 'Oleh-oleh Solo', 65000, ['barcode' => '8992304001004']],
            ['2304002', 'Serundeng Kelapa 200 g', 'retail', 'PCS', 'Oleh-oleh Solo', 25000, ['barcode' => '8992304002001']],
            ['2304003', 'Intip Goreng Solo', 'retail', 'PCS', 'Oleh-oleh Solo', 22000, ['barcode' => '8992304003008']],
            ['2304004', 'Kerupuk Udang 250 g', 'retail', 'PCS', 'Oleh-oleh Solo', 32000, ['barcode' => '8992304004005']],
            ['2305001', 'Air Mineral 600 ml', 'retail', 'PCS', 'Minuman Kemasan', 5000, ['barcode' => '8992305001003']],
            ['2305002', 'Teh Botol 450 ml', 'retail', 'PCS', 'Minuman Kemasan', 7500, ['barcode' => '8992305002000']],

            // Menu restoran R&B Solo (grill & seafood) dengan station dapur.
            ['2501001', 'Sirloin Steak 200 g', 'menu', 'PORSI', 'Steak', 128000, ['kitchen_station' => 'grill']],
            ['2501002', 'Tenderloin Steak 200 g', 'menu', 'PORSI', 'Steak', 189000, ['kitchen_station' => 'grill']],
            ['2501003', 'Ribeye Steak 250 g', 'menu', 'PORSI', 'Steak', 215000, ['kitchen_station' => 'grill']],
            ['2501004', 'Wagyu Striploin MB5 200 g', 'menu', 'PORSI', 'Steak', 395000, ['kitchen_station' => 'grill']],
            ['2502001', 'Iga Bakar Madu', 'menu', 'PORSI', 'Bakaran', 98000, ['kitchen_station' => 'grill']],
            ['2502002', 'Sate Kambing Solo (10 tusuk)', 'menu', 'PORSI', 'Bakaran', 75000, ['kitchen_station' => 'grill']],
            ['2502003', 'Kakap Bakar Jimbaran', 'menu', 'PORSI', 'Seafood', 135000, ['kitchen_station' => 'grill']],
            ['2502004', 'Cumi Bakar Kecap', 'menu', 'PORSI', 'Seafood', 72000, ['kitchen_station' => 'grill']],
            ['2503001', 'Udang Saus Padang', 'menu', 'PORSI', 'Seafood', 89000, ['kitchen_station' => 'hot']],
            ['2503002', 'Cumi Goreng Tepung', 'menu', 'PORSI', 'Seafood', 65000, ['kitchen_station' => 'hot']],
            ['2503003', 'Nasi Goreng Seafood', 'menu', 'PORSI', 'Nasi & Mie', 58000, ['kitchen_station' => 'hot']],
            ['2503004', 'Selat Solo', 'menu', 'PORSI', 'Khas Solo', 55000, ['kitchen_station' => 'hot']],
            ['2503005', 'Tengkleng Kambing', 'menu', 'PORSI', 'Khas Solo', 68000, ['kitchen_station' => 'hot']],
            ['2503006', 'Sop Buntut', 'menu', 'PORSI', 'Khas Solo', 85000, ['kitchen_station' => 'hot']],
            ['2503007', 'Nasi Liwet Solo', 'menu', 'PORSI', 'Nasi & Mie', 38000, ['kitchen_station' => 'hot']],
            ['2504001', 'Kentang Goreng', 'menu', 'PORSI', 'Pendamping', 28000, ['kitchen_station' => 'hot']],
            ['2504002', 'Mashed Potato', 'menu', 'PORSI', 'Pendamping', 25000, ['kitchen_station' => 'hot']],
            ['2504003', 'Nasi Putih', 'menu', 'PORSI', 'Pendamping', 10000, ['kitchen_station' => 'hot']],
            ['2504004', 'Caesar Salad', 'menu', 'PORSI', 'Pendamping', 45000, ['kitchen_station' => 'cold']],
            ['2505001', 'Es Teh Manis', 'menu', 'PORSI', 'Minuman', 10000, ['kitchen_station' => 'bar']],
            ['2505002', 'Wedang Uwuh', 'menu', 'PORSI', 'Minuman', 18000, ['kitchen_station' => 'bar']],
            ['2505003', 'Es Jeruk Peras', 'menu', 'PORSI', 'Minuman', 18000, ['kitchen_station' => 'bar']],
            ['2505004', 'Jus Alpukat', 'menu', 'PORSI', 'Minuman', 25000, ['kitchen_station' => 'bar']],
            ['2505005', 'Kopi Susu Gula Aren', 'menu', 'PORSI', 'Minuman', 24000, ['kitchen_station' => 'bar']],
            ['2506001', 'Serabi Notosuman', 'menu', 'PORSI', 'Dessert', 22000, ['kitchen_station' => 'pastry']],
            ['2506002', 'Pisang Bakar Keju', 'menu', 'PORSI', 'Dessert', 32000, ['kitchen_station' => 'pastry']],
            ['2506003', 'Es Krim Vanila', 'menu', 'PORSI', 'Dessert', 25000, ['kitchen_station' => 'pastry']],

            // Jasa cooking fee: pilih daging/ikan di etalase, dimasak di resto.
            ['2901001', 'Jasa Masak Steak (Cooking Fee)', 'service', 'PCS', null, null, ['kitchen_station' => 'grill', 'is_stocked' => false]],
            ['2901002', 'Jasa Bakar Seafood (Cooking Fee)', 'service', 'PCS', null, null, ['kitchen_station' => 'grill', 'is_stocked' => false]],
            ['2901003', 'Jasa Masak Seafood (Cooking Fee)', 'service', 'PCS', null, null, ['kitchen_station' => 'hot', 'is_stocked' => false]],
        ];

        $pv = PriceVersion::create(['code' => 'SLO-2026-10', 'outlet_id' => $this->outlet->id, 'effective_from' => $this->now->copy()->subDays(7), 'status' => 'published']);
        foreach ($items as $row) {
            [$code, $name, $type, $uom, $category, $price] = $row;
            $opts = $row[6] ?? [];
            $product = Product::create($opts + [
                'code' => $code, 'name' => $name, 'type' => $type, 'uom_code' => $uom, 'category' => $category,
                'is_weighed' => $type === 'weighed', 'is_stocked' => ! in_array($type, ['menu', 'service'], true),
            ]);
            $this->products[$code] = $product;
            if ($price !== null) {
                PriceItem::create(['price_version_id' => $pv->id, 'product_id' => $product->id, 'unit_price' => $price]);
            }
        }

        // Bahan dapur (tidak dijual, tidak tampil di katalog kasir).
        foreach ([
            ['2801001', 'Bahan Sirloin Portion', 'KG'], ['2801002', 'Bahan Tenderloin Portion', 'KG'], ['2801003', 'Bahan Ribeye Portion', 'KG'],
            ['2801004', 'Bahan Wagyu Striploin', 'KG'], ['2801005', 'Bahan Iga Sapi', 'KG'], ['2801006', 'Bahan Daging Kambing', 'KG'],
            ['2801007', 'Bahan Buntut Sapi', 'KG'], ['2801008', 'Bahan Kakap Fillet', 'KG'], ['2801009', 'Bahan Cumi', 'KG'],
            ['2801010', 'Bahan Udang Kupas', 'KG'], ['2801011', 'Bahan Daging Has Selat', 'KG'],
            ['2802001', 'Kentang', 'KG'], ['2802002', 'Sayuran Campur', 'KG'], ['2802003', 'Beras', 'KG'], ['2802004', 'Selada Romaine', 'KG'],
            ['2802005', 'Jeruk Peras', 'KG'], ['2802006', 'Alpukat', 'KG'], ['2802007', 'Pisang Kepok', 'KG'],
            ['2803001', 'Butter', 'KG'], ['2803002', 'Minyak Goreng Dapur', 'LTR'], ['2803003', 'Tepung Bumbu', 'KG'], ['2803004', 'Kecap Manis', 'LTR'],
            ['2803005', 'Bumbu Jimbaran', 'KG'], ['2803006', 'Bumbu Tengkleng', 'KG'], ['2803007', 'Madu', 'KG'], ['2803008', 'Keju Cheddar', 'KG'],
            ['2803009', 'Gula Aren', 'KG'], ['2803010', 'Teh Tubruk', 'KG'], ['2803011', 'Rempah Wedang Uwuh', 'PAK'], ['2803012', 'Kopi Robusta', 'KG'],
            ['2803013', 'Susu Segar', 'LTR'], ['2803014', 'Santan', 'LTR'], ['2803015', 'Tepung Beras', 'KG'], ['2803016', 'Es Krim Vanila Bulk', 'LTR'],
            ['2803017', 'Lada Hitam', 'KG'], ['2803018', 'Kaldu Sapi', 'LTR'], ['2803019', 'Cabai & Bawang', 'KG'], ['2803020', 'Telur Dapur', 'PCS'],
        ] as [$code, $name, $uom]) {
            $this->products[$code] = Product::create(['code' => $code, 'name' => $name, 'type' => 'ingredient', 'uom_code' => $uom, 'is_weighed' => false, 'is_stocked' => true]);
        }
        // Subresep: saus lada hitam diproduksi batch (stocked_prep), saus padang dibuat per porsi (virtual_prep).
        $this->products['2804001'] = Product::create(['code' => '2804001', 'name' => 'Saus Lada Hitam (produksi)', 'type' => 'prep', 'uom_code' => 'KG', 'is_stocked' => true]);
        $this->products['2804002'] = Product::create(['code' => '2804002', 'name' => 'Saus Padang (virtual)', 'type' => 'prep', 'uom_code' => 'KG', 'is_stocked' => false]);
    }

    private function recipes(): void
    {
        $r = fn (string $code, array $lines, string $kind = 'menu', string $yield = '1', string $uom = 'PORSI') => $this->recipe($code, $lines, $kind, $yield, $uom);

        $r('2804001', [['2803001', '0.200'], ['2803017', '0.080'], ['2803018', '1.500'], ['2803019', '0.300']], 'stocked_prep', '2', 'KG');
        $r('2804002', [['2803019', '0.600'], ['2803014', '0.300'], ['2803002', '0.100']], 'virtual_prep', '1', 'KG');

        $steakSide = [['2802001', '0.150'], ['2802002', '0.080'], ['2804001', '0.050'], ['2803001', '0.010']];
        $r('2501001', array_merge([['2801001', '0.200']], $steakSide));
        $r('2501002', array_merge([['2801002', '0.200']], $steakSide));
        $r('2501003', array_merge([['2801003', '0.250']], $steakSide));
        $r('2501004', array_merge([['2801004', '0.200']], $steakSide, [['2803001', '0.010', 'extra_butter']]));
        $r('2502001', [['2801005', '0.400'], ['2803007', '0.030'], ['2803004', '0.030'], ['2802003', '0.150']]);
        $r('2502002', [['2801006', '0.250'], ['2803004', '0.040'], ['2803019', '0.030']]);
        $r('2502003', [['2801008', '0.400'], ['2803005', '0.050'], ['2802003', '0.150']]);
        $r('2502004', [['2801009', '0.250'], ['2803004', '0.040']]);
        $r('2503001', [['2801010', '0.200'], ['2804002', '0.080'], ['2802003', '0.150']]);
        $r('2503002', [['2801009', '0.200'], ['2803003', '0.060'], ['2803002', '0.100']]);
        $r('2503003', [['2802003', '0.200'], ['2801010', '0.060'], ['2801009', '0.050'], ['2803020', '1'], ['2803002', '0.030']]);
        $r('2503004', [['2801011', '0.120'], ['2802002', '0.100'], ['2802001', '0.100'], ['2803004', '0.030'], ['2803020', '1']]);
        $r('2503005', [['2801006', '0.300'], ['2803006', '0.060'], ['2803014', '0.150']]);
        $r('2503006', [['2801007', '0.350'], ['2802002', '0.100'], ['2803018', '0.400']]);
        $r('2503007', [['2802003', '0.180'], ['2803014', '0.100'], ['2803020', '1']]);
        $r('2504001', [['2802001', '0.180'], ['2803002', '0.050']]);
        $r('2504002', [['2802001', '0.150'], ['2803001', '0.020'], ['2803013', '0.050']]);
        $r('2504003', [['2802003', '0.150']]);
        $r('2504004', [['2802004', '0.120'], ['2803008', '0.020'], ['2803020', '1']]);
        $r('2505001', [['2803010', '0.005'], ['2803009', '0.025']]);
        $r('2505002', [['2803011', '1']]);
        $r('2505003', [['2802005', '0.250'], ['2803009', '0.020']]);
        $r('2505004', [['2802006', '0.200'], ['2803013', '0.100'], ['2803009', '0.020']]);
        $r('2505005', [['2803012', '0.018'], ['2803013', '0.150'], ['2803009', '0.025']]);
        $r('2506001', [['2803015', '0.080'], ['2803014', '0.100']]);
        $r('2506002', [['2802007', '0.200'], ['2803008', '0.030'], ['2803001', '0.010']]);
        $r('2506003', [['2803016', '0.150']]);

        // Resep jasa cooking fee: hanya bahan tambahan, daging/ikan pelanggan tidak dipotong lagi.
        $r('2901001', [['2803001', '0.020'], ['2804001', '0.050']], 'cooking_service', '1', 'PCS');
        $r('2901002', [['2803005', '0.060'], ['2803004', '0.030']], 'cooking_service', '1', 'PCS');
        $r('2901003', [['2804002', '0.080'], ['2803002', '0.080'], ['2803003', '0.050']], 'cooking_service', '1', 'PCS');
    }

    private function recipe(string $code, array $lines, string $kind, string $yield, string $uom): RecipeVersion
    {
        $product = $this->products[$code];
        $recipe = Recipe::create(['product_id' => $product->id, 'name' => $product->name]);
        $version = RecipeVersion::create([
            'recipe_id' => $recipe->id, 'version' => 1, 'yield_quantity' => $yield, 'yield_uom' => $uom, 'kind' => $kind,
            'status' => 'approved', 'approved_by' => $this->users['chef']->id, 'effective_from' => $this->now->copy()->subDays(30),
        ]);
        foreach ($lines as $line) {
            $component = $this->products[$line[0]];
            $version->lines()->create([
                'component_product_id' => $component->id, 'quantity' => $line[1], 'uom_code' => $component->uom_code, 'modifier_key' => $line[2] ?? null,
            ]);
        }

        return $version;
    }

    private function cookingFees(): void
    {
        $svc = fn (string $code) => RecipeVersion::whereHas('recipe', fn ($q) => $q->where('product_id', $this->products[$code]->id))->value('id');
        $base = ['outlet_id' => $this->outlet->id, 'effective_from' => $this->now->copy()->subDays(7)];

        // Tanpa metode khusus: steak dari daging etalase, per kg berat mentah.
        CookingFeeRule::create($base + ['code' => 'SLO-CF-STEAK', 'method' => null, 'basis' => 'per_kg', 'rate' => 45000, 'minimum_fee' => 35000,
            'service_product_id' => $this->products['2901001']->id, 'recipe_version_id' => $svc('2901001'), 'included_items' => ['butter', 'saus lada hitam']]);
        CookingFeeRule::create($base + ['code' => 'SLO-CF-BAKAR', 'method' => 'bakar', 'basis' => 'tiered', 'rate' => 35000, 'minimum_fee' => 30000,
            'tiers' => [['up_to' => '1.000', 'rate' => 45000], ['up_to' => '2.000', 'rate' => 40000]],
            'service_product_id' => $this->products['2901002']->id, 'recipe_version_id' => $svc('2901002'), 'included_items' => ['bumbu jimbaran', 'sambal matah']]);
        CookingFeeRule::create($base + ['code' => 'SLO-CF-GORENG', 'method' => 'goreng', 'basis' => 'per_kg', 'rate' => 35000, 'minimum_fee' => 25000,
            'service_product_id' => $this->products['2901003']->id, 'recipe_version_id' => $svc('2901003'), 'included_items' => ['tepung bumbu', 'sambal']]);
        CookingFeeRule::create($base + ['code' => 'SLO-CF-PADANG', 'method' => 'saus_padang', 'basis' => 'per_portion', 'rate' => 30000, 'minimum_fee' => 30000,
            'service_product_id' => $this->products['2901003']->id, 'recipe_version_id' => $svc('2901003'), 'included_items' => ['saus padang']]);
    }

    private function tables(): void
    {
        foreach ([['A1', 'Indoor', 4], ['A2', 'Indoor', 4], ['A3', 'Indoor', 4], ['A4', 'Indoor', 2], ['A5', 'Indoor', 6],
            ['B1', 'Teras', 4], ['B2', 'Teras', 4], ['B3', 'Teras', 6], ['L1', 'Lesehan', 8], ['L2', 'Lesehan', 8], ['VIP', 'Ruang VIP', 12]] as [$code, $area, $cap]) {
            DiningTable::create(['outlet_id' => $this->outlet->id, 'code' => $code, 'area' => $area, 'capacity' => $cap]);
        }
    }

    private function promotions(): void
    {
        $id = fn (string $code) => $this->products[$code]->id;
        $promos = [
            ['SLO-DAGING-2KG', 'Borong Daging Sapi: ≥2 kg hemat 10%, ≥4 kg hemat 15%', 'weight_tier', 'item', 'exclusive', 10,
                ['categories' => ['Daging Sapi'], 'tiers' => [['min_weight' => '2.000', 'percent' => 10], ['min_weight' => '4.000', 'percent' => 15]]], null],
            ['SLO-SEAFOOD-JUMAT', 'Seafood Segar diskon 12%', 'percent', 'item', 'exclusive', 20,
                ['categories' => ['Ikan Laut'], 'percent' => 12], ['retail']],
            ['SLO-SOSIS-B2G1', 'Sosis Bratwurst beli 2 gratis 1', 'buy_x_get_y', 'item', 'exclusive', 30,
                ['buy_product_id' => $id('2301001'), 'buy_qty' => 2, 'get_product_id' => $id('2301001'), 'get_qty' => 1, 'product_ids' => [$id('2301001')]], null],
            ['SLO-KENTANG-HEMAT', 'Harga spesial Kentang Shoestring Rp45.000', 'special_price', 'item', 'exclusive', 40,
                ['product_ids' => [$id('2301004')], 'price' => 45000], null],
            ['SLO-STEAK-DINEIN', 'Steak dine-in hemat 10%', 'percent', 'item', 'exclusive', 15,
                ['categories' => ['Steak'], 'percent' => 10, 'max_discount' => 40000], ['dine_in']],
            ['SLO-BELANJA-1JT', 'Belanja ≥ Rp1.000.000 potongan Rp50.000', 'amount', 'basket', 'stackable', 90,
                ['min_spend' => 1000000, 'amount' => 50000, 'line_types' => ['retail_product']], ['retail']],
        ];
        foreach ($promos as [$code, $name, $type, $scope, $stacking, $priority, $rules, $channels]) {
            $promo = Promotion::create(['code' => $code, 'name' => $name]);
            $version = PromotionVersion::create([
                'promotion_id' => $promo->id, 'version' => 1, 'type' => $type, 'scope' => $scope, 'rules' => $rules, 'channels' => $channels,
                'priority' => $priority, 'stacking' => $stacking, 'starts_at' => $this->now->copy()->subDays(3), 'ends_at' => $this->now->copy()->addDays(30),
                'status' => 'published', 'published_at' => $this->now->copy()->subDays(3),
            ]);
            PromotionTarget::create(['promotion_version_id' => $version->id, 'outlet_id' => $this->outlet->id]);
        }
    }

    private function openingStock(): void
    {
        $at = $this->now->copy()->subDays(1)->setTime(6, 0);
        Carbon::setTestNow($at);

        // Pack ID daging dan ikan: berat aktual dari timbangan gudang pusat / etalase.
        $packs = [
            '2101001' => ['1.245', '1.510', '0.985', '2.040', '1.120', '0.890'],
            '2101002' => ['0.865', '1.020', '1.240', '0.770'],
            '2101003' => ['1.150', '0.940', '1.330'],
            '2101004' => ['0.520', '0.610', '0.480'],
            '2101005' => ['1.500', '2.000', '1.750'],
            '2101006' => ['1.200', '1.050'],
            '2102001' => ['1.000', '1.250'],
            '2102002' => ['0.950', '1.100'],
            '2103001' => ['1.050', '0.980', '1.120'],
            '2201001' => ['0.850', '1.120', '0.960', '1.340'],
            '2201002' => ['1.250', '0.980'],
            '2201003' => ['0.620', '0.700', '0.580'],
            '2201004' => ['0.480', '0.520', '0.505'],
            '2201005' => ['0.400', '0.450'],
            '2201006' => ['1.000', '0.500', '1.000', '0.750'],
            '2201007' => ['0.800', '1.000'],
            '2201008' => ['0.950', '1.150'],
            '2201009' => ['1.000', '1.000'],
        ];
        $n = 100000;
        foreach ($packs as $code => $weights) {
            $product = $this->products[$code];
            $fresh = $product->category === 'Ikan Laut';
            foreach ($weights as $i => $weight) {
                $n++;
                $pack = Pack::create([
                    'pack_code' => 'PK-'.$n, 'product_id' => $product->id, 'net_weight' => $weight, 'uom_code' => 'KG',
                    'weigh_source' => $fresh ? 'SCALE-SLO-ESF' : 'SCALE-GDG-01', 'packed_at' => $at->copy()->subDay(),
                    'expires_at' => $at->copy()->addDays($fresh ? 3 : 7)->toDateString(), 'location_type' => 'outlet',
                    'outlet_id' => $this->outlet->id, 'status' => 'received',
                ]);
                StockLedger::move($this->outlet->id, $product->id, $weight, 'KG', 'opening_balance', 'opening', $pack->id, packId: $pack->id);
            }
        }

        // Pack khusus untuk mencoba blokir: ditahan QC dan sudah kedaluwarsa (butuh PIN kepala outlet).
        foreach ([['PK-109001', '2101001', '1.300', 'hold', 5], ['PK-109002', '2201003', '0.640', 'received', -1]] as [$code, $p, $w, $status, $days]) {
            $pack = Pack::create([
                'pack_code' => $code, 'product_id' => $this->products[$p]->id, 'net_weight' => $w, 'uom_code' => 'KG', 'weigh_source' => 'SCALE-SLO-ESF',
                'packed_at' => $at->copy()->subDays(4), 'expires_at' => $at->copy()->addDays($days)->toDateString(), 'location_type' => 'outlet',
                'outlet_id' => $this->outlet->id, 'status' => $status,
            ]);
            StockLedger::move($this->outlet->id, $this->products[$p]->id, $w, 'KG', 'opening_balance', 'opening', $pack->id, packId: $pack->id);
        }

        // Kiriman gudang pusat yang sedang dalam perjalanan (diterima lewat Rasentra Inventory).
        foreach ([['PK-120001', '2101002', '1.180'], ['PK-120002', '2101003', '1.060'], ['PK-120003', '2201004', '0.510'], ['PK-120004', '2201001', '1.050'], ['PK-120005', '2201006', '1.000']] as [$code, $p, $w]) {
            Pack::create([
                'pack_code' => $code, 'product_id' => $this->products[$p]->id, 'net_weight' => $w, 'uom_code' => 'KG', 'weigh_source' => 'SCALE-GDG-01',
                'packed_at' => $this->now->copy()->subHours(5), 'expires_at' => $this->now->copy()->addDays(6)->toDateString(),
                'location_type' => 'transit', 'outlet_id' => $this->outlet->id, 'status' => 'shipped',
            ]);
        }

        // Saldo awal barang satuan dan bahan dapur.
        $i = 0;
        foreach ($this->products as $code => $product) {
            if (! $product->is_stocked || $product->is_weighed || $product->type === 'prep') {
                continue;
            }
            $qty = match (true) {
                $product->type === 'retail' => (string) random_int(12, 48),
                in_array($product->uom_code, ['PCS', 'PAK'], true) => '120',
                str_starts_with($code, '2801') => '12',
                default => '15',
            };
            StockLedger::move($this->outlet->id, $product->id, $qty, $product->uom_code, 'opening_balance', 'opening',
                '00000000-0000-7000-9000-'.str_pad((string) $product->id, 12, '0', STR_PAD_LEFT), ++$i);
        }
    }

    // ------------------------------------------------------- satu hari berjalan

    private function simulateDay(): void
    {
        $orders = app(OrderService::class);
        $payments = app(PaymentService::class);
        $kitchen = app(KitchenService::class);
        $board = app(KitchenBoard::class);
        $shifts = app(ShiftService::class);
        $approval = ['approver_id' => $this->users['kepala']->id, 'pin' => '9999'];
        $p = fn (string $code) => $this->products[$code];

        // Produksi pagi: 2 kg saus lada hitam untuk steak dan jasa masak.
        $this->at(420);
        app(RecipeService::class)->produce($this->outlet->id, $this->users['chef'], [
            ['product_id' => $p('2803001')->id, 'quantity' => '0.200', 'uom' => 'KG'],
            ['product_id' => $p('2803017')->id, 'quantity' => '0.080', 'uom' => 'KG'],
            ['product_id' => $p('2803018')->id, 'quantity' => '1.500', 'uom' => 'LTR'],
            ['product_id' => $p('2803019')->id, 'quantity' => '0.300', 'uom' => 'KG'],
        ], [['product_id' => $p('2804001')->id, 'quantity' => '1.950', 'uom' => 'KG']], 'Penguapan saat reduksi', $this->recipeVersionId('2804001'));

        $this->at(400);
        $retailShift = $shifts->open($this->users['kasir'], $this->outlet->id, '1000000');
        $restoShift = $shifts->open($this->users['kasir2'], $this->outlet->id, '750000');
        $shifts->cashMovement($retailShift, $this->users['kasir'], 'cash_in', '200000', 'Tambahan uang kecil dari brankas');

        $pack = fn (string $code) => Pack::where('product_id', $p($code)->id)->where('status', 'received')->where('outlet_id', $this->outlet->id)
            ->where('expires_at', '>=', now()->toDateString())->orderBy('pack_code')->value('pack_code');

        // ---- Penjualan retail sepanjang pagi-siang.
        $retail = [
            [380, [['pack', '2101001'], ['pack', '2101001'], ['sku', '2302001', 1]], [['cash', null]]],
            [350, [['pack', '2201001'], ['pack', '2201006'], ['sku', '2304004', 2]], [['edc_manual', 'APPR-381102']]],
            [320, [['sku', '2301001', 3], ['sku', '2301004', 2], ['sku', '2303003', 1]], [['cash', null]]],
            [290, [['pack', '2101002'], ['pack', '2101003'], ['pack', '2101004'], ['sku', '2302002', 1]], [['qris', null]]],
            [255, [['pack', '2201004'], ['pack', '2201005'], ['sku', '2202001', 2]], [['cash', '200000'], ['edc_manual', 'APPR-381355']]],
            [230, [['sku', '2304001', 2], ['sku', '2304002', 3], ['sku', '2304003', 2], ['sku', '2303001', 1]], [['cash', null]]],
            [200, [['pack', '2101005'], ['pack', '2101006'], ['sku', '2302003', 2]], [['edc_manual', 'APPR-381420']]],
            [170, [['pack', '2102001'], ['pack', '2103001'], ['sku', '2103002', 2]], [['qris', null]]],
            [140, [['pack', '2201002'], ['pack', '2201008'], ['sku', '2305001', 6]], [['cash', null]]],
            [95, [['sku', '2101007', 2], ['sku', '2301002', 1], ['sku', '2301003', 2], ['sku', '2301005', 1]], [['cash', null]]],
            [60, [['pack', '2101001'], ['pack', '2201003'], ['sku', '2302004', 1]], [['edc_manual', 'APPR-381511']]],
        ];
        foreach ($retail as $idx => [$minutesAgo, $lines, $pays]) {
            $this->at($minutesAgo);
            $order = $orders->create($this->users['kasir'], $this->outlet->id, 'retail', ['shift_id' => $retailShift->id]);
            foreach ($lines as $line) {
                $line[0] === 'pack'
                    ? $orders->addPack($order, $pack($line[1]), $this->users['kasir'])
                    : $orders->addSku($order, $p($line[1]), (string) $line[2]);
            }
            if ($idx === 5) {
                // Override harga dengan PIN kepala outlet: kemasan abon penyok.
                $abon = $order->lines()->where('product_id', $p('2304001')->id)->first();
                $orders->overridePrice($abon, '58000', 'Kemasan penyok, disetujui kepala outlet', $this->users['kasir'], $approval);
            }
            $this->settle($order, $pays, $payments);
            $orders->finalize($order->fresh(), $this->users['kasir']);
        }

        // Pack kedaluwarsa dijual setelah disetujui kepala outlet (tercatat di audit).
        $this->at(45);
        $order = $orders->create($this->users['kasir'], $this->outlet->id, 'retail', ['shift_id' => $retailShift->id]);
        $orders->addPack($order, 'PK-109002', $this->users['kasir'], $approval);
        $orders->overridePrice($order->lines()->first(), '95000', 'Markdown ikan H+1', $this->users['kasir'], $approval);
        $this->settle($order, [['cash', null]], $payments);
        $orders->finalize($order->fresh(), $this->users['kasir']);

        // Transaksi yang sedang ditahan kasir (pelanggan kembali ambil dompet).
        $this->at(12);
        $held = $orders->create($this->users['kasir'], $this->outlet->id, 'retail', ['shift_id' => $retailShift->id]);
        $orders->addPack($held, $pack('2101002'), $this->users['kasir']);
        $orders->addPack($held, $pack('2201004'), $this->users['kasir']);
        $orders->addSku($held, $p('2302001'), '1');
        $orders->hold($held->fresh());

        // ---- Restoran: pesanan yang sudah selesai (untuk analisis waktu saji).
        $done = [
            [330, 'A1', 2, [['2501001', 2, 'medium'], ['2504001', 2], ['2505001', 2]], [4, 14, 17]],
            [300, 'B1', 4, [['2503004', 2], ['2503005', 1], ['2503007', 3], ['2505002', 4]], [3, 11, 13]],
            [270, 'L1', 6, [['2502002', 2], ['2502003', 1], ['2503001', 1], ['2504003', 6], ['2505003', 3], ['2505001', 3]], [5, 19, 22]],
            [225, 'A3', 2, [['2501004', 1, 'medium_rare'], ['2504004', 1], ['2505005', 2], ['2506001', 2]], [2, 12, 14]],
            [190, 'VIP', 10, [['2501002', 3, 'medium'], ['2501003', 2, 'well_done'], ['2503006', 3], ['2503003', 2], ['2504002', 5], ['2505004', 5], ['2506002', 3]], [6, 24, 28]],
            [150, 'B2', 3, [['2502001', 2], ['2503002', 1], ['2504003', 3], ['2505001', 3]], [3, 13, 15]],
            [110, 'A5', 4, [['2501001', 2, 'medium_well'], ['2502004', 1], ['2504001', 2], ['2505002', 4], ['2506003', 2]], [4, 16, 18]],
        ];
        foreach ($done as $i => [$minutesAgo, $table, $guests, $items, [$start, $ready, $served]]) {
            $order = $this->restoOrder($orders, $table, $guests, $minutesAgo, $i % 2 ? 'pelayan2' : 'pelayan', $restoShift, $items);
            $kitchen->send($order);
            $this->advance($board, $order, $minutesAgo, $start, $ready, $served);
            $this->at($minutesAgo - $served - 25);
            $this->settle($order->fresh(), [[$i % 3 === 0 ? 'cash' : 'edc_manual', $i % 3 === 0 ? null : 'APPR-39'.$i.'0'.$i]], $payments);
            $orders->finalize($order->fresh(), $this->users['kasir2']);
            Visit::whereKey($order->visit_id)->update(['status' => 'closed']);
        }

        // Cooking fee yang sudah selesai: kakap dari etalase dibakar, daging dibayar di kasir retail dulu.
        $this->at(240);
        $receipt = $orders->create($this->users['kasir'], $this->outlet->id, 'retail', ['shift_id' => $retailShift->id]);
        $tbone = $pack('2101003');
        $orders->addPack($receipt, $tbone, $this->users['kasir']);
        $this->settle($receipt, [['cash', null]], $payments);
        $receipt = $orders->finalize($receipt->fresh(), $this->users['kasir']);
        $this->at(235);
        $cook = $this->restoOrder($orders, 'A4', 2, 235, 'pelayan', $restoShift, [['2504002', 1], ['2505001', 2]]);
        app(CookingService::class)->requestFromReceipt($cook, $receipt->doc_no, $tbone, $this->users['pelayan'], ['portions' => 2, 'doneness' => 'medium']);
        $kitchen->send($cook->fresh());
        $this->advance($board, $cook, 235, 3, 16, 18);
        $this->at(200);
        $this->settle($cook->fresh(), [['cash', null]], $payments);
        $orders->finalize($cook->fresh(), $this->users['kasir2']);

        // ---- Restoran: yang sedang berjalan sekarang (tampil di KDS dan expeditor).
        $live = [
            // [menit lalu, meja, tamu, item, langkah: start/ready per item index]
            [24, 'A2', 4, [['2501003', 2, 'medium'], ['2503004', 1], ['2504001', 2], ['2505001', 4]], ['0' => 'start', '1' => 'ready', '2' => 'ready', '3' => 'served']],
            [13, 'B3', 5, [['2501001', 2, 'rare'], ['2501004', 1, 'medium_rare'], ['2503005', 2], ['2505002', 3], ['2505004', 2]], ['0' => 'start', '2' => 'start', '3' => 'ready']],
            [7, 'L2', 8, [['2502002', 3], ['2503001', 2], ['2503003', 2], ['2504003', 8], ['2505003', 4], ['2506001', 4]], ['1' => 'start']],
            [2, 'A1', 2, [['2501002', 1, 'medium'], ['2504004', 1], ['2505005', 2]], []],
        ];
        foreach ($live as $i => [$minutesAgo, $table, $guests, $items, $steps]) {
            $order = $this->restoOrder($orders, $table, $guests, $minutesAgo, $i % 2 ? 'pelayan2' : 'pelayan', $restoShift, $items);
            $kitchen->send($order);
            $this->stepItems($board, $order, $minutesAgo, $steps);
        }

        // Meja VIP: pilih kakap & udang dari etalase es, minta dibakar dan saus padang (cooking fee).
        $this->at(5);
        $vip = $this->restoOrder($orders, 'VIP', 6, 5, 'pelayan', $restoShift, [['2503007', 6], ['2505001', 6]]);
        $cooking = app(CookingService::class);
        $kakap = $orders->addPack($vip, $pack('2201001'), $this->users['pelayan']);
        $cooking->requestForPackLine($kakap, $this->users['pelayan'], ['method' => 'bakar', 'portions' => 3, 'sauce' => 'sambal matah']);
        $udang = $orders->addPack($vip, $pack('2201006'), $this->users['pelayan']);
        $cooking->requestForPackLine($udang, $this->users['pelayan'], ['method' => 'saus_padang', 'portions' => 3, 'allergy_note' => 'Satu tamu alergi kacang']);
        $kitchen->send($vip->fresh());

        // ---- Takeaway: nomor antrean di layar pickup.
        $takeaway = [
            [16, [['2503003', 2], ['2505001', 2]], 'ready'],
            [9, [['2501001', 1, 'medium'], ['2504001', 1]], 'start'],
            [3, [['2502001', 1], ['2504003', 1], ['2505003', 1]], null],
        ];
        foreach ($takeaway as [$minutesAgo, $items, $state]) {
            $this->at($minutesAgo);
            $order = $orders->create($this->users['kasir2'], $this->outlet->id, 'takeaway', ['shift_id' => $restoShift->id]);
            foreach ($items as $it) {
                $orders->addSku($order, $p($it[0]), (string) $it[1], isset($it[2]) ? ['modifiers' => [['key' => $it[2], 'label' => $this->doneness($it[2])]]] : []);
            }
            $this->settle($order->fresh(), [['qris', null]], $payments);
            $kitchen->send($order->fresh());
            if ($state !== null) {
                $this->stepItems($board, $order, $minutesAgo, array_fill_keys(array_keys($items), $state));
            }
        }

        // ---- Inventory: sesi opname cycle count berjalan (blind), dihitung Wawan.
        $this->at(30);
        $counts = app(StockCountService::class);
        $session = $counts->open($this->users['gudang'], $this->outlet->id, 'cycle', true, '0.050');
        $this->at(25);
        foreach ([['8992301001009', '2', 'RAK'], ['8992302001008', '18', 'RAK'], ['8992202001006', '9', 'FRZ']] as [$code, $qty, $loc]) {
            $counts->record($session, $this->users['gudang'], $code, $qty, $loc);
        }
        foreach (Pack::where('outlet_id', $this->outlet->id)->where('product_id', $p('2201004')->id)->whereIn('status', ['received'])->limit(1)->pluck('pack_code') as $code) {
            $counts->record($session, $this->users['gudang'], $code, null, 'ESF');
        }

        $this->at(0);
    }

    // ----------------------------------------------------------------- bantuan

    private function at(int $minutesAgo): void
    {
        Carbon::setTestNow($this->now->copy()->subMinutes($minutesAgo));
    }

    private function restoOrder(OrderService $orders, string $table, int $guests, int $minutesAgo, string $waiter, Shift $shift, array $items): Order
    {
        $this->at($minutesAgo);
        $visit = Visit::create([
            'outlet_id' => $this->outlet->id, 'dining_table_id' => DiningTable::where('outlet_id', $this->outlet->id)->where('code', $table)->value('id'),
            'visit_token' => Str::random(40), 'guest_count' => $guests, 'waiter_id' => $this->users[$waiter]->id, 'status' => 'open', 'opened_at' => now(),
        ]);
        $order = $orders->create($this->users[$waiter], $this->outlet->id, 'dine_in', ['shift_id' => $shift->id, 'visit_id' => $visit->id]);
        foreach ($items as $it) {
            $opts = isset($it[2]) ? ['modifiers' => [['key' => $it[2], 'label' => $this->doneness($it[2])]]] : [];
            if ($it[0] === '2503001') {
                $opts['note'] = 'Pedas sedang';
            }
            $orders->addSku($order, $this->products[$it[0]], (string) $it[1], $opts);
        }

        return $order->fresh();
    }

    private function doneness(string $key): string
    {
        return ['rare' => 'Rare', 'medium_rare' => 'Medium rare', 'medium' => 'Medium', 'medium_well' => 'Medium well', 'well_done' => 'Well done'][$key] ?? $key;
    }

    /**
     * Majukan semua item pesanan: mulai setelah $start menit, siap sesuai lama masak tiap
     * station (plus variasi kecil per pesanan), lalu disajikan dua menit kemudian.
     */
    private function advance(KitchenBoard $board, Order $order, int $minutesAgo, int $start, int $ready, int $served): void
    {
        $actor = $this->users['chef'];
        $extra = intdiv(max(0, $ready - $start - 12), 4); // pesanan besar sedikit lebih lama
        foreach ($this->items($order) as $n => $item) {
            $cook = $this->cookMinutes($item) + $extra + ($n % 2);
            foreach ([['start', $start], ['ready', $start + $cook], ['served', $start + $cook + 2]] as [$step, $offset]) {
                $this->at(max(0, $minutesAgo - $offset));
                $board->bumpItem($item->fresh(), $actor, $step === 'served');
            }
        }
    }

    private function stepItems(KitchenBoard $board, Order $order, int $minutesAgo, array $steps): void
    {
        $items = $this->items($order);
        foreach ($steps as $index => $target) {
            $item = $items[(int) $index] ?? null;
            if ($item === null) {
                continue;
            }
            $sequence = ['start' => ['start'], 'ready' => ['start', 'ready'], 'served' => ['start', 'ready', 'served']][$target];
            foreach ($sequence as $k => $step) {
                $this->at(max(0, $minutesAgo - 1 - $k * 4));
                $board->bumpItem($item->fresh(), $this->users['chef'], $step === 'served');
            }
        }
    }

    /** @return list<TicketItem> urut sesuai baris pesanan */
    private function items(Order $order): array
    {
        $tickets = KitchenTicket::where('order_id', $order->id)->pluck('id');

        return TicketItem::whereIn('kitchen_ticket_id', $tickets)
            ->join('order_lines', 'order_lines.id', '=', 'ticket_items.order_line_id')
            ->orderBy('order_lines.line_no')->select('ticket_items.*')->get()->all();
    }

    private function cookMinutes(TicketItem $item): int
    {
        return match (KitchenTicket::whereKey($item->kitchen_ticket_id)->value('station')) {
            'bar' => 3,
            'cold', 'pastry' => 6,
            'hot' => 6,
            'grill' => 10,
            default => 7,
        };
    }

    private function settle(Order $order, array $pays, PaymentService $payments): void
    {
        $order->refresh();
        $remaining = (string) $order->net_amount;
        foreach ($pays as $k => [$method, $ref]) {
            $last = $k === count($pays) - 1;
            $amount = $last ? $remaining : (string) $ref;
            if ($method === 'cash') {
                $tendered = $last ? (string) (ceil(((float) $amount) / 50000) * 50000) : $amount;
                $payments->add($order, 'cash', $amount, ['tendered' => $tendered]);
            } elseif ($method === 'qris') {
                $payment = $payments->add($order, 'qris', $amount, ['provider_ref' => 'QR'.Str::upper(Str::random(10))]);
                $payments->applyProviderResult($payment, 'succeeded', (string) $payment->amount);
            } else {
                $payments->add($order, 'edc_manual', $amount, ['provider' => 'edc_manual', 'provider_ref' => $ref]);
            }
            $remaining = (string) \App\Support\Decimal::money(\App\Support\Decimal::of($remaining)->minus(\App\Support\Decimal::of($amount)));
        }
    }

    private function recipeVersionId(string $code): ?int
    {
        return RecipeVersion::whereHas('recipe', fn ($q) => $q->where('product_id', $this->products[$code]->id))->value('id');
    }
}
