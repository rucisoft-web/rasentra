<?php

namespace App\Domain\Sync;

use App\Domain\Core\SalesProjector;
use App\Models\InboxEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rasentra Central: menerima batch event dari outlet secara idempoten. Unique
 * (source_id, event_id) adalah penjaga utama; kirim ulang mengembalikan ack_ref yang sama.
 * Respons per item karena HTTP 200 tidak berarti semua item berhasil.
 */
final class InboxReceiver
{
    public function __construct(private readonly SalesProjector $sales) {}

    public const REQUIRED = ['event_id', 'source_id', 'outlet_id', 'entity_type', 'entity_id', 'entity_version', 'event_type', 'payload', 'checksum'];

    /** @return list<array{event_id:string, result:string, ack_ref?:string, error?:string}> */
    public function receive(array $events, string $authenticatedSourceId): array
    {
        $results = [];
        foreach ($events as $event) {
            $results[] = $this->receiveOne($event, $authenticatedSourceId);
        }

        return $results;
    }

    private function receiveOne(array $event, string $sourceId): array
    {
        $eventId = (string) ($event['event_id'] ?? '');
        foreach (self::REQUIRED as $field) {
            if (! array_key_exists($field, $event)) {
                return ['event_id' => $eventId, 'result' => 'rejected', 'error' => "field {$field} wajib"];
            }
        }
        if ($event['source_id'] !== $sourceId) {
            return ['event_id' => $eventId, 'result' => 'rejected', 'error' => 'source_id tidak sesuai token perangkat'];
        }
        $json = json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (! hash_equals((string) $event['checksum'], hash('sha256', $json))) {
            return ['event_id' => $eventId, 'result' => 'rejected', 'error' => 'checksum tidak cocok'];
        }

        $existing = InboxEvent::where('source_id', $sourceId)->where('event_id', $eventId)->first();
        if ($existing) {
            return ['event_id' => $eventId, 'result' => 'duplicate', 'ack_ref' => $existing->ack_ref];
        }

        try {
            $inbox = DB::transaction(fn () => tap(InboxEvent::create([
                'source_id' => $sourceId,
                'event_id' => $eventId,
                'outlet_id' => $event['outlet_id'],
                'entity_type' => $event['entity_type'],
                'entity_id' => $event['entity_id'],
                'entity_version' => $event['entity_version'],
                'event_type' => $event['event_type'],
                'payload' => $event['payload'],
                'checksum' => $event['checksum'],
                'ack_ref' => 'ACK-'.Str::upper(Str::random(20)),
                'status' => 'accepted',
            ]), fn (InboxEvent $e) => $this->sales->project($e)));
        } catch (UniqueConstraintViolationException) {
            // Dua worker mengirim event sama bersamaan: yang kalah membaca ack milik pemenang.
            $inbox = InboxEvent::where('source_id', $sourceId)->where('event_id', $eventId)->firstOrFail();

            return ['event_id' => $eventId, 'result' => 'duplicate', 'ack_ref' => $inbox->ack_ref];
        }

        return ['event_id' => $eventId, 'result' => 'accepted', 'ack_ref' => $inbox->ack_ref];
    }
}
