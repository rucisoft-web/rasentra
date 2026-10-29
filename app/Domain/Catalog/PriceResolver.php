<?php

namespace App\Domain\Catalog;

use App\Models\PriceItem;
use App\Support\DomainException;
use Illuminate\Support\Carbon;

/**
 * Mengambil harga dari versi harga yang berlaku untuk outlet dan kanal. Versi khusus
 * outlet/kanal didahulukan dari versi umum; saat pusat tidak terjangkau dipakai versi
 * terakhir yang sudah diterima server lokal.
 */
final class PriceResolver
{
    /** @return array{unit_price: string, price_version_id: int} */
    public function resolve(int $productId, int $outletId, ?string $channel = null, ?Carbon $at = null): array
    {
        $at ??= now();

        $item = PriceItem::query()
            ->join('price_versions as v', 'v.id', '=', 'price_items.price_version_id')
            ->where('price_items.product_id', $productId)
            ->where('v.status', 'published')
            ->where('v.effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>', $at))
            ->where(fn ($q) => $q->whereNull('v.outlet_id')->orWhere('v.outlet_id', $outletId))
            ->where(fn ($q) => $q->whereNull('v.channel')->orWhere('v.channel', $channel))
            ->orderByRaw('CASE WHEN v.outlet_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN v.channel IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('v.effective_from')
            ->select('price_items.unit_price', 'price_items.price_version_id')
            ->first();

        if ($item === null) {
            throw new DomainException('price_not_found', 'Harga belum tersedia untuk produk ini di outlet.');
        }

        return ['unit_price' => (string) $item->unit_price, 'price_version_id' => (int) $item->price_version_id];
    }
}
