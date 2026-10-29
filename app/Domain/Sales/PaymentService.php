<?php

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Pembayaran memiliki status sendiri. Status "uncertain" (timeout provider) tidak boleh
 * dianggap gagal; kasir wajib cek status sebelum meminta pelanggan membayar ulang.
 */
final class PaymentService
{
    public const METHODS = ['cash', 'qris', 'edc', 'edc_manual', 'voucher', 'points'];

    public function add(Order $order, string $method, string $amount, array $data = []): Payment
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new DomainException('payment_method_invalid', 'Metode pembayaran tidak dikenal.');
        }

        return DB::transaction(function () use ($order, $method, $amount, $data) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->isEditable()) {
                throw DomainException::conflict('order_not_editable', 'Pesanan sudah final.');
            }

            $paid = Decimal::sum($order->payments()->whereIn('status', ['succeeded', 'pending', 'uncertain'])->pluck('amount'));
            $remaining = Decimal::of($order->net_amount)->minus($paid);
            $amount = Decimal::of($amount);
            if (! $amount->isPositive()) {
                throw new DomainException('payment_amount_invalid', 'Nominal pembayaran harus lebih dari nol.', 422);
            }

            $tendered = null;
            $change = null;
            if ($method === 'cash') {
                $tendered = Decimal::of($data['tendered'] ?? $amount);
                $amount = $amount->isGreaterThan($remaining) ? $remaining : $amount;
                $change = $tendered->minus($amount);
                if ($change->isNegative()) {
                    throw new DomainException('cash_insufficient', 'Uang diterima kurang dari nominal.');
                }
            } elseif ($amount->isGreaterThan($remaining)) {
                throw new DomainException('payment_exceeds_due', 'Nominal non-tunai melebihi sisa tagihan.');
            }

            // Tunai dan EDC manual langsung berhasil (dengan bukti); QRIS/EDC terintegrasi menunggu provider.
            $status = in_array($method, ['cash', 'edc_manual', 'voucher', 'points'], true) ? 'succeeded' : 'pending';

            return $order->payments()->create([
                'method' => $method,
                'amount' => Decimal::money($amount),
                'tendered' => $tendered ? Decimal::money($tendered) : null,
                'change_amount' => $change ? Decimal::money($change) : null,
                'status' => $status,
                'provider' => $data['provider'] ?? null,
                'provider_ref' => $data['provider_ref'] ?? null,
                'verified_by_api' => false,
            ]);
        });
    }

    /** Hasil dari provider (webhook yang diteruskan pusat, atau query status). Idempoten. */
    public function applyProviderResult(Payment $payment, string $status, string $amount): Payment
    {
        return DB::transaction(function () use ($payment, $status, $amount) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if (! Decimal::of($amount)->isEqualTo(Decimal::of($payment->amount))) {
                Audit::record('payment.amount_mismatch', $payment, reason: "provider={$amount}");
                throw DomainException::conflict('payment_amount_mismatch', 'Nominal dari provider tidak sama dengan tagihan.');
            }
            // Notifikasi berulang atau urutan terbalik: status final tidak boleh mundur.
            if (in_array($payment->status, ['succeeded', 'refunded', 'refunded_partial'], true)) {
                return $payment;
            }
            $payment->update(['status' => $status, 'verified_by_api' => true]);

            return $payment;
        });
    }

    public function markUncertain(Payment $payment): Payment
    {
        if ($payment->status === 'pending') {
            $payment->update(['status' => 'uncertain']);
        }

        return $payment;
    }
}
