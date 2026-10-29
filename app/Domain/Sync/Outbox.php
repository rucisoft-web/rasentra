<?php

namespace App\Domain\Sync;

use App\Models\OutboxEvent;
use Illuminate\Support\Str;

/**
 * Menulis event bisnis ke outbox. Wajib dipanggil di dalam DB::transaction yang sama
 * dengan dokumen dan pergerakan stoknya, sehingga tidak ada transaksi tanpa event.
 */
final class Outbox
{
    public static function record(
        int $outletId,
        string $entityType,
        string $entityId,
        int $entityVersion,
        string $eventType,
        array $payload,
        string $businessDate,
        ?string $correlationId = null,
    ): OutboxEvent {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return OutboxEvent::create([
            'event_id' => (string) Str::uuid7(),
            'source_id' => config('rasentra.source_id'),
            'outlet_id' => $outletId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_version' => $entityVersion,
            'event_type' => $eventType,
            'schema_version' => 1,
            'occurred_at' => now(),
            'business_date' => $businessDate,
            'correlation_id' => $correlationId ?? (string) Str::uuid7(),
            'payload' => $payload,
            'checksum' => hash('sha256', $json),
            'status' => 'pending',
        ]);
    }
}
