<?php

namespace App\Domain\Promotion;

use App\Domain\Audit\Audit;
use App\Models\Outlet;
use App\Models\PromotionTarget;
use App\Models\PromotionVersion;
use App\Models\PublicationVersion;
use App\Models\User;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Rasentra Central: publikasi promo ke seluruh outlet, wilayah atau outlet tertentu.
 * Daftar outlet target dibekukan pada versi ini; versi published bersifat immutable.
 */
final class PromotionPublisher
{
    /** @param array{all?:bool, regions?:list<string>, outlets?:list<string>} $target */
    public function publish(PromotionVersion $version, User $approver, array $target): PromotionVersion
    {
        if ($version->status === 'published') {
            throw DomainException::conflict('already_published', 'Versi ini sudah diterbitkan; buat versi baru untuk perubahan.');
        }
        if ($version->created_by !== null && $version->created_by === $approver->id) {
            throw DomainException::needsApproval('approval_self', 'Pembuat promo tidak dapat menyetujui promonya sendiri.');
        }

        $outlets = Outlet::query()->where('is_active', true)
            ->when(empty($target['all']), function ($q) use ($target) {
                $q->where(function ($q) use ($target) {
                    $q->whereIn('code', $target['outlets'] ?? [])
                        ->orWhereHas('region', fn ($r) => $r->whereIn('code', $target['regions'] ?? []));
                });
            })
            ->get();

        if ($outlets->isEmpty()) {
            throw new DomainException('no_target', 'Tidak ada outlet target yang aktif.');
        }

        return DB::transaction(function () use ($version, $approver, $outlets) {
            foreach ($outlets as $outlet) {
                PromotionTarget::create(['promotion_version_id' => $version->id, 'outlet_id' => $outlet->id]);
            }
            $version->update(['status' => 'published', 'approved_by' => $approver->id, 'published_at' => now()]);

            PublicationVersion::create([
                'entity_type' => 'promotion_version',
                'entity_key' => (string) $version->id,
                'payload' => $version->fresh()->toArray(),
                'outlet_ids' => $outlets->pluck('id')->all(),
            ]);
            Audit::record('promotion.published', $version, $approver->id, after: ['outlets' => $outlets->pluck('code')->all()]);

            return $version->fresh('targets');
        });
    }

    /** Status penerimaan per outlet untuk dashboard pusat. */
    public function receiptStatus(PromotionVersion $version): array
    {
        return $version->targets()->join('outlets', 'outlets.id', '=', 'promotion_targets.outlet_id')
            ->get(['outlets.code', 'outlets.name', 'promotion_targets.ack_status', 'promotion_targets.acked_at'])
            ->toArray();
    }
}
