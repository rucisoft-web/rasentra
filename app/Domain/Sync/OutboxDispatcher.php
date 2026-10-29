<?php

namespace App\Domain\Sync;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Rasentra Lokal (server outlet): kirim event outbox ke Rasentra Central. At-least-once; idempotensi
 * dijamin penerima. Gagal = backoff eksponensial + jitter, lalu parked.
 */
final class OutboxDispatcher
{
    public function dispatch(): array
    {
        $events = OutboxEvent::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit(config('rasentra.central.batch_size', 500))
            ->get();

        if ($events->isEmpty()) {
            return ['sent' => 0];
        }

        OutboxEvent::whereIn('id', $events->pluck('id'))->update(['status' => 'sending']);

        try {
            $response = Http::withToken((string) config('rasentra.central.token'))
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim((string) config('rasentra.central.url'), '/').'/api/v1/sync/events', [
                    'events' => $events->map(fn (OutboxEvent $e) => [
                        'event_id' => $e->event_id,
                        'source_id' => $e->source_id,
                        'outlet_id' => $e->outlet_id,
                        'entity_type' => $e->entity_type,
                        'entity_id' => $e->entity_id,
                        'entity_version' => $e->entity_version,
                        'event_type' => $e->event_type,
                        'schema_version' => $e->schema_version,
                        'occurred_at' => $e->occurred_at->toIso8601ZuluString('microsecond'),
                        'business_date' => $e->business_date->toDateString(),
                        'correlation_id' => $e->correlation_id,
                        'payload' => $e->payload,
                        'checksum' => $e->checksum,
                    ])->all(),
                ]);
            $response->throw();
            $results = collect($response->json('results', []))->keyBy('event_id');
        } catch (Throwable $e) {
            foreach ($events as $event) {
                $this->fail($event, $e->getMessage());
            }

            return ['sent' => 0, 'error' => $e->getMessage()];
        }

        $acked = 0;
        foreach ($events as $event) {
            $result = $results->get($event->event_id);
            if ($result && in_array($result['result'], ['accepted', 'duplicate'], true)) {
                $event->update(['status' => 'acked', 'ack_ref' => $result['ack_ref'], 'last_error' => null]);
                $acked++;
            } else {
                $this->fail($event, $result['error'] ?? 'tidak ada hasil untuk event ini');
            }
        }

        return ['sent' => $acked];
    }

    private function fail(OutboxEvent $event, string $error): void
    {
        $attempts = $event->attempts + 1;
        $base = config('rasentra.sync.backoff_base', 5);
        $delay = min(config('rasentra.sync.backoff_max', 900), $base * (2 ** min($attempts, 10)));
        $delay = (int) ($delay * (0.5 + mt_rand() / mt_getrandmax() / 2)); // jitter 50-100%

        $event->update([
            'status' => $attempts >= config('rasentra.sync.max_attempts', 20) ? 'parked' : 'failed',
            'attempts' => $attempts,
            'next_attempt_at' => now()->addSeconds($delay),
            'last_error' => mb_substr($error, 0, 2000),
        ]);
    }
}
