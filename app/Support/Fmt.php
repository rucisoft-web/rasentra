<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Format tampilan Rasentra Core: rupiah, berat dan waktu lokal. */
final class Fmt
{
    public static function money(string|int|float|null $value): string
    {
        $v = Decimal::of($value ?? '0')->toScale(0, Decimal::rounding());

        return ($v->isNegative() ? '-Rp' : 'Rp').number_format((float) (string) $v->abs(), 0, ',', '.');
    }

    public static function qty(string|int|float|null $value, int $scale = 3): string
    {
        return number_format((float) (string) Decimal::of($value ?? '0'), $scale, ',', '.');
    }

    public static function dt(mixed $value): string
    {
        return $value ? Carbon::parse($value)->timezone(config('rasentra.display_timezone', 'Asia/Jakarta'))->format('d M Y H:i') : '-';
    }

    public static function seconds(?int $s): string
    {
        if ($s === null) {
            return '-';
        }

        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
