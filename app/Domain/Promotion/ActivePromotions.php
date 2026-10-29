<?php

namespace App\Domain\Promotion;

use App\Models\PromotionVersion;
use Illuminate\Support\Carbon;

/**
 * Promo yang berlaku di outlet: versi published, outlet tercantum pada snapshot target,
 * dalam jendela waktu, dan kanal sesuai. Aturan yang sudah diterima tetap berlaku saat
 * outlet terputus dari pusat sampai masa berakhirnya.
 */
final class ActivePromotions
{
    public function for(int $outletId, string $channel, ?Carbon $at = null): array
    {
        $at ??= now();

        return PromotionVersion::query()
            ->where('status', 'published')
            ->where('starts_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->whereHas('targets', fn ($q) => $q->where('outlet_id', $outletId))
            ->get()
            ->filter(fn (PromotionVersion $v) => empty($v->channels) || in_array($channel, $v->channels, true))
            ->map(fn (PromotionVersion $v) => [
                'id' => $v->id,
                'type' => $v->type,
                'scope' => $v->scope,
                'rules' => $v->rules,
                'priority' => $v->priority,
                'stacking' => $v->stacking,
                'funded_by' => $v->funded_by,
            ])
            ->values()
            ->all();
    }
}
