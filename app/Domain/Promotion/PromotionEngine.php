<?php

namespace App\Domain\Promotion;

use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Promotion Engine yang berjalan di server outlet. Murni (tanpa I/O): masukan keranjang
 * dan daftar promo aktif, keluaran alokasi diskon per baris yang deterministik.
 *
 * Urutan: promo item lebih dahulu, lalu promo keranjang. Promo diurutkan berdasarkan
 * priority lalu id (pemecah seri). Mode tumpuk:
 *  - exclusive    : baris yang sudah mendapat promo exclusive tidak menerima promo lain
 *  - stackable    : dihitung atas sisa nilai bersih baris
 *  - best_benefit : di antara kandidat best_benefit, hanya manfaat terbesar yang dipakai
 *
 * Baris:  ['key','product_id','category','quantity','unit_price','gross']
 * Promo:  ['id','type','scope','rules','priority','stacking','funded_by']
 */
final class PromotionEngine
{
    /**
     * @return array{lines: array<string, array{discount: string, net: string, allocations: list<array{promotion_version_id:int, amount:string, funded_by:string}>}>, total_discount: string, applied: list<int>}
     */
    public function evaluate(array $lines, array $promotions): array
    {
        $state = [];
        foreach ($lines as $line) {
            $state[$line['key']] = [
                'line' => $line,
                'net' => Decimal::of($line['gross']),
                'exclusive_taken' => false,
                'allocations' => [],
            ];
        }

        usort($promotions, fn ($a, $b) => [$a['priority'] ?? 100, $a['id']] <=> [$b['priority'] ?? 100, $b['id']]);

        $itemPromos = array_values(array_filter($promotions, fn ($p) => ($p['scope'] ?? 'item') === 'item'));
        $basketPromos = array_values(array_filter($promotions, fn ($p) => ($p['scope'] ?? 'item') === 'basket'));

        // best_benefit: pilih satu pemenang per baris sebelum diterapkan.
        $best = array_values(array_filter($itemPromos, fn ($p) => ($p['stacking'] ?? 'exclusive') === 'best_benefit'));
        $others = array_values(array_filter($itemPromos, fn ($p) => ($p['stacking'] ?? 'exclusive') !== 'best_benefit'));

        foreach ($others as $promo) {
            $this->applyItemPromo($promo, $state);
        }

        if ($best !== []) {
            foreach (array_keys($state) as $key) {
                if ($state[$key]['exclusive_taken']) {
                    continue;
                }
                $winner = null;
                $winnerAmount = BigDecimal::zero();
                foreach ($best as $promo) {
                    $amount = $this->itemBenefits($promo, $state)[$key] ?? BigDecimal::zero();
                    if ($amount->isGreaterThan($winnerAmount)) {
                        [$winner, $winnerAmount] = [$promo, $amount];
                    }
                }
                if ($winner !== null) {
                    $this->allocate($state, $key, $winner, $winnerAmount);
                    $state[$key]['exclusive_taken'] = true;
                }
            }
        }

        foreach ($basketPromos as $promo) {
            $this->applyBasketPromo($promo, $state);
        }

        $result = ['lines' => [], 'total_discount' => '0', 'applied' => []];
        $total = BigDecimal::zero();
        $applied = [];
        foreach ($state as $key => $s) {
            $discount = Decimal::of($s['line']['gross'])->minus($s['net']);
            $total = $total->plus($discount);
            foreach ($s['allocations'] as $a) {
                $applied[$a['promotion_version_id']] = true;
            }
            $result['lines'][$key] = [
                'discount' => Decimal::money($discount),
                'net' => Decimal::money($s['net']),
                'allocations' => $s['allocations'],
            ];
        }
        $result['total_discount'] = Decimal::money($total);
        $result['applied'] = array_keys($applied);

        return $result;
    }

    private function applyItemPromo(array $promo, array &$state): void
    {
        foreach ($this->itemBenefits($promo, $state) as $key => $amount) {
            if ($amount->isZero()) {
                continue;
            }
            $this->allocate($state, $key, $promo, $amount);
            if (($promo['stacking'] ?? 'exclusive') === 'exclusive') {
                $state[$key]['exclusive_taken'] = true;
            }
        }
    }

    /** @return array<string, BigDecimal> manfaat per baris, sudah dibatasi sisa nilai bersih */
    private function itemBenefits(array $promo, array $state): array
    {
        $rules = $promo['rules'];
        $eligible = array_filter($state, fn ($s) => ! $s['exclusive_taken'] && $this->matches($rules, $s['line']));
        if ($eligible === []) {
            return [];
        }

        $benefits = [];
        switch ($promo['type']) {
            case 'percent':
                foreach ($eligible as $key => $s) {
                    $benefits[$key] = $s['net']->multipliedBy(Decimal::of($rules['percent']))->dividedBy(100, 2, Decimal::rounding());
                }
                break;

            case 'special_price':
                foreach ($eligible as $key => $s) {
                    $special = Decimal::mul($s['line']['quantity'], $rules['price'])->toScale(2, Decimal::rounding());
                    $benefits[$key] = BigDecimal::max(BigDecimal::zero(), Decimal::of($s['line']['gross'])->minus($special));
                }
                break;

            case 'weight_tier':
                // Syarat berat dihitung gabungan seluruh baris yang memenuhi, dengan presisi berat sebenarnya.
                $weight = Decimal::sum(array_map(fn ($s) => $s['line']['quantity'], $eligible));
                $percent = null;
                foreach ($rules['tiers'] as $tier) {
                    if ($weight->isGreaterThanOrEqualTo(Decimal::of($tier['min_weight']))) {
                        $percent = $tier['percent'];
                    }
                }
                if ($percent !== null) {
                    foreach ($eligible as $key => $s) {
                        $benefits[$key] = $s['net']->multipliedBy(Decimal::of($percent))->dividedBy(100, 2, Decimal::rounding());
                    }
                }
                break;

            case 'buy_x_get_y':
                $buyQty = Decimal::sum(array_map(
                    fn ($s) => $s['line']['quantity'],
                    array_filter($state, fn ($s) => (int) $s['line']['product_id'] === (int) $rules['buy_product_id'])
                ));
                $multiple = $buyQty->dividedBy(Decimal::of($rules['buy_qty']), 0, RoundingMode::Down);
                if (isset($rules['max_multiple'])) {
                    $multiple = BigDecimal::min($multiple, Decimal::of($rules['max_multiple']));
                }
                $freeLeft = $multiple->multipliedBy(Decimal::of($rules['get_qty']));
                foreach ($eligible as $key => $s) {
                    if ((int) $s['line']['product_id'] !== (int) $rules['get_product_id'] || $freeLeft->isZero()) {
                        continue;
                    }
                    $qty = BigDecimal::min($freeLeft, Decimal::of($s['line']['quantity']));
                    $freeLeft = $freeLeft->minus($qty);
                    // Barang gratis tetap berupa baris dan tetap memotong stok; hanya nilainya nol.
                    $benefits[$key] = $qty->multipliedBy(Decimal::of($s['line']['unit_price']))->toScale(2, Decimal::rounding());
                }
                break;
        }

        foreach ($benefits as $key => $amount) {
            if (isset($rules['max_discount'])) {
                $amount = BigDecimal::min($amount, Decimal::of($rules['max_discount']));
            }
            $benefits[$key] = BigDecimal::min($amount, $state[$key]['net']);
        }

        return $benefits;
    }

    private function applyBasketPromo(array $promo, array &$state): void
    {
        $rules = $promo['rules'];
        $eligible = array_filter($state, fn ($s) => ! $s['exclusive_taken'] && $this->matches($rules, $s['line']));
        $base = Decimal::sum(array_map(fn ($s) => $s['net'], $eligible));
        if ($eligible === [] || $base->isZero()) {
            return;
        }
        if (isset($rules['min_spend']) && $base->isLessThan(Decimal::of($rules['min_spend']))) {
            return;
        }

        $benefit = isset($rules['percent'])
            ? $base->multipliedBy(Decimal::of($rules['percent']))->dividedBy(100, 2, Decimal::rounding())
            : Decimal::of($rules['amount']);
        if (isset($rules['max_discount'])) {
            $benefit = BigDecimal::min($benefit, Decimal::of($rules['max_discount']));
        }
        $benefit = BigDecimal::min($benefit, $base);

        // Alokasi proporsional nilai bersih; sisa pembulatan ke baris terbesar agar retur dan pajak tertelusur.
        $keys = array_keys($eligible);
        usort($keys, fn ($a, $b) => $state[$b]['net']->compareTo($state[$a]['net']) ?: strcmp((string) $a, (string) $b));
        $allocated = BigDecimal::zero();
        $shares = [];
        foreach ($keys as $key) {
            $share = $benefit->multipliedBy($state[$key]['net'])->dividedBy($base, 2, RoundingMode::Down);
            $shares[$key] = $share;
            $allocated = $allocated->plus($share);
        }
        $shares[$keys[0]] = $shares[$keys[0]]->plus($benefit->minus($allocated));

        foreach ($shares as $key => $share) {
            if (! $share->isZero()) {
                $this->allocate($state, $key, $promo, $share);
            }
            if (($promo['stacking'] ?? 'exclusive') === 'exclusive') {
                $state[$key]['exclusive_taken'] = true;
            }
        }
    }

    private function allocate(array &$state, string $key, array $promo, BigDecimal $amount): void
    {
        $state[$key]['net'] = $state[$key]['net']->minus($amount);
        $state[$key]['allocations'][] = [
            'promotion_version_id' => (int) $promo['id'],
            'amount' => Decimal::money($amount),
            'funded_by' => $promo['funded_by'] ?? 'internal',
        ];
    }

    private function matches(array $rules, array $line): bool
    {
        if (! empty($rules['product_ids']) && ! in_array((int) $line['product_id'], array_map('intval', $rules['product_ids']), true)) {
            return false;
        }
        if (! empty($rules['categories']) && ! in_array($line['category'] ?? null, $rules['categories'], true)) {
            return false;
        }
        if (! empty($rules['line_types']) && ! in_array($line['line_type'] ?? null, $rules['line_types'], true)) {
            return false;
        }
        if (! empty($rules['exclude_line_types']) && in_array($line['line_type'] ?? null, $rules['exclude_line_types'], true)) {
            return false;
        }

        return true;
    }
}
