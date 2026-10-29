<?php

namespace App\Support;

/**
 * Barcode Code 128 sebagai SVG, tanpa pustaka luar. Kode numerik genap memakai set C
 * (dua digit per simbol, lebih pendek di kertas 80 mm); lainnya memakai set B.
 * Dipakai untuk kode bump di tiket dapur yang dipindai di layar station.
 */
final class Barcode
{
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];

    private const STOP = '2331112';

    /** @return list<int> nilai simbol termasuk start dan checksum */
    public static function code128Values(string $text): array
    {
        if ($text !== '' && strlen($text) % 2 === 0 && ctype_digit($text)) {
            $values = [105];
            foreach (str_split($text, 2) as $pair) {
                $values[] = (int) $pair;
            }
        } else {
            $values = [104];
            foreach (str_split($text) as $ch) {
                $ord = ord($ch);
                if ($ord < 32 || $ord > 126) {
                    throw new \InvalidArgumentException('Karakter di luar Code 128 set B.');
                }
                $values[] = $ord - 32;
            }
        }
        $sum = $values[0];
        foreach (array_slice($values, 1) as $i => $v) {
            $sum += ($i + 1) * $v;
        }
        $values[] = $sum % 103;

        return $values;
    }

    /** Lebar modul bergantian batang/spasi, diawali batang, termasuk stop. */
    public static function code128Modules(string $text): string
    {
        return implode('', array_map(fn (int $v) => self::PATTERNS[$v], self::code128Values($text))).self::STOP;
    }

    public static function svg(string $text, int $height = 48, int $module = 2): string
    {
        $x = 10 * $module; // zona tenang
        $rects = '';
        foreach (str_split(self::code128Modules($text)) as $i => $w) {
            $width = (int) $w * $module;
            if ($i % 2 === 0) {
                $rects .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $x, $width, $height);
            }
            $x += $width;
        }
        $total = $x + 10 * $module;

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="%s"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g></svg>',
            $total, $height, $total, $height, htmlspecialchars($text), $rects);
    }
}
