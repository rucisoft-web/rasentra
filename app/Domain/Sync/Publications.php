<?php

namespace App\Domain\Sync;

use App\Models\CookingFeeRule;
use App\Models\PriceVersion;
use App\Models\PromotionVersion;
use App\Models\PublicationVersion;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;

/**
 * Rasentra Central: menerbitkan data master ke cursor publikasi dan menyusun snapshot awal
 * untuk Rasentra Lokal yang baru dipasang. Payload berisi baris apa adanya; induk yang
 * dibutuhkan foreign key ikut dikirim di "_parents" agar Lokal bisa menerapkannya berurutan.
 */
final class Publications
{
    /** Jenis entitas yang diterapkan Rasentra Lokal dan tabel tujuannya. */
    public const TABLES = [
        'outlet' => 'outlets',
        'uom' => 'uoms',
        'product' => 'products',
        'location' => 'locations',
        'dining_table' => 'dining_tables',
        'user' => 'users',
        'price_version' => 'price_versions',
        'promotion_version' => 'promotion_versions',
        'recipe_version' => 'recipe_versions',
        'cooking_fee_rule' => 'cooking_fee_rules',
    ];

    /** Kolom yang tidak pernah dikirim ke outlet. */
    private const HIDDEN = ['users' => ['remember_token']];

    /** Terbitkan baris master terbaru. Akun kantor pusat (tanpa outlet) tidak dikirim ke outlet. */
    public function master(string $type, int $id, array $alsoOutletIds = []): ?PublicationVersion
    {
        $payload = $this->row(self::TABLES[$type], $id);
        if ($payload === null || ($type === 'user' && $payload['outlet_id'] === null && $alsoOutletIds === [])) {
            return null;
        }
        $outletIds = match ($type) {
            'outlet' => [$id],
            'location', 'dining_table', 'user' => array_values(array_unique(array_filter([$payload['outlet_id'], ...$alsoOutletIds]))),
            default => null,
        };

        return PublicationVersion::create([
            'entity_type' => $type,
            'entity_key' => (string) $id,
            'payload' => $payload,
            'outlet_ids' => $outletIds,
        ]);
    }

    /** Payload yang dikirim ke Lokal, dilengkapi induk yang dibutuhkan foreign key. */
    public function enrich(string $type, array $payload): array
    {
        $parents = match ($type) {
            'outlet' => [
                'business_units' => [$this->row('business_units', (int) $payload['business_unit_id'])],
                'regions' => [$this->row('regions', (int) $payload['region_id'])],
            ],
            'promotion_version' => ['promotions' => [$this->row('promotions', (int) $payload['promotion_id'])]],
            'recipe_version' => ['recipes' => [$this->row('recipes', (int) $payload['recipe_id'])]],
            default => [],
        };

        return $parents === [] ? $payload : $payload + ['_parents' => array_map(fn ($rows) => array_values(array_filter($rows)), $parents)];
    }

    /**
     * Snapshot lengkap untuk satu outlet, berurutan sesuai ketergantungan foreign key.
     *
     * @return array{cursor:int, changes:list<array{entity_type:string, entity_key:string, payload:array}>}
     */
    public function snapshot(int $outletId): array
    {
        $cursor = (int) PublicationVersion::max('id');
        $changes = [];
        $add = function (string $type, array $payload) use (&$changes) {
            $changes[] = ['id' => null, 'entity_type' => $type, 'entity_key' => (string) $payload['id'], 'payload' => $this->enrich($type, $payload)];
        };
        $rows = fn (string $table, ?callable $scope = null) => ($scope ? $scope(DB::table($table)) : DB::table($table))
            ->orderBy('id')->get()->map(fn ($r) => array_diff_key((array) $r, array_flip(self::HIDDEN[$table] ?? [])));

        $add('outlet', $this->row('outlets', $outletId));
        $rows('uoms')->each(fn ($r) => $add('uom', $r));
        $rows('products')->each(fn ($r) => $add('product', $r));
        foreach (['location' => 'locations', 'dining_table' => 'dining_tables', 'user' => 'users'] as $type => $table) {
            $rows($table, fn ($q) => $q->where('outlet_id', $outletId))->each(fn ($r) => $add($type, $r));
        }

        PriceVersion::where('status', 'published')->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId))
            ->orderBy('id')->get()
            ->each(fn ($v) => $add('price_version', $v->toArray() + ['items' => $v->items()->get(['product_id', 'unit_price'])->toArray()]));
        PromotionVersion::where('status', 'published')->whereHas('targets', fn ($q) => $q->where('outlet_id', $outletId))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('id')->get()->each(fn ($v) => $add('promotion_version', $v->toArray()));
        RecipeVersion::with('lines')->where('status', 'approved')->orderBy('id')->get()
            ->filter(fn ($v) => empty($v->outlet_ids) || in_array($outletId, $v->outlet_ids, true))
            ->each(fn ($v) => $add('recipe_version', $v->toArray()));
        CookingFeeRule::where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId))
            ->orderBy('id')->get()->each(fn ($r) => $add('cooking_fee_rule', $r->toArray()));

        return ['cursor' => $cursor, 'changes' => $changes];
    }

    private function row(string $table, int $id): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();

        return $row ? array_diff_key((array) $row, array_flip(self::HIDDEN[$table] ?? [])) : null;
    }
}
