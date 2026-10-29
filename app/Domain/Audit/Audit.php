<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;

/**
 * Jejak audit append-only. Kasir tidak memiliki jalur untuk menghapus baris ini.
 */
final class Audit
{
    public static function record(
        string $action,
        object|string $subject,
        ?int $actorId = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?int $approverId = null,
        ?int $outletId = null,
        string|int|null $amount = null,
    ): AuditEvent {
        [$type, $id] = is_object($subject)
            ? [class_basename($subject), (string) $subject->getKey()]
            : explode(':', $subject, 2) + [1 => ''];

        return AuditEvent::create([
            'outlet_id' => $outletId ?? (is_object($subject) ? ($subject->outlet_id ?? null) : null),
            'actor_id' => $actorId,
            'approver_id' => $approverId,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'reason' => $reason,
            'amount' => $amount,
            'correlation_id' => request()?->header('X-Correlation-Id'),
            'created_at' => now(),
        ]);
    }
}
