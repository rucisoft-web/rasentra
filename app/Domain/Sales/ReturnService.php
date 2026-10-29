<?php

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Domain\Inventory\StockLedger;
use App\Domain\Sync\Outbox;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\PackEvent;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\Shift;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Retur penjualan dari struk final. Selalu butuh PIN kepala outlet. Nilai retur dihitung
 * pro-rata dari nilai bersih baris (setelah promo), sehingga potongan ikut kembali sesuai
 * porsinya. Barang retail kembali ke stok; Pack ID daging masuk karantina untuk diperiksa.
 * Uang tunai keluar dari laci shift kasir dan mengurangi saldo kas yang diharapkan.
 */
final class ReturnService
{
    /**
     * @param  list<array{order_line_id:string, quantity:string, restock?:bool}>  $items
     * @param  array{method:string, ref?:?string}  $refund
     */
    public function create(Order $order, User $cashier, array $items, string $reason, array $refund, ?array $approval): SalesReturn
    {
        return DB::transaction(function () use ($order, $cashier, $items, $reason, $refund, $approval) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->isFinal()) {
                throw DomainException::conflict('order_not_final', 'Hanya struk yang sudah selesai yang bisa diretur.');
            }
            $days = (int) config('rasentra.pos.return_days', 7);
            $today = Outlet::businessDate($order->outlet_id);
            if ($order->business_date->copy()->addDays($days)->toDateString() < $today) {
                throw DomainException::conflict('return_expired', "Retur hanya bisa dalam {$days} hari sejak transaksi.");
            }
            if (trim($reason) === '') {
                throw new DomainException('reason_required', 'Alasan retur wajib diisi.', 422);
            }
            if ($items === []) {
                throw new DomainException('return_empty', 'Pilih barang yang diretur.', 422);
            }

            $rows = [];
            foreach ($items as $item) {
                /** @var OrderLine $line */
                $line = OrderLine::lockForUpdate()->with('product')->where('order_id', $order->id)->where('status', 'active')->find($item['order_line_id']);
                if ($line === null) {
                    throw new DomainException('return_line_invalid', 'Baris tidak ditemukan pada struk ini.', 422);
                }
                $remaining = Decimal::of($line->quantity)->minus(Decimal::of($line->returned_quantity));
                $qty = Decimal::of($item['quantity']);
                if ($line->pack_id) {
                    $qty = $remaining; // Pack ID diretur utuh sesuai berat label
                }
                if (! $qty->isPositive() || $qty->isGreaterThan($remaining)) {
                    throw new DomainException('return_quantity', "Jumlah retur {$line->product->name} melebihi sisa yang bisa diretur ({$remaining}).", 422);
                }
                $rows[] = [$line, $qty, $qty->isEqualTo($remaining), (bool) ($item['restock'] ?? true)];
            }

            $lines = [];
            foreach ($rows as [$line, $qty, $isLast, $restock]) {
                $lines[] = ['line' => $line, 'qty' => $qty, 'restock' => $restock] + $this->amounts($line, $qty, $isLast);
            }
            $net = Decimal::sum(array_column($lines, 'net'));
            $gross = Decimal::sum(array_column($lines, 'gross'));

            $method = $refund['method'] ?? 'cash';
            $this->assertRefundMethod($order, $method, $net);
            $shift = Shift::where('user_id', $cashier->id)->where('status', 'open')->first();
            if ($method === 'cash' && $shift === null) {
                throw DomainException::conflict('shift_required', 'Buka shift dulu: uang retur tunai keluar dari laci Anda.');
            }

            $approver = Approval::verify($approval, $cashier->id, $order->outlet_id, Decimal::money($net));
            $outlet = Outlet::lockForUpdate()->findOrFail($order->outlet_id);

            $return = SalesReturn::create([
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'doc_no' => $this->nextDocNo($outlet, $today),
                'shift_id' => $shift?->id,
                'user_id' => $cashier->id,
                'approved_by' => $approver->id,
                'business_date' => $today,
                'reason' => $reason,
                'refund_method' => $method,
                'refund_ref' => $refund['ref'] ?? null,
                'gross_amount' => Decimal::money($gross),
                'discount_amount' => Decimal::money($gross->minus($net)),
                'net_amount' => Decimal::money($net),
            ]);

            foreach ($lines as $i => $l) {
                /** @var OrderLine $line */
                $line = $l['line'];
                $disposition = $this->restore($return, $line, $l['qty'], $l['restock'], $cashier, $i + 1, $today);
                SalesReturnLine::create([
                    'sales_return_id' => $return->id,
                    'order_line_id' => $line->id,
                    'line_no' => $i + 1,
                    'product_id' => $line->product_id,
                    'pack_id' => $line->pack_id,
                    'quantity' => Decimal::qty($l['qty']),
                    'uom_code' => $line->uom_code,
                    'gross_amount' => Decimal::money($l['gross']),
                    'discount_amount' => Decimal::money($l['gross']->minus($l['net'])),
                    'net_amount' => Decimal::money($l['net']),
                    'disposition' => $disposition,
                ]);
                $line->update(['returned_quantity' => Decimal::qty(Decimal::of($line->returned_quantity)->plus($l['qty']))]);
            }

            Outbox::record($return->outlet_id, 'sales_return', $return->id, 1, 'order.returned', $this->payload($return->fresh('lines'), $order), $today);
            Audit::record('order.returned', $return, $cashier->id, approverId: $approver->id, reason: $reason, outletId: $return->outlet_id, amount: $return->net_amount,
                after: ['order' => $order->doc_no, 'refund' => $method, 'lines' => count($lines)]);

            return $return->fresh('lines');
        });
    }

    /** Sisa nilai yang masih bisa dikembalikan per metode bayar struk ini. */
    public function refundable(Order $order): array
    {
        $paid = $order->payments()->where('status', 'succeeded')->get()->groupBy('method')->map(fn ($g) => Decimal::sum($g->pluck('amount')));
        $refunded = SalesReturn::where('order_id', $order->id)->get()->groupBy('refund_method')->map(fn ($g) => Decimal::sum($g->pluck('net_amount')));
        $total = Decimal::of($order->net_amount)->minus(Decimal::sum($refunded->all()));

        return ['total' => Decimal::money($total)] + $paid->map(fn ($v, $m) => Decimal::money($v->minus($refunded[$m] ?? BigDecimal::zero())))->all();
    }

    /** Nilai baris yang diretur. Porsi terakhir memakai sisa nilai agar tidak ada selisih pembulatan. */
    private function amounts(OrderLine $line, BigDecimal $qty, bool $isLast): array
    {
        $done = SalesReturnLine::where('order_line_id', $line->id)->get(['gross_amount', 'net_amount']);
        if ($isLast) {
            return [
                'gross' => Decimal::of($line->gross_amount)->minus(Decimal::sum($done->pluck('gross_amount'))),
                'net' => Decimal::of($line->net_amount)->minus(Decimal::sum($done->pluck('net_amount'))),
            ];
        }
        $ratio = fn ($amount) => Decimal::of($amount)->multipliedBy($qty)->dividedBy(Decimal::of($line->quantity), 2, Decimal::rounding());

        return ['gross' => $ratio($line->gross_amount), 'net' => $ratio($line->net_amount)];
    }

    private function assertRefundMethod(Order $order, string $method, BigDecimal $net): void
    {
        $refundable = $this->refundable($order);
        if ($net->isGreaterThan(Decimal::of($refundable['total']))) {
            throw DomainException::conflict('return_exceeds', 'Nilai retur melebihi sisa nilai struk.');
        }
        if ($method === 'cash') {
            return; // toko selalu bisa mengembalikan tunai dari laci
        }
        if (! array_key_exists($method, $refundable) || $net->isGreaterThan(Decimal::of($refundable[$method]))) {
            throw new DomainException('refund_method', "Pengembalian lewat {$method} melebihi pembayaran {$method} pada struk ini. Pilih tunai.", 422);
        }
    }

    /** Kembalikan stok sesuai jenis baris. @return string disposition */
    private function restore(SalesReturn $return, OrderLine $line, BigDecimal $qty, bool $restock, User $actor, int $lineNo, string $date): string
    {
        if (in_array($line->line_type, ['cooking_service', 'restaurant_menu'], true) || ! $line->product->is_stocked) {
            return 'none'; // jasa dan menu yang sudah dimasak tidak kembali ke stok
        }
        // Daging dengan Pack ID selalu ke karantina; petugas memutuskan kembali ke stok atau waste.
        $quarantine = $line->pack_id !== null || ! $restock;
        $location = $quarantine ? Location::where('outlet_id', $return->outlet_id)->where('type', 'quarantine')->value('id') : null;

        StockLedger::move($return->outlet_id, $line->product_id, Decimal::qty($qty), $line->uom_code, 'return', 'sales_return', $return->id, $lineNo,
            locationId: $location, packId: $line->pack_id, state: $quarantine ? 'quarantine' : 'available', businessDate: $date);

        if ($line->pack_id) {
            $pack = Pack::lockForUpdate()->findOrFail($line->pack_id);
            PackEvent::create([
                'pack_id' => $pack->id, 'event' => 'returned', 'from_status' => $pack->status, 'to_status' => 'quarantine', 'actor_id' => $actor->id,
                'reason' => $return->reason, 'source_type' => 'sales_return', 'source_id' => $return->id, 'occurred_at' => now(),
            ]);
            $pack->update(['status' => 'quarantine']);
        }

        return $quarantine ? 'quarantine' : 'restock';
    }

    private function nextDocNo(Outlet $outlet, string $date): string
    {
        $prefix = $outlet->code.'-R'.date('ymd', strtotime($date)).'-';
        $last = SalesReturn::where('outlet_id', $outlet->id)->where('doc_no', 'like', $prefix.'%')->max('doc_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function payload(SalesReturn $return, Order $order): array
    {
        $orderLines = $order->lines()->get()->keyBy('id');

        return [
            'id' => $return->id,
            'doc_no' => $return->doc_no,
            'original_order_id' => $order->id,
            'original_doc_no' => $order->doc_no,
            'original_business_date' => $order->business_date->toDateString(),
            'outlet_id' => $return->outlet_id,
            'channel' => $order->channel,
            'business_date' => $return->business_date->toDateString(),
            'reason' => $return->reason,
            'gross_amount' => (string) $return->gross_amount,
            'discount_amount' => (string) $return->discount_amount,
            'net_amount' => (string) $return->net_amount,
            'refund' => ['method' => $return->refund_method, 'amount' => (string) $return->net_amount, 'ref' => $return->refund_ref],
            'finalized_at' => $return->created_at->toIso8601ZuluString(),
            'lines' => $return->lines->map(fn (SalesReturnLine $l) => [
                'line_no' => $l->line_no,
                'order_line_no' => $orderLines[$l->order_line_id]->line_no ?? null,
                'line_type' => $orderLines[$l->order_line_id]->line_type ?? 'retail_product',
                'product_id' => $l->product_id,
                'pack_id' => $l->pack_id,
                'quantity' => (string) $l->quantity,
                'uom' => $l->uom_code,
                'unit_price' => (string) ($orderLines[$l->order_line_id]->unit_price ?? '0'),
                'gross_amount' => (string) $l->gross_amount,
                'discount_amount' => (string) $l->discount_amount,
                'net_amount' => (string) $l->net_amount,
                'disposition' => $l->disposition,
            ])->all(),
        ];
    }
}
