<?php

namespace App\Domain\Recipe;

use App\Domain\Audit\Audit;
use App\Domain\Inventory\StockLedger;
use App\Models\ConsumptionEvent;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

final class RecipeService
{
    public function __construct(private readonly RecipeExploder $exploder) {}

    public function approve(RecipeVersion $version, User $approver): RecipeVersion
    {
        return DB::transaction(function () use ($version, $approver) {
            $version->update(['status' => 'approved', 'approved_by' => $approver->id, 'effective_from' => $version->effective_from ?? now()]);
            $this->exploder->assertAcyclic($version->fresh('lines'));
            RecipeVersion::where('recipe_id', $version->recipe_id)->where('id', '!=', $version->id)
                ->where('status', 'approved')->update(['status' => 'retired']);
            Audit::record('recipe.approved', $version, $approver->id);

            return $version->fresh();
        });
    }

    public function activeVersionFor(int $productId): ?RecipeVersion
    {
        return Recipe::where('product_id', $productId)->first()
            ?->versions()->where('status', 'approved')->orderByDesc('version')->first();
    }

    /**
     * Konsumsi bahan untuk satu sumber (item dapur, cooking job). Versi resep disimpan pada
     * event sehingga perubahan resep tidak menghitung ulang histori.
     */
    public function consume(int $outletId, RecipeVersion $version, string $portions, array $modifiers, string $sourceType, string $sourceId, string $reason = 'sale'): array
    {
        $events = [];
        foreach ($this->exploder->explode($version, $portions, $modifiers) as $i => $need) {
            $events[] = ConsumptionEvent::create([
                'outlet_id' => $outletId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'recipe_version_id' => $version->id,
                'product_id' => $need['product_id'],
                'quantity' => $need['quantity'],
                'uom_code' => $need['uom'],
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            StockLedger::move($outletId, $need['product_id'], Decimal::qty(Decimal::of($need['quantity'])->negated()), $need['uom'],
                'consumption', $sourceType, $sourceId, $i + 1);
        }

        return $events;
    }

    /**
     * Produksi batch: bahan keluar, hasil (dan produk samping) masuk dengan berat aktual.
     * Neraca: input = output + susut; selisih di atas toleransi wajib alasan.
     */
    public function produce(int $outletId, User $user, array $inputs, array $outputs, ?string $lossReason = null, ?int $recipeVersionId = null, string $tolerance = '0.001'): ProductionBatch
    {
        return DB::transaction(function () use ($outletId, $user, $inputs, $outputs, $lossReason, $recipeVersionId, $tolerance) {
            $in = Decimal::sum(array_column($inputs, 'quantity'));
            $out = Decimal::sum(array_column($outputs, 'quantity'));
            $loss = $in->minus($out);
            if ($loss->isNegative()) {
                throw new DomainException('production_balance', 'Hasil produksi melebihi bahan masuk.');
            }
            if ($loss->isGreaterThan(Decimal::of($tolerance)) && empty($lossReason)) {
                throw new DomainException('loss_reason_required', 'Susut produksi wajib diberi alasan.');
            }

            $batch = ProductionBatch::create([
                'outlet_id' => $outletId,
                'recipe_version_id' => $recipeVersionId,
                'inputs' => $inputs,
                'outputs' => $outputs,
                'loss_quantity' => Decimal::qty($loss),
                'loss_reason' => $lossReason,
                'user_id' => $user->id,
                'produced_at' => now(),
            ]);

            foreach ($inputs as $i => $input) {
                StockLedger::move($outletId, (int) $input['product_id'], Decimal::qty(Decimal::of($input['quantity'])->negated()),
                    $input['uom'] ?? 'KG', 'production_issue', 'production', $batch->id, $i + 1);
            }
            foreach ($outputs as $i => $output) {
                StockLedger::move($outletId, (int) $output['product_id'], Decimal::qty($output['quantity']),
                    $output['uom'] ?? 'KG', 'production_receipt', 'production', $batch->id, $i + 1);
            }

            return $batch;
        });
    }
}
