<?php

namespace Database\Seeders;

use App\Domain\Catalog\PricePublisher;
use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClientFactory;
use App\Domain\Integration\Sap\SapHealth;
use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Promotion\PromotionDesigner;
use App\Domain\Sync\InboxReceiver;
use App\Models\BusinessUnit;
use App\Models\CookingFeeRule;
use App\Models\DailyReconciliation;
use App\Models\Device;
use App\Models\InboxEvent;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\OutletSyncState;
use App\Models\PriceItem;
use App\Models\PriceVersion;
use App\Models\Product;
use App\Models\PromotionVersion;
use App\Models\PublicationVersion;
use App\Models\RecipeVersion;
use App\Models\SapMapping;
use App\Models\User;
use App\Support\Decimal;
use App\Support\SystemState;
use Brick\Math\BigDecimal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Data demo Rasentra Core (kantor pusat): pengguna pusat, perangkat dan token, riwayat
 * penjualan 7 hari dari Solo dan Lombok lewat inbox Rasentra Central, mapping dan posting
 * per instance SAP tiruan (termasuk kasus perlu review), serta promo dan versi harga yang menunggu persetujuan.
 * Dijalankan setelah SoloSeeder dan LombokSeeder.
 */
class CoreSeeder extends Seeder
{
    /** Produk yang sengaja belum dimapping ke SAP; muncul di beberapa struk kemarin sebagai contoh masalah integrasi. */
    private const UNMAPPED = ['2201008'];

    private int $unmappedOrders = 3;

    private Carbon $now;

    private Outlet $solo;

    /** @var array<string, User> */
    private array $hq = [];

    public function run(): void
    {
        $solo = Outlet::where('code', 'SLO')->first();
        if (! $solo || User::where('email', 'admin@rasentra.test')->exists()) {
            return;
        }
        $this->solo = $solo;
        $this->now = Carbon::now();
        mt_srand(2026);

        $this->users();
        $this->devices();
        $this->syncJmsOutbox();
        $this->salesHistory();
        $this->sapMappings();
        $this->postToSap();
        $this->pendingApprovals();
        $this->centralState();
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
        $id = $this->solo->id;
        foreach ([
            ['SRV-SLO', 'outlet_server', 'Rasentra Lokal Solo', 1],
            ['POS-SLO-01', 'pos', 'Kasir retail 1', 2],
            ['POS-SLO-02', 'pos', 'Kasir resto', 2],
            ['TRM-SLO-01', 'terminal', 'Tablet pelayan Lusi', 6],
            ['KDS-SLO-GRILL', 'kitchen', 'Layar dapur grill', 1],
            ['KDS-SLO-HOT', 'kitchen', 'Layar dapur hot kitchen', 1],
            ['KDS-SLO-EXPO', 'kitchen', 'Layar expeditor', 1],
            ['INV-SLO-01', 'inventory', 'Handheld gudang Wawan', 40],
        ] as [$code, $app, $name, $minutes]) {
            Device::create([
                'outlet_id' => $id, 'code' => $code, 'app' => $app, 'name' => $name,
                'token_hash' => hash('sha256', 'demo-'.Str::lower($code)), 'last_seen_at' => $this->now->copy()->subMinutes($minutes),
            ]);
        }
        Device::create(['outlet_id' => $id, 'code' => 'POS-SLO-03', 'app' => 'pos', 'name' => 'Kasir lama (dicabut)', 'token_hash' => null, 'is_active' => false]);
    }

    /** Kirim event outbox Solo hari ini ke inbox Central; dua event terakhir masih antre. */
    private function syncJmsOutbox(): void
    {
        OutboxEvent::where('outlet_id', $this->solo->id)->update(['source_id' => 'SRV-SLO']);
        $events = OutboxEvent::where('outlet_id', $this->solo->id)->orderBy('id')->get();
        $send = $events->slice(0, max(0, $events->count() - 2));

        $results = collect(app(InboxReceiver::class)->receive($send->map(fn (OutboxEvent $e) => $e->only([
            'event_id', 'source_id', 'outlet_id', 'entity_type', 'entity_id', 'entity_version', 'event_type', 'payload', 'checksum',
        ]))->values()->all(), 'SRV-SLO'))->keyBy('event_id');

        foreach ($send as $event) {
            $r = $results[$event->event_id] ?? null;
            if ($r && in_array($r['result'], ['accepted', 'duplicate'], true)) {
                $event->update(['status' => 'acked', 'ack_ref' => $r['ack_ref'], 'attempts' => 1]);
                InboxEvent::where('event_id', $event->event_id)->update(['created_at' => $event->occurred_at->copy()->addSeconds(5), 'updated_at' => $event->occurred_at->copy()->addSeconds(5)]);
            }
        }
        $events->last()?->update(['status' => 'failed', 'attempts' => 3, 'last_error' => 'cURL error 28: Connection timed out after 15000 ms', 'next_attempt_at' => $this->now->copy()->addMinute()]);
    }

    /**
     * Riwayat penjualan 7 hari untuk Solo dan Lombok, dikirim lewat inbox seperti event asli.
     * Harga dan promo memakai versi yang terbit di masing-masing outlet.
     */
    private function salesHistory(): void
    {
        $lombok = Outlet::where('code', 'LBK')->first();
        foreach (array_filter([$this->solo, $lombok]) as $outlet) {
            $source = 'SRV-'.$outlet->code;
            if ($outlet->id !== $this->solo->id) {
                Device::create([
                    'outlet_id' => $outlet->id, 'code' => $source, 'app' => 'outlet_server', 'name' => 'Rasentra Lokal '.$outlet->name,
                    'token_hash' => hash('sha256', 'demo-'.Str::lower($source)), 'last_seen_at' => $this->now->copy()->subMinutes(2),
                ]);
            }
            $prices = PriceItem::query()->whereIn('price_version_id', PriceVersion::where('status', 'published')->where('outlet_id', $outlet->id)->pluck('id'))
                ->pluck('unit_price', 'product_id');
            $products = Product::whereIn('id', $prices->keys())->get();
            $unmapped = $products->whereIn('code', self::UNMAPPED)->values();
            $products = $products->whereNotIn('code', self::UNMAPPED);
            $retail = $products->whereIn('type', ['retail', 'weighed'])->values();
            $menus = $products->where('type', 'menu')->values();
            $promos = PromotionVersion::where('status', 'published')->whereHas('targets', fn ($q) => $q->where('outlet_id', $outlet->id))->get();
            $today = $this->now->copy()->setTimezone($outlet->timezone)->startOfDay();
            $scale = $outlet->id === $this->solo->id ? 1.6 : 1.2;

            for ($d = 6; $d >= 0; $d--) {
                // Hari ini Solo sudah punya struk nyata dari SoloSeeder.
                if ($d === 0 && $outlet->id === $this->solo->id) {
                    continue;
                }
                $day = $today->copy()->subDays($d);
                $weekend = $day->isWeekend() ? 1.35 : 1.0;
                $count = (int) round(mt_rand(14, 22) * $scale * $weekend * ($d === 0 ? 0.45 : 1));
                $events = [];
                $times = [];
                for ($i = 1; $i <= $count; $i++) {
                    $at = $this->orderTime($day, $d === 0);
                    $dineIn = $menus->isNotEmpty() && mt_rand(1, 100) <= 40;
                    $pool = $dineIn ? $menus : $retail;
                    if ($d === 1 && ! $dineIn && $outlet->id === $this->solo->id && $this->unmappedOrders-- > 0) {
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
        $this->touchLastSync();
    }

    /** Outlet yang tersambung mengirim event terakhir beberapa menit lalu. */
    private function touchLastSync(): void
    {
        foreach (InboxEvent::query()->groupBy('outlet_id')->selectRaw('MAX(id) as id')->pluck('id') as $id) {
            $at = $this->now->copy()->subSeconds(mt_rand(40, 240));
            InboxEvent::whereKey($id)->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }

    private function orderTime(Carbon $day, bool $isToday): Carbon
    {
        $open = $day->copy()->setTime(8, 0)->utc();
        $close = $isToday ? $this->now->copy()->subMinutes(3) : $day->copy()->setTime(21, 0)->utc();
        if ($close->lessThanOrEqualTo($open)) {
            $open = $close->copy()->subHours(2);
        }

        return $open->copy()->addSeconds(mt_rand(0, max(1, (int) $open->diffInSeconds($close))));
    }

    /** Persen potongan promo outlet yang berlaku untuk satu baris (persen biasa atau tier berat). */
    private function promoFor(Collection $promos, Product $p, string $qty, string $channel): array
    {
        foreach ($promos->sortBy('priority') as $v) {
            $rules = $v->rules;
            if (! in_array($v->type, ['percent', 'weight_tier'], true) || ! in_array($p->category, $rules['categories'] ?? [], true)) {
                continue;
            }
            if (! empty($v->channels) && ! in_array($channel, $v->channels, true)) {
                continue;
            }
            $percent = $v->type === 'percent' ? (float) $rules['percent'] : 0;
            foreach ($v->type === 'weight_tier' ? $rules['tiers'] : [] as $tier) {
                if ((float) $qty >= (float) $tier['min_weight']) {
                    $percent = (float) $tier['percent'];
                }
            }
            if ($percent > 0) {
                return [$v, $percent, $rules['max_discount'] ?? null];
            }
        }

        return [null, 0, null];
    }

    private function syntheticOrder(Outlet $outlet, Carbon $day, Carbon $at, int $seq, Collection $pool, Collection $prices, Collection $promos, bool $dineIn): array
    {
        $lines = [];
        $channel = $dineIn ? 'dine_in' : 'retail';
        $picked = $pool->random(min($pool->count(), mt_rand(1, 4)));
        foreach ($picked->values() as $n => $p) {
            $qty = $p->is_weighed ? number_format(mt_rand(350, 2600) / 1000, 3, '.', '') : (string) mt_rand(1, $dineIn ? 3 : 2);
            $price = (string) $prices[$p->id];
            $gross = Decimal::money(Decimal::mul($qty, $price));
            [$promo, $percent, $max] = $this->promoFor($promos, $p, $qty, $channel);
            $amount = Decimal::of($gross)->multipliedBy((string) $percent)->dividedBy(100, 2, Decimal::rounding());
            $discount = $percent ? Decimal::money($max !== null ? BigDecimal::min($amount, Decimal::of((string) $max)) : $amount) : '0.00';
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
            'channel' => $channel,
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

    /** Mapping per instance SAP: setiap CV punya ItemCode, gudang dan akun kas/bank sendiri. */
    private function sapMappings(): void
    {
        $accounts = [
            'sap_solo' => ['cash' => '1110004', 'qris' => '1120301', 'edc' => '1120201', 'edc_manual' => '1120202'],
            'sap_lombok' => ['cash' => '1110104', 'qris' => '1120311', 'edc' => '1120211', 'edc_manual' => '1120212'],
        ];
        foreach (BusinessUnit::whereNotNull('sap_company_db')->with('outlets')->get() as $bu) {
            $db = $bu->sap_company_db;
            $map = fn (string $type, string $key, string $code, ?array $attrs = null) => SapMapping::create([
                'company_db' => $db, 'entity_type' => $type, 'entity_key' => $key, 'sap_code' => $code, 'attributes' => $attrs,
            ]);
            foreach ($bu->outlets as $o) {
                $map('outlet', (string) $o->id, 'WH-'.$o->code);
            }
            // Contoh masalah integrasi: Kepiting Bakau belum dibuat di sap_solo.
            foreach (Product::when($db === 'sap_solo', fn ($q) => $q->whereNotIn('code', self::UNMAPPED))->get() as $p) {
                $map('product', (string) $p->id, 'IT'.$p->code, ['tax_code' => $p->type === 'menu' ? 'PB1' : 'PPN11']);
            }
            foreach ($accounts[$db] ?? $accounts['sap_solo'] as $method => $account) {
                $map('payment_method', $method, $account);
            }
            $map('customer', 'walk_in', 'C-UMUM');
        }
    }

    /** Posting penjualan sebelum hari ini ke SAP tiruan, termasuk satu timeout, satu parsial dan satu gagal. */
    private function postToSap(): void
    {
        $fake = new FakeSapClient;
        $fake->timeoutAfterCommitOnce = true;
        $sap = new SapPostingService(new SapClientFactory($fake));
        $today = Outlet::pluck('id')->mapWithKeys(fn ($id) => [$id => Outlet::businessDate($id)]);

        $events = InboxEvent::where('event_type', 'order.finalized')->where('status', 'accepted')->orderBy('id')->get()
            ->filter(fn ($e) => $e->payload['business_date'] < $today[$e->outlet_id])->values();
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

        $designer->create($laras, 'SLO-KAMBING-WEEKEND', 'Daging kambing akhir pekan hemat 8%', [
            'type' => 'percent', 'scope' => 'item', 'rules' => ['categories' => ['Daging Kambing'], 'percent' => '8'],
            'channels' => ['retail'], 'priority' => 25, 'stacking' => 'exclusive', 'funded_by' => 'internal',
            'starts_at' => $start, 'ends_at' => $start->copy()->addDays(30),
        ]);

        $salmon = Product::where('code', '2201004')->first();
        if ($salmon) {
            $version = $designer->create($laras, 'SLO-SALMON-SUPPLIER', 'Salmon Norwegia diskon 15% (ditanggung supplier)', [
                'type' => 'percent', 'scope' => 'item', 'rules' => ['product_ids' => [$salmon->id], 'percent' => '15', 'max_discount' => '150000'],
                'channels' => ['retail'], 'priority' => 18, 'stacking' => 'exclusive', 'funded_by' => 'supplier',
                'starts_at' => $start, 'ends_at' => $start->copy()->addDays(14),
            ]);
            $designer->simulate($version, $laras, $this->solo->id, 'retail', [['product_id' => $salmon->id, 'quantity' => '1.250']]);
        }

        $current = PriceVersion::where('outlet_id', $this->solo->id)->where('status', 'published')->latest('effective_from')->first();
        $publisher = app(PricePublisher::class);
        $draft = $publisher->draft($laras, 'SLO-2026-11', $this->solo->id, null, $this->now->copy()->addMonthNoOverflow()->startOfMonth(), $current, 'Penyesuaian harga daging sapi impor November');
        $ids = Product::whereIn('code', ['2101002', '2101003', '2101004'])->pluck('id', 'code');
        $publisher->setItems($draft, $laras, array_filter([
            $ids['2101002'] ?? null => '345000', $ids['2101003'] ?? null => '305000', $ids['2101004'] ?? null => '795000',
        ], fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Kondisi Rasentra Central untuk demo: heartbeat kedua server Lokal (Lombok tertinggal dua
     * publikasi), tutup hari 6 hari terakhir (satu hari Lombok masih menunggu struk), cek SAP
     * dan jejak job terjadwal.
     */
    private function centralState(): void
    {
        $this->publishCurrent();
        $publications = PublicationVersion::orderBy('id')->get(['id', 'outlet_ids']);
        foreach (Outlet::whereIn('code', ['SLO', 'LBK'])->get() as $outlet) {
            $server = Device::where('outlet_id', $outlet->id)->where('app', 'outlet_server')->first();
            $mine = $publications->filter(fn ($p) => $p->outlet_ids === null || in_array($outlet->id, $p->outlet_ids, true))->pluck('id')->values();
            $solo = $outlet->code === 'SLO';
            $queue = OutboxEvent::where('outlet_id', $outlet->id)->whereIn('status', ['pending', 'failed']);
            OutletSyncState::create([
                'device_id' => $server->id, 'outlet_id' => $outlet->id,
                'last_heartbeat_at' => $this->now->copy()->subSeconds($solo ? 35 : 50),
                'last_pull_at' => $this->now->copy()->subSeconds($solo ? 20 : 25),
                'applied_cursor' => $solo || $mine->count() < 3 ? (int) $publications->max('id') : $mine[$mine->count() - 3],
                'outbox_pending' => (clone $queue)->where('status', 'pending')->count(),
                'outbox_failed' => (clone $queue)->where('status', 'failed')->count(),
                'outbox_oldest_at' => (clone $queue)->min('occurred_at'),
                'last_sent_at' => $this->now->copy()->subMinutes($solo ? 4 : 2),
                'app_version' => $solo ? '1.0.0' : '0.9.8',
                'clock_skew_seconds' => $solo ? 2 : -1,
                'open_business_date' => Outlet::businessDate($outlet->id),
                'open_shifts' => $solo ? 2 : 1,
            ]);
            $server->update(['last_seen_at' => $this->now->copy()->subSeconds($solo ? 35 : 50)]);

            $today = Outlet::businessDate($outlet->id);
            for ($d = 6; $d >= 1; $d--) {
                $date = Carbon::parse($today)->subDays($d)->toDateString();
                $orders = DB::table('hq_orders')->where('outlet_id', $outlet->id)->whereDate('business_date', $date)->orderBy('doc_no')->get();
                if ($orders->isEmpty()) {
                    continue;
                }
                $docs = $orders->pluck('doc_no')->all();
                $net = Decimal::sum($orders->pluck('net_amount'));
                // Lombok dua hari lalu: satu struk sore masih tertahan di server outlet.
                $late = ! $solo && $d === 2;
                if ($late) {
                    $docs[] = $outlet->code.'-'.str_replace('-', '', substr($date, 2)).'-LATE1';
                    $net = $net->plus('187500');
                }
                $cash = ($solo ? ['0', '-2000', '5000', '0', '-500', '0'] : ['-1500', '0', '0', '2500', '0', '-1000'])[$d - 1];
                $payments = DB::table('hq_order_payments')->whereIn('hq_order_id', $orders->pluck('id'))->get()->groupBy('method')
                    ->map(fn ($g) => Decimal::money(Decimal::sum($g->pluck('amount'))))->all();
                DailyReconciliation::create([
                    'outlet_id' => $outlet->id, 'business_date' => $date, 'closing_version' => 1,
                    'closed_at' => Carbon::parse($date, $outlet->timezone)->addDay()->setTime(4, 0)->utc(),
                    'local_orders' => count($docs), 'local_net' => Decimal::money($net),
                    'central_orders' => $orders->count(), 'central_net' => Decimal::money(Decimal::sum($orders->pluck('net_amount'))),
                    'cash_difference' => $cash, 'shifts' => 2, 'open_shifts' => 0,
                    'status' => $late ? 'waiting' : 'matched',
                    'missing_doc_nos' => $late ? [end($docs)] : [], 'extra_doc_nos' => [],
                    'local_summary' => [
                        'outlet_code' => $outlet->code, 'business_date' => $date, 'orders' => count($docs), 'net_amount' => Decimal::money($net),
                        'by_channel' => $orders->groupBy('channel')->map(fn ($g) => ['orders' => $g->count(), 'net_amount' => Decimal::money(Decimal::sum($g->pluck('net_amount')))])->all(),
                        'payments' => $payments, 'doc_nos' => $docs, 'cash_difference' => $cash,
                    ],
                    'checked_at' => $this->now->copy()->subMinutes(20),
                ]);
            }
        }

        // Sisi Rasentra Lokal Solo (demo memakai satu database): heartbeat dan riwayat tarik publikasi.
        $max = (int) $publications->max('id');
        SystemState::put('sync:cursor', $max);
        SystemState::put('sync:last_heartbeat', ['ok' => true, 'clock_skew_seconds' => 2, 'central_cursor' => $max, 'at' => $this->now->copy()->subSeconds(35)->toIso8601ZuluString()]);
        SystemState::put('sync:last_ok_heartbeat', $this->now->copy()->subSeconds(35)->toIso8601ZuluString());
        $log = [
            ['at' => $this->now->copy()->subMinutes(50), 'applied' => 1, 'cursor' => $max, 'error' => null, 'types' => ['promotion_version' => 1]],
            ['at' => $this->now->copy()->subHours(5), 'applied' => 3, 'cursor' => $max - 1, 'error' => null, 'types' => ['product' => 2, 'user' => 1]],
            ['at' => $this->now->copy()->subDays(1)->setTime(1, 0), 'applied' => 0, 'cursor' => $max - 1, 'error' => 'cURL error 6: Could not resolve host: central.indoguna.local', 'types' => []],
        ];
        SystemState::put('sync:pull_log', array_map(fn ($l) => ['at' => $l['at']->toIso8601ZuluString()] + $l, $log));
        SystemState::put('sync:last_pull', ['at' => $this->now->copy()->subSeconds(20)->toIso8601ZuluString(), 'applied' => 0, 'cursor' => $max, 'error' => null, 'types' => []]);

        app(SapHealth::class)->checkAll();
        SystemState::ran('sap-check', ['ok' => BusinessUnit::whereNotNull('sap_company_db')->count(), 'failed' => 0]);
        SystemState::ran('post-sap', ['posted' => DB::table('sap_documents')->where('status', 'posted')->count()]);
        SystemState::ran('reconcile', ['checked' => 1]);
    }

    /** Riwayat cursor publikasi untuk harga, promo, resep dan cooking fee yang sudah berlaku. */
    private function publishCurrent(): void
    {
        if (PublicationVersion::exists()) {
            return;
        }
        $items = collect();
        PriceVersion::where('status', 'published')->get()->each(fn ($v) => $items->push([$v->published_at ?? $v->effective_from, 'price_version', $v->id,
            $v->toArray() + ['items' => $v->items()->get(['product_id', 'unit_price'])->toArray()], $v->outlet_id ? [$v->outlet_id] : null]));
        PromotionVersion::where('status', 'published')->get()->each(fn ($v) => $items->push([$v->published_at ?? $v->starts_at, 'promotion_version', $v->id,
            $v->toArray(), $v->targets()->pluck('outlet_id')->all()]));
        RecipeVersion::with('lines')->where('status', 'approved')->get()->each(fn ($v) => $items->push([$v->effective_from ?? $v->created_at, 'recipe_version', $v->id,
            $v->toArray(), $v->outlet_ids]));
        CookingFeeRule::all()->each(fn ($r) => $items->push([$r->effective_from ?? $r->created_at, 'cooking_fee_rule', $r->id, $r->toArray(), $r->outlet_id ? [$r->outlet_id] : null]));

        foreach ($items->sortBy(fn ($i) => Carbon::parse($i[0])->getTimestamp()) as [$at, $type, $id, $payload, $outlets]) {
            PublicationVersion::create(['entity_type' => $type, 'entity_key' => (string) $id, 'payload' => $payload, 'outlet_ids' => $outlets]);
        }
    }
}
