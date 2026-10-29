<?php

namespace App\Domain\CookingService;

use App\Domain\Audit\Audit;
use App\Domain\Inventory\StockLedger;
use App\Domain\Sales\Approval;
use App\Domain\Sales\OrderService;
use App\Models\CookingFeeRule;
use App\Models\CookingJob;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\PackEvent;
use App\Models\Product;
use App\Models\ServiceEntitlement;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Pilih daging retail lalu dimasak di restoran. Daging dijual satu kali sebagai baris
 * retail berdasarkan Pack ID; jasa memasak adalah baris terpisah dengan tarif berbasis
 * berat mentah, per pack, per porsi atau bertingkat.
 */
final class CookingService
{
    public function __construct(private readonly OrderService $orders) {}

    public function fee(CookingFeeRule $rule, string $rawWeight, int $portions): string
    {
        $fee = match ($rule->basis) {
            'per_kg' => Decimal::mul($rawWeight, $rule->rate),
            'per_pack' => Decimal::of($rule->rate),
            'per_portion' => Decimal::mul((string) $portions, $rule->rate),
            'tiered' => Decimal::mul($rawWeight, $this->tierRate($rule, $rawWeight)),
            default => throw new DomainException('fee_basis_invalid', 'Dasar tarif cooking fee tidak dikenal.'),
        };

        $minimum = Decimal::of($rule->minimum_fee);

        return Decimal::money($fee->isLessThan($minimum) ? $minimum : $fee);
    }

    public function ruleFor(int $outletId, ?string $method = null): CookingFeeRule
    {
        $rule = CookingFeeRule::query()
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId))
            ->where(fn ($q) => $q->whereNull('method')->orWhere('method', $method))
            ->orderByRaw('CASE WHEN outlet_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN method IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('version')
            ->first();

        if ($rule === null) {
            throw new DomainException('fee_rule_missing', 'Tarif cooking fee belum diatur untuk outlet ini.');
        }

        return $rule;
    }

    /**
     * Daging belum dibayar: pack sudah ada di keranjang (reserved), tambahkan jasa memasak.
     */
    public function requestForPackLine(OrderLine $packLine, User $actor, array $instructions): CookingJob
    {
        return DB::transaction(function () use ($packLine, $actor, $instructions) {
            $packLine = OrderLine::lockForUpdate()->with('order')->findOrFail($packLine->id);
            if ($packLine->pack_id === null || $packLine->status !== 'active') {
                throw new DomainException('not_pack_line', 'Cooking fee hanya untuk baris kemasan daging.');
            }
            if (CookingJob::where('pack_id', $packLine->pack_id)->whereNotIn('status', ['cancelled_before_cook'])->exists()) {
                throw DomainException::conflict('pack_already_has_job', 'Kemasan ini sudah dipesan untuk dimasak.');
            }

            $job = $this->createJob($packLine->order, $packLine->pack_id, (string) $packLine->quantity, $instructions, null);
            $packLine->update(['cooking_job_id' => $job->id]);

            return $job;
        });
    }

    /**
     * Daging sudah dibayar di kasir retail: restoran memakai struk + Pack ID asal dan hanya
     * menagih jasa/tambahan. Hak layanan dipakai satu kali; pemakaian ulang ditolak.
     */
    public function requestFromReceipt(Order $restoOrder, string $receiptDocNo, string $packCode, User $actor, array $instructions): CookingJob
    {
        return DB::transaction(function () use ($restoOrder, $receiptDocNo, $packCode, $actor, $instructions) {
            $receipt = Order::where('outlet_id', $restoOrder->outlet_id)->where('doc_no', $receiptDocNo)->first();
            if ($receipt === null || $receipt->status !== 'paid') {
                throw new DomainException('receipt_not_found', 'Struk tidak ditemukan atau belum lunas di outlet ini.', 404);
            }
            $pack = Pack::where('pack_code', $packCode)->first();
            $line = $pack ? $receipt->lines()->where('pack_id', $pack->id)->where('status', 'active')->first() : null;
            if ($line === null) {
                throw DomainException::conflict('pack_not_on_receipt', 'Kemasan tidak tercantum pada struk tersebut.');
            }

            $entitlement = ServiceEntitlement::where('receipt_order_id', $receipt->id)->where('pack_id', $pack->id)->lockForUpdate()->first()
                ?? ServiceEntitlement::create([
                    'receipt_order_id' => $receipt->id,
                    'pack_id' => $pack->id,
                    'remaining_weight' => $line->quantity,
                    'status' => 'open',
                ]);

            if ($entitlement->status !== 'open') {
                Audit::record('cooking.entitlement_reuse_blocked', $entitlement, $actor->id, outletId: $restoOrder->outlet_id);
                throw DomainException::conflict('entitlement_used', 'Hak layanan untuk struk dan Pack ID ini sudah dipakai.');
            }

            $job = $this->createJob($restoOrder, $pack->id, (string) $line->quantity, $instructions, $entitlement->id);
            $entitlement->update(['status' => 'used', 'remaining_weight' => '0']);

            return $job;
        });
    }

    public function handOver(CookingJob $job, User $kitchenUser): CookingJob
    {
        if ($job->status === 'requested') {
            $job->update(['status' => 'pack_handed_over', 'handed_over_by' => $kitchenUser->id]);
        }

        return $job;
    }

    /**
     * Batal. Sebelum dimasak: jasa dibatalkan, pack tetap milik keranjang. Sesudah dimasak:
     * butuh supervisor, daging tidak kembali tersedia (dicatat waste bila belum terjual).
     */
    public function cancel(CookingJob $job, User $actor, string $reason, ?array $approval = null): CookingJob
    {
        return DB::transaction(function () use ($job, $actor, $reason, $approval) {
            $job = CookingJob::lockForUpdate()->findOrFail($job->id);
            $order = Order::findOrFail($job->order_id);
            $serviceLine = OrderLine::where('cooking_job_id', $job->id)->where('line_type', 'cooking_service')->first();

            if (in_array($job->status, ['requested', 'pack_handed_over'], true)) {
                $serviceLine?->update(['status' => 'voided']);
                OrderLine::where('order_id', $order->id)->where('pack_id', $job->pack_id)->update(['cooking_job_id' => null]);
                $job->update(['status' => 'cancelled_before_cook']);
                if ($job->entitlement_id) {
                    ServiceEntitlement::whereKey($job->entitlement_id)->update(['status' => 'open', 'remaining_weight' => $job->raw_weight]);
                }
                if ($order->isEditable()) {
                    $this->orders->recalculate($order);
                }

                return $job;
            }

            $approver = Approval::verify($approval, $actor->id, $order->outlet_id);
            $pack = Pack::lockForUpdate()->findOrFail($job->pack_id);

            if ($pack->status !== 'sold') {
                // Daging belum terjual tetapi sudah dimasak: keluar sebagai waste, tidak kembali tersedia.
                StockLedger::move($order->outlet_id, $pack->product_id, Decimal::qty(Decimal::of($pack->net_weight)->negated()), $pack->uom_code,
                    'waste', 'cooking_job', $job->id, packId: $pack->id);
                PackEvent::create(['pack_id' => $pack->id, 'event' => 'consumed_cancelled', 'from_status' => $pack->status, 'to_status' => 'damaged',
                    'actor_id' => $actor->id, 'reason' => $reason, 'source_type' => 'cooking_job', 'source_id' => $job->id, 'occurred_at' => now()]);
                $pack->update(['status' => 'damaged', 'reserved_order_id' => null]);
                OrderLine::where('order_id', $order->id)->where('pack_id', $pack->id)->where('status', 'active')->update(['status' => 'voided']);
            }
            $serviceLine?->update(['status' => 'voided']);
            $job->update(['status' => 'cancelled_after_cook']);
            Audit::record('cooking.cancel_after_cook', $job, $actor->id, reason: $reason, approverId: $approver->id, outletId: $order->outlet_id);
            if ($order->isEditable()) {
                $this->orders->recalculate($order);
            }

            return $job;
        });
    }

    private function createJob(Order $order, string $packId, string $rawWeight, array $instructions, ?string $entitlementId): CookingJob
    {
        $rule = $this->ruleFor($order->outlet_id, $instructions['method'] ?? null);
        $portions = max(1, (int) ($instructions['portions'] ?? 1));
        $fee = $this->fee($rule, $rawWeight, $portions);

        $job = CookingJob::create([
            'order_id' => $order->id,
            'pack_id' => $packId,
            'entitlement_id' => $entitlementId,
            'raw_weight' => Decimal::qty($rawWeight),
            'portions' => $portions,
            'method' => $instructions['method'] ?? null,
            'doneness' => $instructions['doneness'] ?? null,
            'sauce' => $instructions['sauce'] ?? null,
            'allergy_note' => $instructions['allergy_note'] ?? null,
            'fee_rule_id' => $rule->id,
            'fee_rule_version' => $rule->version,
            'fee_amount' => $fee,
            'status' => 'requested',
        ]);

        // Baris jasa terpisah dari daging: kuantitas = dasar tarif, nilai = fee yang dihitung.
        [$qty, $unit] = match ($rule->basis) {
            'per_kg', 'tiered' => [Decimal::qty($rawWeight), 'KG'],
            'per_portion' => [(string) $portions, 'PORSI'],
            default => ['1', 'PCS'],
        };
        $service = Product::findOrFail($rule->service_product_id);
        $line = $this->orders->addSku($order, $service, '1', [
            'line_type' => 'cooking_service',
            'unit_price' => $fee,
            'cooking_job_id' => $job->id,
            'modifiers' => [['key' => 'cooking_fee', 'basis' => $rule->basis, 'qty' => $qty, 'uom' => $unit, 'rate' => (string) $rule->rate]],
        ]);
        $line->update(['note' => "Cooking fee {$qty} {$unit}"]);

        return $job;
    }

    private function tierRate(CookingFeeRule $rule, string $weight): string
    {
        $rate = (string) $rule->rate;
        foreach ($rule->tiers ?? [] as $tier) {
            if (Decimal::of($weight)->isLessThanOrEqualTo(Decimal::of($tier['up_to']))) {
                return (string) $tier['rate'];
            }
        }

        return $rate;
    }
}
