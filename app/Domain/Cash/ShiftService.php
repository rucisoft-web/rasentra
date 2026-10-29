<?php

namespace App\Domain\Cash;

use App\Domain\Audit\Audit;
use App\Domain\Sales\Approval;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Shift dan laci kas. Closing dapat dilakukan tanpa koneksi pusat; status posting SAP terpisah.
 */
final class ShiftService
{
    public function open(User $user, int $outletId, string $openingCash, ?int $deviceId = null): Shift
    {
        if (Shift::where('user_id', $user->id)->where('status', 'open')->exists()) {
            throw DomainException::conflict('shift_already_open', 'Anda masih memiliki shift terbuka.');
        }

        return Shift::create([
            'outlet_id' => $outletId,
            'device_id' => $deviceId,
            'user_id' => $user->id,
            'business_date' => now()->toDateString(),
            'opening_cash' => Decimal::money($openingCash),
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    public function cashMovement(Shift $shift, User $user, string $type, string $amount, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('reason_required', 'Alasan wajib diisi.');
        }
        $movement = $shift->cashMovements()->create([
            'type' => $type, 'amount' => Decimal::money($amount), 'reason' => $reason, 'user_id' => $user->id,
        ]);
        Audit::record("cash.{$type}", $movement, $user->id, reason: $reason, outletId: $shift->outlet_id, amount: $amount);
    }

    /** Ringkasan sistem per metode bayar untuk shift ini. */
    public function expected(Shift $shift): array
    {
        $byMethod = Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.shift_id', $shift->id)
            ->where('payments.status', 'succeeded')
            ->get(['payments.method', 'payments.amount'])
            ->groupBy('method')
            ->map(fn ($rows) => Decimal::money(Decimal::sum($rows->pluck('amount'))))
            ->all();

        $cashIn = Decimal::sum($shift->cashMovements()->where('type', 'cash_in')->pluck('amount'));
        $cashOut = Decimal::sum($shift->cashMovements()->where('type', 'cash_out')->pluck('amount'));
        $byMethod['cash'] = Decimal::money(
            Decimal::of($shift->opening_cash)->plus(Decimal::of($byMethod['cash'] ?? '0'))->plus($cashIn)->minus($cashOut)
        );

        return $byMethod;
    }

    public function close(Shift $shift, User $user, array $counts, ?array $approval = null, string $threshold = '10000'): Shift
    {
        return DB::transaction(function () use ($shift, $user, $counts, $approval, $threshold) {
            $shift = Shift::lockForUpdate()->findOrFail($shift->id);
            if ($shift->status !== 'open') {
                throw DomainException::conflict('shift_not_open', 'Shift sudah ditutup.');
            }
            $expected = $this->expected($shift);
            $diff = Decimal::of($counts['cash'] ?? '0')->minus(Decimal::of($expected['cash']));

            $approver = null;
            if ($diff->abs()->isGreaterThan(Decimal::of($threshold))) {
                $approver = Approval::verify($approval, $user->id, $shift->outlet_id, Decimal::money($diff->abs()));
            }

            $shift->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closing_counts' => array_map(fn ($v) => Decimal::money((string) $v), $counts),
                'closing_expected' => $expected,
                'cash_difference' => Decimal::money($diff),
                'closing_approved_by' => $approver?->id,
            ]);
            Audit::record('shift.closed', $shift, $user->id, approverId: $approver?->id, amount: Decimal::money($diff));

            return $shift;
        });
    }
}
