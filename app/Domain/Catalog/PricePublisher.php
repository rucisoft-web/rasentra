<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\Audit;
use App\Models\PriceItem;
use App\Models\PriceVersion;
use App\Models\PublicationVersion;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rasentra Central: versi harga dibuat sebagai draft, diisi, lalu diterbitkan oleh orang
 * lain (maker-checker). Versi terbit tidak diubah; perubahan harga = versi baru dengan
 * tanggal efektif baru, sehingga harga di struk lama tetap tertelusur.
 */
final class PricePublisher
{
    public function draft(User $user, string $code, ?int $outletId, ?string $channel, Carbon $effectiveFrom, ?PriceVersion $copyFrom = null, ?string $note = null): PriceVersion
    {
        return DB::transaction(function () use ($user, $code, $outletId, $channel, $effectiveFrom, $copyFrom, $note) {
            $version = PriceVersion::create([
                'code' => $code,
                'outlet_id' => $outletId,
                'channel' => $channel,
                'effective_from' => $effectiveFrom,
                'status' => 'draft',
                'created_by' => $user->id,
                'note' => $note,
            ]);
            if ($copyFrom !== null) {
                foreach ($copyFrom->items as $item) {
                    PriceItem::create(['price_version_id' => $version->id, 'product_id' => $item->product_id, 'unit_price' => $item->unit_price]);
                }
            }
            Audit::record('price_version.drafted', $version, $user->id, after: ['code' => $code, 'copy_from' => $copyFrom?->code], outletId: $outletId);

            return $version;
        });
    }

    /** @param array<int|string, string|null> $prices product_id => harga; kosong = hapus dari versi */
    public function setItems(PriceVersion $version, User $user, array $prices): int
    {
        $this->assertDraft($version);

        return DB::transaction(function () use ($version, $user, $prices) {
            $changed = 0;
            foreach ($prices as $productId => $price) {
                $existing = PriceItem::where('price_version_id', $version->id)->where('product_id', $productId)->first();
                if ($price === null || trim((string) $price) === '') {
                    if ($existing) {
                        $existing->delete();
                        $changed++;
                    }

                    continue;
                }
                if (! is_numeric($price)) {
                    throw new DomainException('price_invalid', "Harga {$price} bukan angka.");
                }
                $value = Decimal::of((string) $price);
                if ($value->isNegative()) {
                    throw new DomainException('price_negative', 'Harga tidak boleh negatif.');
                }
                $value = Decimal::money($value);
                if ($existing === null) {
                    PriceItem::create(['price_version_id' => $version->id, 'product_id' => $productId, 'unit_price' => $value]);
                    $changed++;
                } elseif (! Decimal::of($existing->unit_price)->isEqualTo(Decimal::of($value))) {
                    $existing->update(['unit_price' => $value]);
                    $changed++;
                }
            }
            if ($changed > 0) {
                Audit::record('price_version.items_changed', $version, $user->id, after: ['changed' => $changed], outletId: $version->outlet_id);
            }

            return $changed;
        });
    }

    public function publish(PriceVersion $version, User $approver): PriceVersion
    {
        $this->assertDraft($version);
        if ($version->created_by !== null && (int) $version->created_by === (int) $approver->id) {
            throw DomainException::needsApproval('approval_self', 'Pembuat versi harga tidak dapat menerbitkannya sendiri. Minta rekan pusat lain untuk menerbitkan.');
        }
        if (! $version->items()->exists()) {
            throw new DomainException('price_empty', 'Versi harga belum berisi produk.');
        }

        return DB::transaction(function () use ($version, $approver) {
            $version->update(['status' => 'published', 'published_by' => $approver->id, 'published_at' => now()]);
            PublicationVersion::create([
                'entity_type' => 'price_version',
                'entity_key' => (string) $version->id,
                'payload' => $version->fresh()->toArray() + ['items' => $version->items()->get(['product_id', 'unit_price'])->toArray()],
                'outlet_ids' => $version->outlet_id ? [$version->outlet_id] : null,
            ]);
            Audit::record('price_version.published', $version, $approver->id, approverId: $approver->id, outletId: $version->outlet_id,
                after: ['items' => $version->items()->count(), 'effective_from' => $version->effective_from->toIso8601String()]);

            return $version->fresh();
        });
    }

    private function assertDraft(PriceVersion $version): void
    {
        if ($version->status !== 'draft') {
            throw DomainException::conflict('price_published', 'Versi harga yang sudah terbit tidak dapat diubah. Buat versi baru.');
        }
    }
}
