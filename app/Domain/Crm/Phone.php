<?php

namespace App\Domain\Crm;

/**
 * Normalisasi nomor HP Indonesia ke format internasional tanpa tanda plus (62812…), format
 * yang dipakai WhatsApp. "0812-3456-789", "+62 812 3456 789", "812 3456 789" dan
 * "62812…" menjadi nomor yang sama sehingga duplikat antar outlet bisa digabung.
 */
final class Phone
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        // Angka dari Excel kadang terbaca 6.28123E+11 atau 812345678.0.
        $raw = trim($raw);
        if (preg_match('/^\d+\.0+$/', $raw)) {
            $raw = substr($raw, 0, strpos($raw, '.'));
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0062')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }
        // Nomor seluler Indonesia: 62 8xx, total 10–15 digit.
        if (! preg_match('/^628\d{7,12}$/', $digits)) {
            return null;
        }

        return $digits;
    }

    /** Tampilan ramah: 0812-3456-7890. */
    public static function display(string $phone): string
    {
        $local = str_starts_with($phone, '62') ? '0'.substr($phone, 2) : $phone;

        return trim(chunk_split(substr($local, 0, 4), 4, '-').implode('-', str_split(substr($local, 4), 4)), '-');
    }

    /** Disamarkan untuk daftar: 0812-****-7890. */
    public static function masked(string $phone): string
    {
        $local = str_starts_with($phone, '62') ? '0'.substr($phone, 2) : $phone;

        return substr($local, 0, 4).'-****-'.substr($local, -4);
    }
}
