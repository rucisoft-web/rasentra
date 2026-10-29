<?php

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Domain\Catalog\PriceResolver;
use App\Domain\Inventory\StockLedger;
use App\Domain\Kitchen\KitchenService;
use App\Domain\Promotion\ActivePromotions;
use App\Domain\Promotion\PromotionEngine;
use App\Domain\Sync\Outbox;
use App\Models\CookingJob;
use App\Models\DiscountAllocation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\PackEvent;
use App\Models\Product;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Siklus keranjang sampai finalisasi untuk Rasentra POS (retail dan resto).
 * Setiap mutasi berjalan dalam satu transaksi MySQL; finalisasi menulis struk,
 * pergerakan stok, status Pack ID dan outbox sekaligus.
 */
final class OrderService
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly ActivePromotions $promotions,
        private readonly PromotionEngine $engine,
    ) {}

    public function create(User $user, int $outletId, string $channel = 'retail', array $attributes = []): Order
    {
        return Order::create($attributes + [
            'outlet_id' => $outletId,
            'channel' => $channel,
            'user_id' => $user->id,
            'status' => 'draft',
            'business_date' => Outlet::businessDate($outletId),
        ]);
    }

    /** Barcode SKU biasa: scan berulang menambah kuantitas pada baris yang sama. */
    public function addSku(Order $order, Product $product, string $quantity = '1', array $options = []): OrderLine
    {
        if ($product->is_weighed && empty($options['allow_manual_weight'])) {
            throw new DomainException('weighed_needs_pack', 'Produk timbang harus dijual lewat Pack ID atau timbangan.');
        }

        return DB::transaction(function () use ($order, $product, $quantity, $options) {
            $order = $this->lockEditable($order);
            $lineType = $options['line_type'] ?? ($product->type === 'menu' ? 'restaurant_menu' : 'retail_product');

            $existing = $order->lines()
                ->where('product_id', $product->id)
                ->where('status', 'active')
                ->whereNull('pack_id')
                ->where('line_type', $lineType)
                ->when(! empty($options['modifiers']), fn ($q) => $q->whereRaw('1 = 0')) // modifier berbeda = baris baru
                ->first();

            if ($existing && empty($options['modifiers'])) {
                $qty = Decimal::of($existing->quantity)->plus(Decimal::of($quantity));
                $existing->update([
                    'quantity' => Decimal::qty($qty),
                    'gross_amount' => Decimal::money(Decimal::mul($qty, $existing->unit_price)),
                    'net_amount' => Decimal::money(Decimal::mul($qty, $existing->unit_price)),
                    'revision' => $existing->revision + 1,
                ]);
                $line = $existing;
            } else {
                $price = isset($options['unit_price'])
                    ? ['unit_price' => (string) $options['unit_price'], 'price_version_id' => null]
                    : $this->prices->resolve($product->id, $order->outlet_id, $order->channel);
                $gross = Decimal::money(Decimal::mul($quantity, $price['unit_price']));
                $line = $order->lines()->create([
                    'line_no' => $this->nextLineNo($order),
                    'line_type' => $lineType,
                    'product_id' => $product->id,
                    'quantity' => Decimal::qty($quantity),
                    'uom_code' => $product->uom_code,
                    'unit_price' => $price['unit_price'],
                    'price_version_id' => $price['price_version_id'],
                    'gross_amount' => $gross,
                    'net_amount' => $gross,
                    'modifiers' => $options['modifiers'] ?? null,
                    'course' => $options['course'] ?? null,
                    'note' => $options['note'] ?? null,
                    'cooking_job_id' => $options['cooking_job_id'] ?? null,
                ]);
            }

            $this->recalculate($order);

            return $line->fresh();
        });
    }

    /**
     * Pack ID: berat diambil dari kemasan, satu kemasan hanya bisa berada di satu keranjang
     * dan hanya terjual sekali. Pack outlet lain atau belum diterima diblokir; pack hold
     * atau kedaluwarsa butuh persetujuan supervisor.
     */
    public function addPack(Order $order, string $packCode, User $actor, ?array $approval = null): OrderLine
    {
        return DB::transaction(function () use ($order, $packCode, $actor, $approval) {
            $order = $this->lockEditable($order);
            $pack = Pack::where('pack_code', $packCode)->lockForUpdate()->first();

            if ($pack === null) {
                throw new DomainException('pack_unknown', "Pack ID {$packCode} tidak dikenal. Jangan menebak berat; hubungi kepala outlet.", 404);
            }
            if ($pack->reserved_order_id === $order->id && $pack->status === 'reserved') {
                throw DomainException::conflict('pack_already_in_cart', 'Kemasan ini sudah ada di keranjang.');
            }
            if ($pack->status === 'sold' || $pack->sold_order_line_id !== null) {
                throw DomainException::conflict('pack_already_sold', 'Kemasan ini sudah terjual.');
            }
            if ($pack->status === 'reserved') {
                throw DomainException::conflict('pack_reserved', 'Kemasan sedang dipakai transaksi lain.');
            }
            if ((int) $pack->outlet_id !== (int) $order->outlet_id || $pack->location_type !== 'outlet') {
                throw DomainException::conflict('pack_other_outlet', 'Kemasan bukan stok outlet ini atau belum diterima.');
            }

            $approver = null;
            $expired = $pack->expires_at !== null && $pack->expires_at->lt(now()->startOfDay());
            if ($pack->status === 'hold' || $expired) {
                $approver = Approval::verify($approval, $actor->id, $order->outlet_id);
            } elseif ($pack->status !== 'received' && $pack->status !== 'available') {
                throw DomainException::conflict('pack_not_sellable', "Status kemasan {$pack->status} tidak dapat dijual.");
            }

            $product = Product::findOrFail($pack->product_id);
            $price = $this->prices->resolve($product->id, $order->outlet_id, $order->channel);
            $gross = Decimal::money(Decimal::mul($pack->net_weight, $price['unit_price']));

            $line = $order->lines()->create([
                'line_no' => $this->nextLineNo($order),
                'line_type' => 'retail_product',
                'product_id' => $product->id,
                'pack_id' => $pack->id,
                'quantity' => Decimal::qty($pack->net_weight),
                'uom_code' => $pack->uom_code,
                'unit_price' => $price['unit_price'],
                'price_version_id' => $price['price_version_id'],
                'label_price' => $pack->label_price,
                'gross_amount' => $gross,
                'net_amount' => $gross,
            ]);

            $this->packEvent($pack, 'reserved', 'reserved', $actor->id, 'order', $order->id, $approver ? 'approved_by:'.$approver->id : null);
            $pack->update(['status' => 'reserved', 'reserved_order_id' => $order->id, 'lock_version' => $pack->lock_version + 1]);

            if ($approver) {
                Audit::record('pack.sell_override', $pack, $actor->id, reason: $expired ? 'expired' : 'hold', approverId: $approver->id, outletId: $order->outlet_id);
            }

            $this->recalculate($order);

            return $line->fresh();
        });
    }

    /** Ubah harga baris: hanya dengan persetujuan supervisor dan alasan, dicatat di audit. */
    public function overridePrice(OrderLine $line, string $unitPrice, string $reason, User $actor, ?array $approval): OrderLine
    {
        return DB::transaction(function () use ($line, $unitPrice, $reason, $actor, $approval) {
            $order = $this->lockEditable($line->order);
            $diff = Decimal::of($line->unit_price)->minus(Decimal::of($unitPrice))->abs()->multipliedBy(Decimal::of($line->quantity));
            $approver = Approval::verify($approval, $actor->id, $order->outlet_id, Decimal::money($diff));
            $before = $line->only(['unit_price', 'gross_amount']);
            $gross = Decimal::money(Decimal::mul($line->quantity, $unitPrice));
            $line->update([
                'unit_price' => Decimal::money($unitPrice),
                'gross_amount' => $gross,
                'net_amount' => $gross,
                'price_override_reason' => $reason,
                'price_override_by' => $approver->id,
                'revision' => $line->revision + 1,
            ]);
            Audit::record('line.price_override', $line, $actor->id, $before, $line->only(['unit_price', 'gross_amount']), $reason, $approver->id, $order->outlet_id, Decimal::money($diff));
            $this->recalculate($order);

            return $line->fresh();
        });
    }

    /** Void baris sebelum finalisasi. Pack dilepas kembali kecuali sudah mulai dimasak. */
    public function voidLine(OrderLine $line, User $actor, ?string $reason = null, ?array $approval = null): void
    {
        DB::transaction(function () use ($line, $actor, $reason, $approval) {
            $order = $this->lockEditable($line->order);
            $line = OrderLine::lockForUpdate()->findOrFail($line->id);
            if ($line->status !== 'active') {
                return;
            }

            $approver = null;
            if ($order->status === 'confirmed') {
                // Item sudah dikirim ke dapur: void butuh supervisor.
                $approver = Approval::verify($approval, $actor->id, $order->outlet_id, $line->net_amount);
            }

            if ($line->pack_id) {
                $job = CookingJob::where('order_id', $order->id)->where('pack_id', $line->pack_id)->first();
                if ($job && in_array($job->status, ['cooking', 'served'], true)) {
                    throw DomainException::conflict('pack_already_cooked', 'Daging sudah dimasak; batalkan lewat dapur sebagai waste, bukan void.');
                }
                $pack = Pack::lockForUpdate()->find($line->pack_id);
                if ($pack && $pack->status === 'reserved' && $pack->reserved_order_id === $order->id) {
                    $pack->update(['status' => 'received', 'reserved_order_id' => null]);
                    $this->packEvent($pack, 'released', 'received', $actor->id, 'order', $order->id, $reason);
                }
            }

            $line->update(['status' => 'voided', 'revision' => $line->revision + 1]);
            if ($order->status === 'confirmed') {
                app(KitchenService::class)->lineVoided($line, $actor, $reason);
            }
            Audit::record('line.void', $line, $actor->id, reason: $reason, approverId: $approver?->id, outletId: $order->outlet_id, amount: $line->net_amount);
            $this->recalculate($order);
        });
    }

    public function hold(Order $order): Order
    {
        $order = $this->lockEditable($order);
        $order->update(['status' => 'held']);

        return $order;
    }

    public function resume(Order $order): Order
    {
        if ($order->status === 'held') {
            $order->update(['status' => 'draft']);
        }

        return $order->fresh();
    }

    /** Hitung ulang promo dan total. Dipanggil di dalam transaksi pemanggil. */
    public function recalculate(Order $order): Order
    {
        $lines = $order->lines()->where('status', 'active')->with('product')->get();

        $input = $lines->map(fn (OrderLine $l) => [
            'key' => $l->id,
            'product_id' => $l->product_id,
            'category' => $l->product?->category,
            'line_type' => $l->line_type,
            'quantity' => (string) $l->quantity,
            'unit_price' => (string) $l->unit_price,
            'gross' => (string) $l->gross_amount,
        ])->all();

        $promos = $this->promotions->for($order->outlet_id, $order->channel);
        $result = $this->engine->evaluate($input, $promos);

        DiscountAllocation::whereIn('order_line_id', $lines->pluck('id'))->delete();
        foreach ($lines as $line) {
            $r = $result['lines'][$line->id];
            $line->update(['discount_amount' => $r['discount'], 'net_amount' => $r['net']]);
            foreach ($r['allocations'] as $a) {
                DiscountAllocation::create(['order_line_id' => $line->id] + $a);
            }
        }

        $gross = Decimal::sum($lines->pluck('gross_amount'));
        $order->update([
            'gross_amount' => Decimal::money($gross),
            'discount_amount' => $result['total_discount'],
            'net_amount' => Decimal::money($gross->minus(Decimal::of($result['total_discount']))),
            'promotion_snapshot' => ['applied' => $result['applied'], 'evaluated_at' => now()->toIso8601String()],
            'version' => $order->version + 1,
        ]);

        return $order;
    }

    /**
     * Finalisasi: pembayaran sah harus sama dengan tagihan. Stok keluar tepat sekali,
     * Pack ID menjadi sold, nomor struk terbit, dan event masuk outbox.
     */
    public function finalize(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'paid' || $order->status === 'closed') {
                return $order; // idempoten: finalisasi ulang tidak memotong stok lagi
            }
            if (! $order->isEditable()) {
                throw DomainException::conflict('order_not_open', 'Pesanan tidak dapat difinalisasi.');
            }
            $this->assertCustomer($order);

            $paid = Decimal::sum($order->payments()->where('status', 'succeeded')->pluck('amount'));
            if (! $paid->isEqualTo(Decimal::of($order->net_amount))) {
                throw new DomainException('payment_mismatch', 'Total pembayaran berhasil belum sama dengan tagihan.', 422, [
                    'due' => (string) $order->net_amount, 'paid' => Decimal::money($paid),
                ]);
            }
            if ($order->payments()->whereIn('status', ['pending', 'uncertain'])->exists()) {
                throw new DomainException('payment_uncertain', 'Masih ada pembayaran yang statusnya belum pasti. Cek status dulu.');
            }

            $outlet = Outlet::lockForUpdate()->findOrFail($order->outlet_id);
            $lines = $order->lines()->where('status', 'active')->with('product')->get();

            foreach ($lines as $line) {
                $this->deductStock($order, $line, $actor);
            }

            $order->update([
                'doc_no' => $this->nextDocNo($outlet, $order),
                'status' => 'paid',
                'payment_status' => 'paid',
                'finalized_at' => now(),
                'version' => $order->version + 1,
            ]);

            Outbox::record(
                $order->outlet_id, 'order', $order->id, $order->version, 'order.finalized',
                $this->payload($order->fresh(['lines.discountAllocations', 'payments'])),
                $order->business_date->toDateString(),
            );
            Audit::record('order.finalized', $order, $actor->id, amount: $order->net_amount);

            return $order->fresh();
        });
    }

    /** Data pemesan pickup/delivery. Boleh diubah selama pesanan belum final. */
    public function setCustomer(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $order = $this->lockEditable($order);
            $order->update([
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'promised_at' => $data['promised_at'] ?? null,
                'fulfilment_note' => $data['fulfilment_note'] ?? null,
            ]);

            return $order;
        });
    }

    /** Pickup butuh nama dan nomor telepon; delivery juga butuh alamat antar. */
    private function assertCustomer(Order $order): void
    {
        if (! in_array($order->channel, ['pickup', 'delivery'], true)) {
            return;
        }
        if (blank($order->customer_name) || blank($order->customer_phone)) {
            throw new DomainException('customer_required', 'Isi nama dan nomor telepon pemesan untuk pesanan '.$order->channel.'.', 422);
        }
        if ($order->channel === 'delivery' && blank($order->delivery_address)) {
            throw new DomainException('address_required', 'Isi alamat antar untuk pesanan delivery.', 422);
        }
    }

    private function deductStock(Order $order, OrderLine $line, User $actor): void
    {
        if ($line->line_type === 'cooking_service') {
            return; // jasa bukan persediaan; bahan tambahan dikonsumsi lewat resep jasa di dapur
        }
        if ($line->line_type === 'restaurant_menu') {
            return; // menu dikonsumsi per resep saat dapur mulai memasak (lihat KitchenService)
        }
        if (! $line->product->is_stocked) {
            return;
        }

        StockLedger::move(
            $order->outlet_id, $line->product_id, Decimal::qty(Decimal::of($line->quantity)->negated()), $line->uom_code,
            'sale', 'order', $order->id, $line->line_no, packId: $line->pack_id,
            businessDate: $order->business_date->toDateString(),
        );

        if ($line->pack_id) {
            $pack = Pack::lockForUpdate()->findOrFail($line->pack_id);
            if ($pack->sold_order_line_id !== null) {
                throw DomainException::conflict('pack_already_sold', 'Kemasan sudah terjual pada transaksi lain.');
            }
            $pack->update(['status' => 'sold', 'sold_order_line_id' => $line->id, 'reserved_order_id' => null]);
            $this->packEvent($pack, 'sold', 'sold', $actor->id, 'order', $order->id);
        }
    }

    private function lockEditable(Order $order): Order
    {
        $order = Order::lockForUpdate()->findOrFail($order->id);
        if (! $order->isEditable()) {
            throw DomainException::conflict('order_not_editable', 'Pesanan sudah final dan tidak dapat diubah.');
        }

        return $order;
    }

    private function nextLineNo(Order $order): int
    {
        return (int) OrderLine::where('order_id', $order->id)->max('line_no') + 1;
    }

    private function nextDocNo(Outlet $outlet, Order $order): string
    {
        $prefix = $outlet->code.'-'.$order->business_date->format('ymd').'-';
        $last = Order::where('outlet_id', $outlet->id)->where('doc_no', 'like', $prefix.'%')->max('doc_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    private function packEvent(Pack $pack, string $event, string $to, ?int $actorId, string $sourceType, string $sourceId, ?string $reason = null): void
    {
        PackEvent::create([
            'pack_id' => $pack->id,
            'event' => $event,
            'from_status' => $pack->status,
            'to_status' => $to,
            'actor_id' => $actorId,
            'reason' => $reason,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'occurred_at' => now(),
        ]);
    }

    public function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'doc_no' => $order->doc_no,
            'outlet_id' => $order->outlet_id,
            'channel' => $order->channel,
            'customer' => $order->customer_name ? ['name' => $order->customer_name, 'phone' => $order->customer_phone] : null,
            'business_date' => $order->business_date->toDateString(),
            'gross_amount' => (string) $order->gross_amount,
            'discount_amount' => (string) $order->discount_amount,
            'net_amount' => (string) $order->net_amount,
            'finalized_at' => $order->finalized_at?->toIso8601ZuluString(),
            'lines' => $order->lines->where('status', 'active')->values()->map(fn (OrderLine $l) => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'line_type' => $l->line_type,
                'product_id' => $l->product_id,
                'pack_id' => $l->pack_id,
                'cooking_job_id' => $l->cooking_job_id,
                'quantity' => (string) $l->quantity,
                'uom' => $l->uom_code,
                'unit_price' => (string) $l->unit_price,
                'gross_amount' => (string) $l->gross_amount,
                'discount_amount' => (string) $l->discount_amount,
                'net_amount' => (string) $l->net_amount,
                'discounts' => $l->discountAllocations->map->only(['promotion_version_id', 'amount', 'funded_by'])->all(),
            ])->all(),
            'payments' => $order->payments->map->only(['id', 'method', 'amount', 'status', 'provider', 'provider_ref'])->all(),
        ];
    }
}
