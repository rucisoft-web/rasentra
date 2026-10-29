<?php

namespace Database\Seeders;

use App\Domain\Catalog\PricePublisher;
use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Promotion\PromotionDesigner;
use App\Domain\Sync\InboxReceiver;
use App\Models\Device;
use App\Models\InboxEvent;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\PriceItem;
use App\Models\PriceVersion;
use App\Models\Product;
use App\Models\PromotionVersion;
use App\Models\SapMapping;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Data demo Rasentra Core (kantor pusat): pengguna pusat, perangkat dan token, riwayat
 * penjualan 7 hari dari beberapa outlet lewat inbox Rasentra Central, mapping dan posting
 * SAP tiruan (termasuk kasus perlu review), serta promo dan versi harga yang menunggu persetujuan.
 * Dijalankan setelah JmSoloSeeder.
 */
class CoreSeeder extends Seeder
{
    private const COMPANY_DB = 'PASARTANI';

    /** Produk yang sengaja belum dimapping ke SAP; muncul di beberapa struk kemarin sebagai contoh masalah integrasi. */
    private const UNMAPPED = ['2201008'];

    private int $unmappedOrders = 3;

    private Carbon $now;

    private Outlet $jms;

    /** @var array<string, User> */
    private array $hq = [];

    public function run(): void
    {
        $jms = Outlet::where('code', 'JMS')->first();
        if (! $jms || User::where('email', 'admin@rasentra.test')->exists()) {
            return;
        }
        $this->jms = $jms;
        $this->now = Carbon::now();
        mt_srand(2026);

        $this->users();
        $this->devices();
        $this->syncJmsOutbox();
        $this->salesHistory();
        $this->sapMappings();
        $this->postToSap();
        $this->pendingApprovals();
    }

    private function users(): void
    {
        foreach ([
            'admin' => ['Rahman Hidayat', 'admin@rasentra.test', 'admin'],
            'merch' => ['Laras Wulandari', 'merch@rasentra.test', 'hq_merch'],
            'finance' => ['Fajar Nugroho', 'finance@rasentra.test', 'hq_finance'],
        ] as $key => [$name, $email, $role]) {
            $this->hq[$key] = User::create([
                'name' => $name, 'email' => $email, 'password' => Hash::make('password'), 'role' => $role,
                'outlet_id' => null, 'approval_limit' => $role === 'admin' ? 25000000 : 0,
            ]);
        }
    }

    private function devices(): void
    {
        $id = $this->jms->id;
        foreach ([
            ['SRV-JMS', 'outlet_server', 'Rasentra Lokal J&M Solo', 1],
            ['POS-JMS-01', 'pos', 'Kasir retail 1', 2],
            ['POS-JMS-02', 'pos', 'Kasir resto', 2],
            ['TRM-JMS-01', 'terminal', 'Tablet pelayan Lusi', 6],
            ['KDS-JMS-GRILL', 'kitchen', 'Layar dapur grill', 1],
            ['KDS-JMS-HOT', 'kitchen', 'Layar dapur hot kitchen', 1],
            ['KDS-JMS-EXPO', 'kitchen', 'Layar expeditor', 1],
            ['INV-JMS-01', 'inventory', 'Handheld gudang Wawan', 40],
        ] as [$code, $app, $name, $minutes]) {
            Device::create([
                'outlet_id' => $id, 'code' => $code, 'app' => $app, 'name' => $name,
                'token_hash' => hash('sha256', 'demo-'.Str::lower($code)), 'last_seen_at' => $this->now->copy()->subMinutes($minutes),
            ]);
        }
        Device::create(['outlet_id' => $id, 'code' => 'POS-JMS-03', 'app' => 'pos', 'name' => 'Kasir lama (dicabut)', 'token_hash' => null, 'is_active' => false]);
    }

    /** Kirim event outbox J&M Solo hari ini ke inbox Central; dua event terakhir masih antre. */
    private function syncJmsOutbox(): void
    {
        OutboxEvent::where('outlet_id', $this->jms->id)->update(['source_id' => 'SRV-JMS']);
        $events = OutboxEvent::where('outlet_id', $this->jms->id)->orderBy('id')->get();
        $send = $events->slice(0, max(0, $events->count() - 2));

        $results = collect(app(InboxReceiver::class)->receive($send->map(fn (OutboxEvent $e) => $e->only([
            'event_id', 'source_id', 'outlet_id', 'entity_type', 'entity_id', 'entity_version', 'event_type', 'payload', 'checksum',
        ]))->values()->all(), 'SRV-JMS'))->keyBy('event_id');

        foreach ($send as $event) {
            $r = $results[$event->event_id] ?? null;
            if ($r && in_array($r['result'], ['accepted', 'duplicate'], true)) {
                $event->update(['status' => 'acked', 'ack_ref' => $r['ack_ref'], 'attempts' => 1]);
                InboxEvent::where('event_id', $event->event_id)->update(['created_at' => $event->occurred_at->copy()->addSeconds(5), 'updated_at' => $event->occurred_at->copy()->addSeconds(5)]);
            }
        }
        $events->last()?->update(['status' => 'failed', 'attempts' => 3, 'last_error' => 'cURL error 28: Connection timed out after 15000 ms', 'next_attempt_at' => $this->now->copy()->addMinute()]);
    }

    /** Riwayat penjualan 7 hari untuk J&M Solo dan sembilan outlet lain, dikirim lewat inbox seperti event asli. */
    private function salesHistory(): void
    {
        $prices = PriceItem::query()->whereIn('price_version_id', PriceVersion::where('status', 'published')->where('outlet_id', $this->jms->id)->pluck('id'))
            ->pluck('unit_price', 'product_id');
        $products = Product::whereIn('id', $prices->keys())->get();
        $unmapped = $products->whereIn('code', self::UNMAPPED)->values();
        $products = $products->whereNotIn('code', self::UNMAPPED);
        $retail = $products->whereIn('type', ['retail', 'weighed'])->values();
        $menus = $products->where('type', 'menu')->values();
        $promos = PromotionVersion::with('promotion')->where('status', 'published')->get()->keyBy(fn ($v) => $v->promotion->code);

        $others = Outlet::where('code', '!=', 'JMS')->where('is_active', true)->orderBy('code')->limit(9)->get();
        $late = $others->take(-2)->pluck('id')->all(); // dua outlet terakhir: sinkron terakhir 2 jam lalu
        $today = $this->now->copy()->setTimezone(config('rasentra.display_timezone'))->startOfDay();

        foreach ($others->prepend($this->jms) as $outlet) {
            $source = 'SRV-'.$outlet->code;
            if ($outlet->id !== $this->jms->id) {
                Device::create([
                    'outlet_id' => $outlet->id, 'code' => $source, 'app' => 'outlet_server', 'name' => 'Rasentra Lokal '.$outlet->name,
                    'token_hash' => hash('sha256', 'demo-'.Str::lower($source)),
                    'last_seen_at' => $this->now->copy()->subMinutes(in_array($outlet->id, $late, true) ? 125 : 2),
                ]);
            }
            $scale = $outlet->id === $this->jms->id ? 1.6 : (0.6 + mt_rand(0, 60) / 100);

            for ($d = 6; $d >= 0; $d--) {
                // Hari ini J&M Solo sudah punya struk nyata dari JmSoloSeeder.
                if ($d === 0 && $outlet->id === $this->jms->id) {
                    continue;
                }
                $day = $today->copy()->subDays($d);
                $weekend = $day->isWeekend() ? 1.35 : 1.0;
                $count = (int) round(mt_rand(10, 18) * $scale * $weekend * ($d === 0 ? 0.45 : 1));
                $events = [];
                $times = [];
                for ($i = 1; $i <= $count; $i++) {
                    $at = $this->orderTime($day, $d === 0, in_array($outlet->id, $late, true));
                    $dineIn = $outlet->id === $this->jms->id && $menus->isNotEmpty() && mt_rand(1, 100) <= 40;
                    $pool = $dineIn ? $menus : $retail;
                    if ($d === 1 && ! $dineIn && $outlet->id === $this->jms->id && $this->unmappedOrders-- > 0) {
                        $pool = $unmapped->isNotEmpty() ? $unmapped : $pool;
                    }
                    $payload = $this->syntheticOrder($outlet, $day, $at, $i, $pool, $prices, $promos, $dineIn);
                    $events[] = $this->event($outlet->id, $source, $payload);
                    $times[$payload['id']] = $at;
                }
                app(InboxReceiver::class)->receive($events, $source);
                foreach ($times as $orderId => $at) {
                    InboxEvent::where('entity_id', $orderId)->update(['created_at' => $at->copy()->addSeconds(mt_rand(5, 40)), 'updated_at' => $at->copy()->addSeconds(40)]);
                }
            }
        }
        $this->touchLastSync($late);
    }

    /** Outlet yang tersambung mengirim heartbeat/event terakhir beberapa menit lalu. */
    private function touchLastSync(array $late): void
    {
        foreach (InboxEvent::query()->whereNotIn('outlet_id', $late)->groupBy('outlet_id')->selectRaw('MAX(id) as id')->pluck('id') as $id) {
            $at = $this->now->copy()->subSeconds(mt_rand(40, 240));
            InboxEvent::whereKey($id)->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }

    private function orderTime(Carbon $day, bool $isToday, bool $late): Carbon
    {
        $open = $day->copy()->setTime(8, 0)->utc();
        $close = $isToday ? $this->now->copy()->subMinutes($late ? 125 : 3) : $day->copy()->setTime(21, 0)->utc();
        if ($close->lessThanOrEqualTo($open)) {
            $open = $close->copy()->subHours(2);
        }

        return $open->copy()->addSeconds(mt_rand(0, max(1, (int) $open->diffInSeconds($close))));
    }

    private function syntheticOrder(Outlet $outlet, Carbon $day, Carbon $at, int $seq, Collection $pool, Collection $prices, Collection $promos, bool $dineIn): array
    {
        $lines = [];
        $picked = $pool->random(min($pool->count(), mt_rand(1, 4)));
        foreach ($picked->values() as $n => $p) {
            $qty = $p->is_weighed ? number_format(mt_rand(350, 2600) / 1000, 3, '.', '') : (string) mt_rand(1, $dineIn ? 3 : 2);
            $price = (string) $prices[$p->id];
            $gross = Decimal::money(Decimal::mul($qty, $price));
            $promo = match (true) {
                $p->category === 'Ikan Laut' && ! $dineIn => $promos->get('JMS-SEAFOOD-JUMAT'),
                $p->category === 'Steak' && $dineIn => $promos->get('JMS-STEAK-DINEIN'),
                $p->category === 'Daging Sapi' && $p->is_weighed && (float) $qty >= 2 => $promos->get('JMS-DAGING-2KG'),
                default => null,
            };
            $percent = match ($promo?->promotion->code) {
                'JMS-SEAFOOD-JUMAT' => 12, 'JMS-DAGING-2KG' => 10, 'JMS-STEAK-DINEIN' => 10, default => 0,
            };
            $discount = $percent ? Decimal::money(Decimal::of($gross)->multipliedBy($percent)->dividedBy(100, 2, Decimal::rounding())) : '0.00';
            $lines[] = [
                'id' => (string) Str::uuid7(), 'line_no' => $n + 1, 'line_type' => $dineIn ? 'restaurant_menu' : 'retail_product',
                'product_id' => $p->id, 'pack_id' => null, 'cooking_job_id' => null, 'quantity' => $qty, 'uom' => $p->uom_code,
                'unit_price' => $price, 'gross_amount' => $gross, 'discount_amount' => $discount,
                'net_amount' => Decimal::money(Decimal::of($gross)->minus(Decimal::of($discount))),
                'discounts' => $percent ? [['promotion_version_id' => $promo->id, 'amount' => $discount, 'funded_by' => $promo->funded_by ?? 'internal']] : [],
            ];
        }
        $gross = Decimal::money(Decimal::sum(array_column($lines, 'gross_amount')));
        $discount = Decimal::money(Decimal::sum(array_column($lines, 'discount_amount')));
        $net = Decimal::money(Decimal::sum(array_column($lines, 'net_amount')));
        $roll = mt_rand(1, 100);
        $method = $roll <= 42 ? 'cash' : ($roll <= 75 ? 'qris' : 'edc');

        return [
            'id' => (string) Str::uuid7(),
            'doc_no' => sprintf('%s-%s-%04d', $outlet->code, $day->format('ymd'), $seq),
            'outlet_id' => $outlet->id,
            'channel' => $dineIn ? 'dine_in' : 'retail',
            'business_date' => $day->toDateString(),
            'gross_amount' => $gross, 'discount_amount' => $discount, 'net_amount' => $net,
            'finalized_at' => $at->toIso8601ZuluString(),
            'lines' => $lines,
            'payments' => [[
                'id' => (string) Str::uuid7(), 'method' => $method, 'amount' => $net, 'status' => 'succeeded',
                'provider' => $method === 'cash' ? null : ($method === 'qris' ? 'qris-bca' : 'edc-bri'),
                'provider_ref' => $method === 'cash' ? null : Str::upper(Str::random(12)),
            ]],
        ];
    }

    private function event(int $outletId, string $source, array $payload): array
    {
        return [
            'event_id' => (string) Str::uuid7(), 'source_id' => $source, 'outlet_id' => $outletId,
            'entity_type' => 'order', 'entity_id' => $payload['id'], 'entity_version' => 3, 'event_type' => 'order.finalized',
            'payload' => $payload, 'checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    private function sapMappings(): void
    {
        $map = fn (string $type, string $key, string $code, ?array $attrs = null) => SapMapping::create([
            'company_db' => self::COMPANY_DB, 'entity_type' => $type, 'entity_key' => $key, 'sap_code' => $code, 'attributes' => $attrs,
        ]);
        foreach (Outlet::all() as $o) {
            $map('outlet', (string) $o->id, 'WH-'.$o->code);
        }
        foreach (Product::whereNotIn('code', self::UNMAPPED)->get() as $p) {
            $map('product', (string) $p->id, 'IT'.$p->code, ['tax_code' => $p->type === 'menu' ? 'PB1' : 'PPN11']);
        }
        foreach (['cash' => '1110004', 'qris' => '1120301', 'edc' => '1120201', 'edc_manual' => '1120202'] as $method => $account) {
            $map('payment_method', $method, $account);
        }
        $map('customer', 'walk_in', 'C-UMUM');
    }

    /** Posting penjualan sebelum hari ini ke SAP tiruan, termasuk satu timeout, satu parsial dan satu gagal. */
    private function postToSap(): void
    {
        $fake = new FakeSapClient;
        $fake->timeoutAfterCommitOnce = true;
        $sap = new SapPostingService($fake, self::COMPANY_DB);
        $today = $this->now->copy()->setTimezone(config('rasentra.display_timezone'))->toDateString();

        $events = InboxEvent::where('event_type', 'order.finalized')->where('status', 'accepted')->orderBy('id')->get()
            ->filter(fn ($e) => $e->payload['business_date'] < $today)->values();
        foreach ($events as $i => $event) {
            if ($i === 7) {
                $fake->failNext['IncomingPayments'] = 1;
            }
            if ($i === 13) {
                $fake->failNext['Invoices'] = 1;
            }
            if ($sap->postSale($event->payload)['status'] === 'posted') {
                $event->update(['status' => 'processed']);
            }
        }
    }

    /** Pekerjaan yang menunggu persetujuan rekan lain (maker-checker). */
    private function pendingApprovals(): void
    {
        $designer = app(PromotionDesigner::class);
        $laras = $this->hq['merch'];
        $start = $this->now->copy()->addDay()->startOfDay();

        $designer->create($laras, 'JMS-KAMBING-WEEKEND', 'Daging kambing akhir pekan hemat 8%', [
            'type' => 'percent', 'scope' => 'item', 'rules' => ['categories' => ['Daging Kambing'], 'percent' => '8'],
            'channels' => ['retail'], 'priority' => 25, 'stacking' => 'exclusive', 'funded_by' => 'internal',
            'starts_at' => $start, 'ends_at' => $start->copy()->addDays(30),
        ]);

        $salmon = Product::where('code', '2201004')->first();
        if ($salmon) {
            $version = $designer->create($laras, 'JMS-SALMON-SUPPLIER', 'Salmon Norwegia diskon 15% (ditanggung supplier)', [
                'type' => 'percent', 'scope' => 'item', 'rules' => ['product_ids' => [$salmon->id], 'percent' => '15', 'max_discount' => '150000'],
                'channels' => ['retail'], 'priority' => 18, 'stacking' => 'exclusive', 'funded_by' => 'supplier',
                'starts_at' => $start, 'ends_at' => $start->copy()->addDays(14),
            ]);
            $designer->simulate($version, $laras, $this->jms->id, 'retail', [['product_id' => $salmon->id, 'quantity' => '1.250']]);
        }

        $current = PriceVersion::where('outlet_id', $this->jms->id)->where('status', 'published')->latest('effective_from')->first();
        $publisher = app(PricePublisher::class);
        $draft = $publisher->draft($laras, 'JMS-2026-11', $this->jms->id, null, $this->now->copy()->addMonthNoOverflow()->startOfMonth(), $current, 'Penyesuaian harga daging sapi impor November');
        $ids = Product::whereIn('code', ['2101002', '2101003', '2101004'])->pluck('id', 'code');
        $publisher->setItems($draft, $laras, array_filter([
            $ids['2101002'] ?? null => '345000', $ids['2101003'] ?? null => '305000', $ids['2101004'] ?? null => '795000',
        ], fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH));
    }
}
