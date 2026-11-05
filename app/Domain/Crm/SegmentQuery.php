<?php

namespace App\Domain\Crm;

use App\Models\Crm\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menerjemahkan aturan segmen (disimpan sebagai JSON) menjadi query pelanggan. Semua aturan
 * digabung dengan AND; daftar outlet dan tag cukup cocok salah satu (OR di dalam daftar).
 */
final class SegmentQuery
{
    public const LIFECYCLES = [
        'new' => 'Baru (belanja pertama ≤ 30 hari)',
        'loyal' => 'Loyal (≥ 5 kali, aktif ≤ 60 hari)',
        'active' => 'Aktif (≤ 60 hari)',
        'at_risk' => 'Berisiko (61–120 hari)',
        'dormant' => 'Tidur (> 120 hari)',
        'never' => 'Belum pernah belanja',
    ];

    public const RULE_KEYS = ['outlet_ids', 'tag_ids', 'wa', 'lifecycle', 'active_within_days', 'inactive_days', 'min_purchase_count', 'min_total_spent', 'birthday_month', 'city'];

    public static function apply(Builder $q, array $rules): Builder
    {
        $today = now()->startOfDay();

        if ($ids = array_filter(array_map('intval', (array) ($rules['outlet_ids'] ?? [])))) {
            $q->whereHas('memberships', fn ($m) => $m->whereIn('outlet_id', $ids));
        }
        if ($ids = array_filter(array_map('intval', (array) ($rules['tag_ids'] ?? [])))) {
            $q->whereHas('tags', fn ($t) => $t->whereIn('crm_tags.id', $ids));
        }
        match ($rules['wa'] ?? null) {
            'opted_in' => $q->where('wa_status', 'opted_in'),
            'reachable' => $q->where('wa_status', '!=', 'opted_out'),
            'opted_out' => $q->where('wa_status', 'opted_out'),
            default => null,
        };
        if (! empty($rules['active_within_days'])) {
            $q->where('last_purchase_on', '>=', $today->copy()->subDays((int) $rules['active_within_days'])->toDateString());
        }
        if (! empty($rules['inactive_days'])) {
            $q->whereNotNull('last_purchase_on')->where('last_purchase_on', '<', $today->copy()->subDays((int) $rules['inactive_days'])->toDateString());
        }
        if (! empty($rules['min_purchase_count'])) {
            $q->where('purchase_count', '>=', (int) $rules['min_purchase_count']);
        }
        if (! empty($rules['min_total_spent'])) {
            $q->where('total_spent', '>=', (string) $rules['min_total_spent']);
        }
        if (! empty($rules['city'])) {
            $q->where('city', 'like', '%'.$rules['city'].'%');
        }
        if (! empty($rules['birthday_month'])) {
            $month = $rules['birthday_month'] === 'current' ? (int) $today->month : (int) $rules['birthday_month'];
            $q->whereNotNull('birth_date')->where(fn ($w) => $w->whereMonth('birth_date', $month));
        }
        if (! empty($rules['lifecycle'])) {
            self::lifecycle($q, (string) $rules['lifecycle']);
        }

        return $q;
    }

    public static function lifecycle(Builder $q, string $stage): Builder
    {
        $d = fn (int $days) => now()->startOfDay()->subDays($days)->toDateString();

        return match ($stage) {
            'new' => $q->where('first_purchase_on', '>=', $d(30)),
            'loyal' => $q->where('purchase_count', '>=', 5)->where('last_purchase_on', '>=', $d(60)),
            'active' => $q->where('last_purchase_on', '>=', $d(60)),
            'at_risk' => $q->where('last_purchase_on', '<', $d(60))->where('last_purchase_on', '>=', $d(120)),
            'dormant' => $q->where('last_purchase_on', '<', $d(120)),
            'never' => $q->whereNull('last_purchase_on'),
            default => $q,
        };
    }

    public static function stageOf(Customer $c): string
    {
        if ($c->last_purchase_on === null) {
            return 'never';
        }
        $days = (int) $c->last_purchase_on->diffInDays(now()->startOfDay());
        if ($c->first_purchase_on && $c->first_purchase_on->diffInDays(now()->startOfDay()) <= 30) {
            return 'new';
        }

        return match (true) {
            $days <= 60 && $c->purchase_count >= 5 => 'loyal',
            $days <= 60 => 'active',
            $days <= 120 => 'at_risk',
            default => 'dormant',
        };
    }

    /** Bersihkan input form menjadi aturan yang disimpan. */
    public static function clean(array $input): array
    {
        $rules = array_intersect_key($input, array_flip(self::RULE_KEYS));

        return array_filter($rules, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Ringkasan aturan dalam kalimat untuk layar daftar segmen. */
    public static function describe(array $rules, array $outlets = [], array $tags = []): string
    {
        $parts = [];
        if (! empty($rules['outlet_ids'])) {
            $parts[] = 'outlet '.implode('/', array_map(fn ($id) => $outlets[$id] ?? "#{$id}", (array) $rules['outlet_ids']));
        }
        if (! empty($rules['tag_ids'])) {
            $parts[] = 'tag '.implode('/', array_map(fn ($id) => $tags[$id] ?? "#{$id}", (array) $rules['tag_ids']));
        }
        if (! empty($rules['lifecycle'])) {
            $parts[] = strtok(self::LIFECYCLES[$rules['lifecycle']] ?? $rules['lifecycle'], ' (');
        }
        if (! empty($rules['active_within_days'])) {
            $parts[] = "belanja ≤ {$rules['active_within_days']} hari";
        }
        if (! empty($rules['inactive_days'])) {
            $parts[] = "tidak belanja > {$rules['inactive_days']} hari";
        }
        if (! empty($rules['min_purchase_count'])) {
            $parts[] = "≥ {$rules['min_purchase_count']} transaksi";
        }
        if (! empty($rules['min_total_spent'])) {
            $parts[] = 'belanja ≥ Rp'.number_format((float) $rules['min_total_spent'], 0, ',', '.');
        }
        if (! empty($rules['birthday_month'])) {
            $parts[] = $rules['birthday_month'] === 'current' ? 'ulang tahun bulan ini' : 'ulang tahun bulan '.$rules['birthday_month'];
        }
        if (! empty($rules['city'])) {
            $parts[] = "kota {$rules['city']}";
        }
        $parts[] = match ($rules['wa'] ?? null) {
            'opted_in' => 'sudah opt-in WhatsApp',
            'reachable' => 'tidak opt-out WhatsApp',
            'opted_out' => 'opt-out WhatsApp',
            default => null,
        };

        return implode(', ', array_filter($parts)) ?: 'Semua pelanggan';
    }
}
