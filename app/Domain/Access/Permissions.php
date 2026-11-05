<?php

namespace App\Domain\Access;

use App\Domain\Core\CoreAccess;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Manajemen izin terpusat. Setiap peran punya daftar izin; baris di tabel roles (diatur di
 * Rasentra Core → Peran & izin, lalu dipublikasikan ke semua outlet) menimpa bawaan di kode.
 * Dengan begitu satu matriks izin berlaku sama di seluruh outlet grup.
 */
final class Permissions
{
    /** Izin area Rasentra Core (kantor pusat). */
    public const CENTRAL = [
        'dashboard' => 'Dashboard grup',
        'master' => 'Perusahaan, outlet, pengguna, perangkat',
        'organization' => 'Struktur organisasi',
        'catalog' => 'Produk & harga',
        'promotions' => 'Promotion Designer',
        'recipes' => 'Resep & cooking fee',
        'members' => 'Membership & poin',
        'crm' => 'Rasentra CRM (pelanggan, segmentasi, WhatsApp)',
        'operations' => 'Meja, metode bayar, shift staf',
        'settings' => 'Pengaturan umum',
        'access' => 'Peran & izin',
        'monitoring' => 'Sinkronisasi & SAP',
        'reports' => 'Laporan & insight grup',
        'audit' => 'Audit log',
    ];

    /** Izin di outlet (Rasentra Lokal dan aplikasinya). */
    public const OUTLET = [
        'pos.sell' => 'Jual di Rasentra POS',
        'pos.approve' => 'Approval kepala (void, override harga, retur)',
        'member.enroll' => 'Daftarkan member baru',
        'member.redeem' => 'Tukar poin member',
        'self_order.accept' => 'Terima pesanan mandiri QR',
        'delivery.manage' => 'Kelola delivery order',
        'staff.roster' => 'Atur roster staf',
        'lokal.console' => 'Konsol Rasentra Lokal',
    ];

    /** Bawaan untuk peran outlet bila tabel roles belum mengisinya. */
    public const OUTLET_DEFAULTS = [
        'cashier' => ['pos.sell', 'member.enroll', 'member.redeem', 'self_order.accept', 'delivery.manage'],
        'waiter' => ['self_order.accept', 'member.enroll'],
        'outlet_head' => ['pos.sell', 'pos.approve', 'member.enroll', 'member.redeem', 'self_order.accept', 'delivery.manage', 'staff.roster', 'lokal.console'],
        'chef' => [],
        'kitchen' => [],
        'storekeeper' => [],
        'courier' => ['delivery.manage'],
    ];

    /** Bawaan tambahan area Core untuk fitur baru, di luar CoreAccess. */
    public const CENTRAL_EXTRA = [
        'hq_merch' => ['members', 'operations'],
        'hq_finance' => ['organization', 'operations'],
    ];

    /** Daftar izin peran: dari tabel roles bila ada, selain itu bawaan. */
    public static function of(string $role): array
    {
        $row = self::rows()[$role] ?? null;
        if ($row !== null) {
            return $row;
        }
        if ($role === 'admin') {
            return ['*'];
        }

        return self::OUTLET_DEFAULTS[$role] ?? self::CENTRAL_EXTRA[$role] ?? [];
    }

    public static function allows(?User $user, string $permission): bool
    {
        if ($user === null || ! ($user->is_active ?? true)) {
            return false;
        }
        if ($user->role === 'admin') {
            return true;
        }
        $perms = self::of($user->role);

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** Untuk CoreAccess: peran yang sudah diatur di tabel roles memakai matriks itu. */
    public static function centralOverride(string $role, string $area): ?bool
    {
        $row = self::rows()[$role] ?? null;
        if ($row === null) {
            return in_array($area, self::CENTRAL_EXTRA[$role] ?? [], true) ? true : null;
        }

        return in_array('*', $row, true) || in_array($area, $row, true);
    }

    /** Peran yang memegang izin tertentu (mis. siapa saja yang boleh approval). */
    public static function rolesWith(string $permission): array
    {
        $roles = array_unique([...array_keys(self::OUTLET_DEFAULTS), ...array_keys(CoreAccess::ROLES), ...array_keys(self::rows())]);

        return array_values(array_filter($roles, fn ($r) => $r === 'admin' || in_array($permission, self::of($r), true) || in_array('*', self::of($r), true)));
    }

    private static function rows(): array
    {
        // Dibaca per permintaan (tabel kecil); perubahan di Core langsung berlaku.
        if (! Schema::hasTable('roles')) {
            return [];
        }

        return Role::pluck('permissions', 'code')->map(fn ($p) => is_array($p) ? $p : (json_decode((string) $p, true) ?? []))->all();
    }
}
