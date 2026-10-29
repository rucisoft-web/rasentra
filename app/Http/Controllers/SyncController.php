<?php

namespace App\Http\Controllers;

use App\Domain\Sync\InboxReceiver;
use App\Domain\Sync\Publications;
use App\Models\Device;
use App\Models\OutletSyncState;
use App\Models\PromotionTarget;
use App\Models\PublicationVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * API sinkronisasi Rasentra Central.
 */
class SyncController extends Controller
{
    private const PAGE = 200;

    public function events(Request $request, InboxReceiver $receiver): JsonResponse
    {
        $data = $request->validate(['events' => ['required', 'array', 'max:500']]);
        $device = $request->attributes->get('device');

        return response()->json(['results' => $receiver->receive($data['events'], $device->code)]);
    }

    /**
     * Delta versi untuk outlet pemanggil, berurutan menurut cursor pusat (bukan jam perangkat).
     * next_cursor maju melewati seluruh halaman, termasuk publikasi untuk outlet lain.
     */
    public function changes(Request $request, Publications $publications): JsonResponse
    {
        $device = $request->attributes->get('device');
        $cursor = (int) $request->query('cursor', 0);

        $page = PublicationVersion::where('id', '>', $cursor)->orderBy('id')->limit(self::PAGE)->get();
        $changes = $page
            ->filter(fn ($v) => $v->outlet_ids === null || in_array($device->outlet_id, $v->outlet_ids, true))
            ->reject(fn ($v) => $v->entity_type === 'user' && ! $this->isOutletServer($device))
            ->values();

        return response()->json([
            'outlet_id' => $device->outlet_id,
            'changes' => $changes->map(fn ($v) => [
                'id' => $v->id, 'entity_type' => $v->entity_type, 'entity_key' => $v->entity_key,
                'payload' => $publications->enrich($v->entity_type, $v->payload),
            ])->all(),
            'next_cursor' => $page->max('id') ?? $cursor,
            'has_more' => $page->count() === self::PAGE,
        ]);
    }

    /** Snapshot awal untuk Rasentra Lokal baru. Hanya untuk server outlet karena berisi PIN petugas (hash). */
    public function snapshot(Request $request, Publications $publications): JsonResponse
    {
        $device = $request->attributes->get('device');
        abort_unless($this->isOutletServer($device), 403, 'Snapshot hanya untuk server Rasentra Lokal.');

        return response()->json(['outlet_id' => $device->outlet_id] + $publications->snapshot($device->outlet_id));
    }

    public function ack(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'acks' => ['present', 'array'],
            'acks.*.entity_type' => ['required', 'string'],
            'acks.*.entity_key' => ['required', 'string'],
            'acks.*.status' => ['required', 'in:received,applied,failed'],
            'acks.*.error' => ['nullable', 'string', 'max:2000'],
        ]);
        $device = $request->attributes->get('device');

        foreach ($data['acks'] as $ack) {
            if ($ack['entity_type'] === 'promotion_version') {
                PromotionTarget::where('promotion_version_id', $ack['entity_key'])->where('outlet_id', $device->outlet_id)
                    ->update(['ack_status' => $ack['status'], 'acked_at' => now()]);
            }
        }
        $failed = collect($data['acks'])->firstWhere('status', 'failed');
        $state = $this->state($device);
        $state->fill(['last_pull_at' => now(), 'last_error' => $failed ? "{$failed['entity_type']} {$failed['entity_key']}: ".($failed['error'] ?? 'gagal diterapkan') : null]);
        if (isset($data['cursor'])) {
            $state->applied_cursor = max((int) $state->applied_cursor, (int) $data['cursor']);
        }
        $state->save();

        return response()->json(['ok' => true]);
    }

    /** Laporan kondisi Rasentra Lokal: antrean outbox, versi aplikasi, jam perangkat, cursor yang sudah diterapkan. */
    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sent_at' => ['nullable', 'date'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'applied_cursor' => ['nullable', 'integer', 'min:0'],
            'outbox' => ['nullable', 'array'],
            'outbox.pending' => ['nullable', 'integer', 'min:0'],
            'outbox.failed' => ['nullable', 'integer', 'min:0'],
            'outbox.parked' => ['nullable', 'integer', 'min:0'],
            'outbox.oldest_at' => ['nullable', 'date'],
            'outbox.last_sent_at' => ['nullable', 'date'],
            'open_business_date' => ['nullable', 'date_format:Y-m-d'],
            'open_shifts' => ['nullable', 'integer', 'min:0'],
            'last_error' => ['nullable', 'string', 'max:2000'],
        ]);
        $device = $request->attributes->get('device');
        $now = now();

        if ($this->isOutletServer($device)) {
            $state = $this->state($device);
            $state->fill([
                'last_heartbeat_at' => $now,
                'app_version' => $data['app_version'] ?? $state->app_version,
                'clock_skew_seconds' => isset($data['sent_at']) ? (int) round(Carbon::parse($data['sent_at'])->diffInSeconds($now, false) * -1) : null,
                'outbox_pending' => $data['outbox']['pending'] ?? 0,
                'outbox_failed' => $data['outbox']['failed'] ?? 0,
                'outbox_parked' => $data['outbox']['parked'] ?? 0,
                'outbox_oldest_at' => $data['outbox']['oldest_at'] ?? null,
                'last_sent_at' => $data['outbox']['last_sent_at'] ?? $state->last_sent_at,
                'open_business_date' => $data['open_business_date'] ?? null,
                'open_shifts' => $data['open_shifts'] ?? 0,
            ]);
            if (array_key_exists('applied_cursor', $data) && $data['applied_cursor'] !== null) {
                $state->applied_cursor = max((int) $state->applied_cursor, (int) $data['applied_cursor']);
            }
            if (array_key_exists('last_error', $data)) {
                $state->last_error = $data['last_error'];
            }
            $state->save();
        }

        return response()->json([
            'server_time' => $now->toIso8601ZuluString(),
            'central_cursor' => (int) PublicationVersion::max('id'),
        ]);
    }

    private function state(Device $device): OutletSyncState
    {
        return OutletSyncState::firstOrNew(['device_id' => $device->id], ['outlet_id' => $device->outlet_id]);
    }

    private function isOutletServer(Device $device): bool
    {
        return $device->app === 'outlet_server';
    }
}
