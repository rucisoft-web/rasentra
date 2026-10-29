<?php

namespace App\Domain\Recipe;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;

/**
 * Menguraikan resep menjadi kebutuhan bahan. Subresep "stocked_prep" (saus, kaldu siap
 * pakai) dipotong sebagai item hasil; subresep "virtual_prep" diuraikan ke bahan asal.
 * Satu subresep tidak pernah dipotong dua kali, dan siklus resep ditolak.
 */
final class RecipeExploder
{
    /**
     * @param  list<string>  $modifiers  kunci modifier terpilih; "without:<product_id>" menghilangkan bahan
     * @return array<int, array{product_id:int, quantity:string, uom:string}>
     */
    public function explode(RecipeVersion $version, string $portions, array $modifiers = []): array
    {
        $needs = [];
        $this->walk($version, Decimal::of($portions), $modifiers, $needs, [$version->recipe_id => true]);

        return array_map(fn ($n) => [
            'product_id' => $n['product_id'],
            'quantity' => Decimal::qty($n['quantity']),
            'uom' => $n['uom'],
        ], array_values($needs));
    }

    private function walk(RecipeVersion $version, BigDecimal $portions, array $modifiers, array &$needs, array $path): void
    {
        $factor = $portions->dividedBy(Decimal::of($version->yield_quantity), 6, Decimal::rounding());

        foreach ($version->lines as $line) {
            if ($line->modifier_key !== null && ! in_array($line->modifier_key, $modifiers, true)) {
                continue;
            }
            if (in_array('without:'.$line->component_product_id, $modifiers, true)) {
                continue;
            }

            $qty = Decimal::of($line->quantity)->multipliedBy($factor);
            $sub = $this->approvedVersionFor($line->component_product_id);

            if ($sub !== null && $sub->kind === 'virtual_prep') {
                if (isset($path[$sub->recipe_id])) {
                    throw new DomainException('recipe_cycle', 'Resep memiliki siklus dan tidak dapat diuraikan.');
                }
                $this->walk($sub, $qty, $modifiers, $needs, $path + [$sub->recipe_id => true]);

                continue;
            }

            $key = $line->component_product_id;
            $needs[$key] ??= ['product_id' => $key, 'quantity' => BigDecimal::zero(), 'uom' => $line->uom_code];
            $needs[$key]['quantity'] = $needs[$key]['quantity']->plus($qty);
        }
    }

    private function approvedVersionFor(int $productId): ?RecipeVersion
    {
        $recipe = Recipe::where('product_id', $productId)->first();

        return $recipe?->versions()->where('status', 'approved')->orderByDesc('version')->first();
    }

    /** Validasi saat menyetujui versi: tolak siklus pada graf subresep. */
    public function assertAcyclic(RecipeVersion $version): void
    {
        $this->explode($version, (string) $version->yield_quantity);
    }
}
