<?php

namespace Tests\Unit;

use App\Domain\Promotion\PromotionEngine;
use Tests\TestCase;

class PromotionEngineTest extends TestCase
{
    private function line(string $key, int $product, string $qty, string $price, string $category = 'A', string $type = 'retail_product'): array
    {
        return ['key' => $key, 'product_id' => $product, 'category' => $category, 'line_type' => $type, 'quantity' => $qty, 'unit_price' => $price,
            'gross' => bcmulFallback($qty, $price)];
    }

    public function test_basket_discount_is_allocated_and_sums_exactly(): void
    {
        $lines = [$this->line('a', 1, '1', '33333'), $this->line('b', 2, '1', '33333'), $this->line('c', 3, '1', '33334')];
        $r = (new PromotionEngine)->evaluate($lines, [
            ['id' => 1, 'type' => 'amount', 'scope' => 'basket', 'rules' => ['min_spend' => 50000, 'amount' => 10000], 'priority' => 1, 'stacking' => 'exclusive'],
        ]);
        $this->assertSame('10000.00', $r['total_discount']);
        $sum = array_sum(array_map(fn ($l) => (int) round((float) $l['discount'] * 100), $r['lines']));
        $this->assertSame(1000000, $sum);
    }

    public function test_exclusive_and_best_benefit_are_deterministic(): void
    {
        $lines = [$this->line('a', 1, '2', '50000')];
        $promos = [
            ['id' => 7, 'type' => 'percent', 'scope' => 'item', 'rules' => ['percent' => 10], 'priority' => 5, 'stacking' => 'best_benefit'],
            ['id' => 8, 'type' => 'percent', 'scope' => 'item', 'rules' => ['percent' => 15], 'priority' => 5, 'stacking' => 'best_benefit'],
        ];
        $r = (new PromotionEngine)->evaluate($lines, $promos);
        $this->assertSame('15000.00', $r['lines']['a']['discount']);
        $this->assertSame([8], $r['applied']);
    }

    public function test_buy_x_get_y_keeps_free_item_as_line(): void
    {
        $lines = [$this->line('buy', 10, '2', '45000'), $this->line('free', 11, '1', '28000')];
        $r = (new PromotionEngine)->evaluate($lines, [
            ['id' => 3, 'type' => 'buy_x_get_y', 'scope' => 'item', 'rules' => ['buy_product_id' => 10, 'buy_qty' => 2, 'get_product_id' => 11, 'get_qty' => 1], 'priority' => 1, 'stacking' => 'exclusive'],
        ]);
        $this->assertSame('0.00', $r['lines']['free']['net']);
        $this->assertSame('90000.00', $r['lines']['buy']['net']);
    }

    public function test_meat_discount_does_not_discount_cooking_fee(): void
    {
        $lines = [$this->line('meat', 1, '1.5', '200000', 'Daging Sapi'), $this->line('fee', 99, '1', '60000', '', 'cooking_service')];
        $r = (new PromotionEngine)->evaluate($lines, [
            ['id' => 4, 'type' => 'percent', 'scope' => 'basket', 'rules' => ['percent' => 10, 'exclude_line_types' => ['cooking_service']], 'priority' => 1, 'stacking' => 'exclusive'],
        ]);
        $this->assertSame('30000.00', $r['lines']['meat']['discount']);
        $this->assertSame('0.00', $r['lines']['fee']['discount']);
    }
}

function bcmulFallback(string $a, string $b): string
{
    return (string) \Brick\Math\BigDecimal::of($a)->multipliedBy($b)->toScale(2);
}
