<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Aritmetika uang dan berat tanpa float. Kuantitas memakai skala 6 (DECIMAL(18,6)),
 * rupiah memakai skala 2; metode pembulatan mengikuti konfigurasi Finance.
 */
final class Decimal
{
    public static function of(string|int|float|BigDecimal|null $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            // Float hanya diterima dari literal konfigurasi; dikonversi lewat string agar tidak bias biner.
            $value = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        return BigDecimal::of((string) $value);
    }

    public static function money(string|int|BigDecimal $value): string
    {
        return (string) self::of($value)->toScale(config('rasentra.money.scale', 2), self::rounding());
    }

    public static function qty(string|int|BigDecimal $value): string
    {
        return (string) self::of($value)->toScale(config('rasentra.quantity_scale', 6), RoundingMode::HalfUp);
    }

    public static function mul(string|int|BigDecimal $a, string|int|BigDecimal $b): BigDecimal
    {
        return self::of($a)->multipliedBy(self::of($b));
    }

    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::of($value));
        }

        return $total;
    }

    public static function rounding(): RoundingMode
    {
        return match (strtoupper((string) config('rasentra.money.rounding', 'HALF_UP'))) {
            'DOWN', 'FLOOR' => RoundingMode::Down,
            'UP', 'CEILING' => RoundingMode::Up,
            'HALF_EVEN' => RoundingMode::HalfEven,
            default => RoundingMode::HalfUp,
        };
    }
}
