<?php

namespace App\Domain\Sales;

use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\Hash;

/**
 * Otorisasi supervisor di server. PIN mempercepat input di layar sentuh, tetapi tetap
 * divalidasi di server: penyetuju harus aktif, satu outlet, berperan supervisor, bukan
 * pembuat transaksi, dan limit nominalnya cukup.
 */
final class Approval
{
    public const APPROVER_ROLES = ['outlet_head', 'admin'];

    public static function verify(?array $approval, int $requesterId, int $outletId, string|int $amount = 0): User
    {
        if (empty($approval['approver_id']) || empty($approval['pin'])) {
            throw DomainException::needsApproval('approval_required', 'Butuh persetujuan kepala outlet.');
        }

        $approver = User::find($approval['approver_id']);

        if ($approver === null
            || ! $approver->is_active
            || ! in_array($approver->role, self::APPROVER_ROLES, true)
            || ($approver->role !== 'admin' && (int) $approver->outlet_id !== $outletId)
            || $approver->pin_hash === null
            || ! Hash::check((string) $approval['pin'], $approver->pin_hash)) {
            throw DomainException::needsApproval('approval_invalid', 'PIN atau penyetuju tidak sah.');
        }

        if ($approver->id === $requesterId) {
            throw DomainException::needsApproval('approval_self', 'Penyetuju harus orang yang berbeda.');
        }

        if (Decimal::of($amount)->isGreaterThan(Decimal::of($approver->approval_limit))) {
            throw DomainException::needsApproval('approval_limit', 'Nominal melebihi limit penyetuju.');
        }

        return $approver;
    }
}
