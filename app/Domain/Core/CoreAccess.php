<?php

namespace App\Domain\Core;

use App\Models\User;

/**
 * Hak akses Rasentra Core per peran kantor pusat. Admin pusat memegang semua area;
 * merchandising mengatur katalog, harga, promo dan resep; finance memantau
 * sinkronisasi, SAP dan audit. Petugas outlet tidak dapat masuk ke Core.
 */
final class CoreAccess
{
    public const ROLES = [
        'admin' => 'Admin pusat',
        'hq_merch' => 'Merchandising pusat',
        'hq_finance' => 'Finance pusat',
    ];

    private const AREAS = [
        'admin' => ['*'],
        'hq_merch' => ['dashboard', 'catalog', 'promotions', 'recipes', 'reports'],
        'hq_finance' => ['dashboard', 'monitoring', 'reports', 'audit'],
    ];

    public static function isCoreUser(?User $user): bool
    {
        return $user !== null && ($user->is_active ?? true) && array_key_exists($user->role, self::ROLES);
    }

    public static function allows(?User $user, string $area): bool
    {
        if (! self::isCoreUser($user)) {
            return false;
        }
        $areas = self::AREAS[$user->role];

        return in_array('*', $areas, true) || in_array($area, $areas, true);
    }
}
