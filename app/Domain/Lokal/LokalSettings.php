<?php

namespace App\Domain\Lokal;

use App\Support\SystemState;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Pengaturan pairing Rasentra Lokal dengan Rasentra Central, diisi dari layar Pemasangan.
 * Disimpan di database (token terenkripsi) dan menimpa nilai .env saat aplikasi berjalan,
 * sehingga kepala outlet atau teknisi tidak perlu menyunting berkas di server.
 */
final class LokalSettings
{
    private const KEY = 'lokal:settings';

    /** @return array{central_url?:string, token?:string, outlet_code?:string, source_id?:string, paired_at?:string} */
    public static function get(): array
    {
        $value = SystemState::get(self::KEY, []);
        if (isset($value['token'])) {
            try {
                $value['token'] = Crypt::decryptString($value['token']);
            } catch (Throwable) {
                unset($value['token']); // APP_KEY berganti: pairing harus diulang
            }
        }

        return $value;
    }

    public static function save(array $settings): void
    {
        if (isset($settings['token'])) {
            $settings['token'] = Crypt::encryptString($settings['token']);
        }
        SystemState::put(self::KEY, $settings);
        self::apply();
    }

    /** Terapkan pengaturan tersimpan ke config. Dipanggil saat boot; aman bila tabel belum ada. */
    public static function apply(): void
    {
        try {
            $s = self::get();
        } catch (Throwable) {
            return;
        }
        $map = ['central_url' => 'rasentra.central.url', 'token' => 'rasentra.central.token', 'outlet_code' => 'rasentra.outlet_code', 'source_id' => 'rasentra.source_id'];
        foreach ($map as $key => $config) {
            if (! empty($s[$key])) {
                config([$config => $s[$key]]);
            }
        }
    }

    public static function isPaired(): bool
    {
        return ! empty(config('rasentra.central.url')) && ! empty(config('rasentra.central.token')) && ! empty(config('rasentra.outlet_code'));
    }
}
