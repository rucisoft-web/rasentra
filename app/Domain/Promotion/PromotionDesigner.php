<?php

namespace App\Domain\Promotion;

use App\Domain\Audit\Audit;
use App\Domain\Catalog\PriceResolver;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionVersion;
use App\Models\PublicationVersion;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Promotion Designer di Rasentra Core. Alur: draft -> simulated -> published (oleh rekan
 * lain) -> superseded/expired. Simulasi memakai Promotion Engine yang sama dengan POS,
 * sehingga hasil uji di pusat sama persis dengan hitungan kasir.
 */
final class PromotionDesigner
{
    public const TYPES = [
        'percent' => 'Diskon persen',
        'amount' => 'Potongan nominal keranjang',
        'special_price' => 'Harga spesial',
        'weight_tier' => 'Bertingkat per berat',
        'buy_x_get_y' => 'Beli X gratis Y',
    ];

    public const LINE_TYPES = ['retail_product' => 'Barang retail', 'restaurant_menu' => 'Menu resto', 'cooking_service' => 'Jasa masak'];

    public function __construct(
        private readonly PromotionEngine $engine,
        private readonly ActivePromotions $active,
        private readonly PriceResolver $prices,
        private readonly PromotionPublisher $publisher,
    ) {}

    public function create(User $user, string $code, string $name, array $attrs): PromotionVersion
    {
        return DB::transaction(function () use ($user, $code, $name, $attrs) {
            $promotion = Promotion::create(['code' => $code, 'name' => $name]);
            $version = PromotionVersion::create(['promotion_id' => $promotion->id, 'version' => 1, 'status' => 'draft', 'created_by' => $user->id] + $attrs);
            Audit::record('promotion.drafted', $version, $user->id, after: ['code' => $code] + $attrs);

            return $version;
        });
    }

    public function update(PromotionVersion $version, User $user, string $name, array $attrs): PromotionVersion
    {
        $this->assertEditable($version);

        return DB::transaction(function () use ($version, $user, $name, $attrs) {
            $before = $version->only(array_keys($attrs));
            $version->promotion->update(['name' => $name]);
            // Perubahan aturan membatalkan simulasi sebelumnya; penyunting terakhir dianggap pembuat (maker-checker).
            $version->update($attrs + ['status' => 'draft', 'created_by' => $user->id]);
            Audit::record('promotion.updated', $version, $user->id, before: $before, after: $attrs);

            return $version->fresh();
        });
    }

    public function newVersion(PromotionVersion $from, User $user): PromotionVersion
    {
        if (PromotionVersion::where('promotion_id', $from->promotion_id)->whereIn('status', ['draft', 'simulated'])->exists()) {
            throw DomainException::conflict('draft_exists', 'Promo ini sudah punya versi draft. Lanjutkan versi itu.');
        }

        return DB::transaction(function () use ($from, $user) {
            $next = (int) PromotionVersion::where('promotion_id', $from->promotion_id)->max('version') + 1;
            $version = PromotionVersion::create([
                'promotion_id' => $from->promotion_id,
                'version' => $next,
                'status' => 'draft',
                'created_by' => $user->id,
                'starts_at' => now()->greaterThan($from->starts_at) ? now()->addDay()->startOfDay() : $from->starts_at,
            ] + $from->only(['type', 'scope', 'rules', 'channels', 'priority', 'stacking', 'funded_by', 'ends_at']));
            Audit::record('promotion.new_version', $version, $user->id, after: ['from_version' => $from->version]);

            return $version;
        });
    }

    /**
     * Uji promo pada keranjang contoh di outlet dan kanal tertentu: hitung tanpa dan dengan
     * versi ini, berdampingan dengan promo lain yang sedang aktif di outlet tersebut.
     *
     * @param  list<array{product_id:int, quantity:string}>  $basket
     */
    public function simulate(PromotionVersion $version, User $user, int $outletId, string $channel, array $basket): array
    {
        if ($basket === []) {
            throw new DomainException('basket_empty', 'Isi keranjang contoh minimal satu produk.');
        }

        $lines = [];
        foreach (array_values($basket) as $i => $row) {
            $product = Product::findOrFail($row['product_id']);
            $qty = Decimal::qty($row['quantity']);
            $price = $this->prices->resolve($product->id, $outletId, $channel)['unit_price'];
            $lines[] = [
                'key' => 'L'.($i + 1),
                'product_id' => $product->id,
                'name' => $product->name,
                'uom' => $product->uom_code,
                'category' => $product->category,
                'line_type' => match ($product->type) {
                    'menu' => 'restaurant_menu',
                    'service' => 'cooking_service',
                    default => 'retail_product',
                },
                'quantity' => $qty,
                'unit_price' => $price,
                'gross' => Decimal::money(Decimal::mul($qty, $price)),
            ];
        }

        $others = array_values(array_filter(
            $this->active->for($outletId, $channel),
            fn ($p) => ! PromotionVersion::whereKey($p['id'])->where('promotion_id', $version->promotion_id)->exists(),
        ));
        $mine = [
            'id' => $version->id, 'type' => $version->type, 'scope' => $version->scope, 'rules' => $version->rules,
            'priority' => $version->priority, 'stacking' => $version->stacking, 'funded_by' => $version->funded_by,
        ];
        $channelOk = empty($version->channels) || in_array($channel, $version->channels, true);

        $before = $this->engine->evaluate($lines, $others);
        $after = $this->engine->evaluate($lines, $channelOk ? [...$others, $mine] : $others);

        $gross = Decimal::sum(array_column($lines, 'gross'));
        $mineTotal = Decimal::sum(array_map(
            fn ($l) => Decimal::sum(array_map(fn ($a) => $a['amount'], array_filter($l['allocations'], fn ($a) => (int) $a['promotion_version_id'] === $version->id))),
            $after['lines'],
        ));

        if (in_array($version->status, ['draft', 'simulated'], true)) {
            $version->update(['status' => 'simulated']);
            Audit::record('promotion.simulated', $version, $user->id, after: ['outlet_id' => $outletId, 'channel' => $channel, 'discount' => Decimal::money($mineTotal)]);
        }

        return [
            'outlet_id' => $outletId,
            'channel' => $channel,
            'channel_ok' => $channelOk,
            'lines' => $lines,
            'before' => $before,
            'after' => $after,
            'gross' => Decimal::money($gross),
            'this_discount' => Decimal::money($mineTotal),
            'other_promos' => array_column($others, 'id'),
        ];
    }

    /** @param array{all?:bool, regions?:list<string>, outlets?:list<string>} $target */
    public function publish(PromotionVersion $version, User $approver, array $target): PromotionVersion
    {
        if ($version->status !== 'simulated') {
            throw DomainException::conflict('not_simulated', 'Jalankan simulasi dulu sebelum menerbitkan promo.');
        }

        return DB::transaction(function () use ($version, $approver, $target) {
            $published = $this->publisher->publish($version, $approver, $target);
            // Versi lama dari promo yang sama berhenti saat versi baru terbit.
            PromotionVersion::where('promotion_id', $version->promotion_id)->where('id', '!=', $version->id)
                ->where('status', 'published')->update(['status' => 'superseded']);

            return $published;
        });
    }

    public function stop(PromotionVersion $version, User $user, string $reason): PromotionVersion
    {
        if ($version->status !== 'published') {
            throw DomainException::conflict('not_published', 'Hanya promo yang sedang terbit yang dapat dihentikan.');
        }

        return DB::transaction(function () use ($version, $user, $reason) {
            $version->update(['ends_at' => now(), 'status' => 'expired']);
            PublicationVersion::create([
                'entity_type' => 'promotion_version',
                'entity_key' => (string) $version->id,
                'payload' => $version->fresh()->toArray(),
                'outlet_ids' => $version->targets()->pluck('outlet_id')->all(),
            ]);
            Audit::record('promotion.stopped', $version, $user->id, reason: $reason);

            return $version->fresh();
        });
    }

    private function assertEditable(PromotionVersion $version): void
    {
        if (! in_array($version->status, ['draft', 'simulated'], true)) {
            throw DomainException::conflict('promotion_locked', 'Versi yang sudah terbit tidak dapat diubah. Buat versi baru.');
        }
    }
}
