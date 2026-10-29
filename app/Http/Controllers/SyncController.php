<?php

namespace App\Http\Controllers;

use App\Domain\Sync\InboxReceiver;
use App\Models\PromotionTarget;
use App\Models\PublicationVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API sinkronisasi Rasentra Central.
 */
class SyncController extends Controller
{
    public function events(Request $request, InboxReceiver $receiver): JsonResponse
    {
        $data = $request->validate(['events' => ['required', 'array', 'max:500']]);
        $device = $request->attributes->get('device');

        return response()->json(['results' => $receiver->receive($data['events'], $device->code)]);
    }

    /** Delta versi untuk outlet pemanggil, berurutan menurut cursor pusat (bukan jam perangkat). */
    public function changes(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $cursor = (int) $request->query('cursor', 0);

        $changes = PublicationVersion::where('id', '>', $cursor)->orderBy('id')->limit(200)->get()
            ->filter(fn ($v) => $v->outlet_ids === null || in_array($device->outlet_id, $v->outlet_ids, true))
            ->values();

        return response()->json([
            'changes' => $changes->map->only(['id', 'entity_type', 'entity_key', 'payload'])->all(),
            'next_cursor' => $changes->max('id') ?? $cursor,
        ]);
    }

    public function ack(Request $request): JsonResponse
    {
        $data = $request->validate([
            'acks' => ['required', 'array'],
            'acks.*.entity_type' => ['required', 'string'],
            'acks.*.entity_key' => ['required', 'string'],
            'acks.*.status' => ['required', 'in:received,applied,failed'],
        ]);
        $device = $request->attributes->get('device');

        foreach ($data['acks'] as $ack) {
            if ($ack['entity_type'] === 'promotion_version') {
                PromotionTarget::where('promotion_version_id', $ack['entity_key'])->where('outlet_id', $device->outlet_id)
                    ->update(['ack_status' => $ack['status'], 'acked_at' => now()]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        return response()->json(['server_time' => now()->toIso8601ZuluString()]);
    }
}
