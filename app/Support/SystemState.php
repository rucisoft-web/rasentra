<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Nilai kecil milik instalasi ini: cursor publikasi yang sudah diterapkan Rasentra Lokal,
 * jejak job terjadwal dan hasil cek koneksi SAP di Rasentra Central.
 */
final class SystemState
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = DB::table('system_states')->where('key', $key)->value('value');

        return $row === null ? $default : json_decode($row, true);
    }

    public static function put(string $key, mixed $value): void
    {
        DB::table('system_states')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now()]);
    }

    /** Catat bahwa job terjadwal baru selesai berjalan, untuk halaman kesehatan Rasentra Central. */
    public static function ran(string $job, array $result = []): void
    {
        self::put("job:{$job}", ['at' => now()->toIso8601ZuluString(), 'result' => $result]);
    }
}
