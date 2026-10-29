<?php

namespace App\Domain\Recipe;

use App\Domain\Audit\Audit;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Inventory\StockLedger;
use App\Models\ConsumptionEvent;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\PublicationVersion;
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
        // Maker-checker: penyunting terakhir draft tidak boleh menyetujui draftnya sendiri.
        if ($version->edited_by !== null && (int) $version->edited_by === (int) $approver->id) {
            throw DomainException::needsApproval('approval_self', 'Anda yang terakhir menyunting draft resep ini. Minta rekan lain (chef kepala atau admin pusat) untuk menyetujui.');
        }

        return DB::transaction(function () use ($version, $approver) {
            $version->update(['status' => 'approved', 'approved_by' => $approver->id, 'effective_from' => $version->effective_from ?? now()]);
            $this->exploder->assertAcyclic($version->fresh('lines'));
            RecipeVersion::where('recipe_id', $version->recipe_id)->where('id', '!=', $version->id)
                ->where('status', 'approved')->update(['status' => 'retired']);
            Audit::record('recipe.approved', $version, $approver->id);
            // Rasentra Central menyebarkan resep yang disetujui ke outlet lewat cursor publikasi.
            PublicationVersion::create([
                'entity_type' => 'recipe_version',
                'entity_key' => (string) $version->id,
                'payload' => $version->fresh('lines')->toArray(),
                'outlet_ids' => $version->outlet_ids,
            ]);

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
     * Produksi batch potong & porsi: bahan keluar, hasil (dan produk samping) masuk dengan berat aktual.
     * Neraca dalam satuan yang sama: input = output + susut; selisih di atas toleransi wajib alasan.
     */
    public function produce(int $outletId, User $user, array $inputs, array $outputs, ?string $lossReason = null, ?int $recipeVersionId = null, string $tolerance = '0.001', ?string $notes = null): ProductionBatch
    {
        $in = Decimal::sum(array_column($inputs, 'quantity'));
        $out = Decimal::sum(array_column($outputs, 'quantity'));
        $loss = $in->minus($out);
        if ($loss->isNegative()) {
            throw new DomainException('production_balance', 'Hasil produksi melebihi bahan masuk.', 422);
        }
        if ($loss->isGreaterThan(Decimal::of($tolerance)) && blank($lossReason)) {
            throw new DomainException('loss_reason_required', 'Susut produksi wajib diberi alasan.', 422);
        }

        return $this->book('cutting', $outletId, $user, $inputs, $outputs, Decimal::qty($loss), $lossReason, $recipeVersionId, null, $notes);
    }

    /**
     * Produksi batch dari resep berstok (saus, kaldu): bahan standar mengikuti resep untuk jumlah yang
     * dibuat, petugas boleh mengoreksi pemakaian aktual. Susut = hasil standar - hasil timbang aktual.
     *
     * @param  array<int, string>|null  $actualInputs  product_id => kuantitas aktual (null = sesuai resep)
     */
    public function produceFromRecipe(int $outletId, User $user, RecipeVersion $version, string $quantity, string $actualOutput, ?array $actualInputs = null, ?string $lossReason = null, ?string $notes = null): ProductionBatch
    {
        if ($version->kind !== 'stocked_prep' || $version->status !== 'approved') {
            throw new DomainException('recipe_not_producible', 'Hanya resep berstok yang sudah disetujui yang bisa diproduksi.', 422);
        }
        if (! Decimal::of($quantity)->isPositive() || ! Decimal::of($actualOutput)->isPositive()) {
            throw new DomainException('quantity_required', 'Isi jumlah produksi dan hasil timbang.', 422);
        }

        $inputs = [];
        foreach ($this->exploder->explode($version, $quantity) as $need) {
            $actual = $actualInputs[$need['product_id']] ?? $need['quantity'];
            if (Decimal::of($actual)->isNegative()) {
                throw new DomainException('quantity_invalid', 'Pemakaian bahan tidak boleh negatif.', 422);
            }
            if (Decimal::of($actual)->isPositive()) {
                $inputs[] = ['product_id' => $need['product_id'], 'quantity' => Decimal::qty($actual), 'standard' => $need['quantity'], 'uom' => $need['uom']];
            }
        }

        $product = $version->recipe->product;
        $loss = Decimal::of($quantity)->minus(Decimal::of($actualOutput));
        $limit = Decimal::of($quantity)->multipliedBy(Decimal::of((string) config('rasentra.kitchen.production_loss_tolerance_percent', 2)))->dividedBy(100, 6, Decimal::rounding());
        if ($loss->isGreaterThan($limit) && blank($lossReason)) {
            throw new DomainException('loss_reason_required', 'Hasil di bawah standar resep. Pilih alasan susut.', 422);
        }

        return $this->book('recipe', $outletId, $user, $inputs,
            [['product_id' => $product->id, 'quantity' => Decimal::qty($actualOutput), 'uom' => $version->yield_uom ?: $product->uom_code, 'kind' => 'main']],
            Decimal::qty($loss->isNegative() ? Decimal::of('0') : $loss), $lossReason, $version->id, Decimal::qty($quantity), $notes);
    }

    /**
     * Bukukan batch: stok bahan keluar dan hasil masuk, lalu dokumen penyesuaian "production" yang dikirim
     * ke Rasentra Central (Goods Issue untuk bahan, Goods Receipt untuk hasil di SAP).
     */
    private function book(string $kind, int $outletId, User $user, array $inputs, array $outputs, string $loss, ?string $lossReason, ?int $recipeVersionId, ?string $expected, ?string $notes): ProductionBatch
    {
        return DB::transaction(function () use ($kind, $outletId, $user, $inputs, $outputs, $loss, $lossReason, $recipeVersionId, $expected, $notes) {
            $uom = fn (array $line) => $line['uom'] ?? Product::whereKey($line['product_id'])->value('uom_code') ?? 'KG';
            $inputs = array_map(fn ($l) => $l + ['uom' => $uom($l)], array_values($inputs));
            $outputs = array_map(fn ($l) => $l + ['uom' => $uom($l), 'kind' => 'main'], array_values($outputs));

            $batch = ProductionBatch::create([
                'outlet_id' => $outletId,
                'recipe_version_id' => $recipeVersionId,
                'kind' => $kind,
                'inputs' => $inputs,
                'outputs' => $outputs,
                'expected_quantity' => $expected,
                'loss_quantity' => $loss,
                'loss_reason' => $lossReason,
                'notes' => $notes,
                'user_id' => $user->id,
                'produced_at' => now(),
            ]);

            foreach ($inputs as $i => $input) {
                StockLedger::move($outletId, (int) $input['product_id'], Decimal::qty(Decimal::of($input['quantity'])->negated()),
                    $input['uom'], 'production_issue', 'production', $batch->id, $i + 1);
            }
            foreach ($outputs as $i => $output) {
                StockLedger::move($outletId, (int) $output['product_id'], Decimal::qty($output['quantity']),
                    $output['uom'], 'production_receipt', 'production', $batch->id, $i + 1);
            }

            $reason = trim(($kind === 'recipe' ? 'Produksi resep' : 'Potong & porsi').($lossReason ? ' · susut: '.$lossReason : ''));
            $doc = app(StockAdjustmentService::class)->record('production', $outletId, $user, null, $reason, array_merge(
                array_map(fn ($l) => ['product_id' => (int) $l['product_id'], 'quantity' => $l['quantity'], 'uom' => $l['uom'], 'from_state' => 'available', 'to_state' => null], $inputs),
                array_map(fn ($l) => ['product_id' => (int) $l['product_id'], 'quantity' => $l['quantity'], 'uom' => $l['uom'], 'from_state' => null, 'to_state' => 'available'], $outputs),
            ), 'production', $batch->id);
            $batch->update(['doc_no' => $doc?->doc_no]);
            Audit::record('production.recorded', $batch, $user->id, reason: $lossReason, outletId: $outletId);

            return $batch;
        });
    }
}
