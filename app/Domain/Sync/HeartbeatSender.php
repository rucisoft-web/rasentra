<?php

namespace App\Domain\Sync;

use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\Shift;
use App\Support\SystemState;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Rasentra Lokal: laporan kondisi ke Rasentra Central setiap menit. Central memakainya untuk
 * melihat outlet yang antreannya menumpuk, jamnya bergeser, atau tertinggal publikasi.
 */
final class HeartbeatSender
{
    public function __construct(
        private readonly CentralClient $central,
        private readonly PublicationApplier $applier,
    ) {}

    public function payload(): array
    {
        $open = OutboxEvent::whereIn('status', ['pending', 'sending', 'failed', 'parked']);
        $outletId = Outlet::where('code', config('rasentra.outlet_code'))->value('id');
        $openShifts = $outletId ? Shift::where('outlet_id', $outletId)->where('status', 'open') : null;
        $lastPull = SystemState::get('sync:last_pull');

        return [
            'sent_at' => now()->toIso8601ZuluString(),
            'app_version' => config('rasentra.version'),
            'applied_cursor' => $this->applier->cursor(),
            'outbox' => [
                'pending' => (clone $open)->whereIn('status', ['pending', 'sending'])->count(),
                'failed' => (clone $open)->where('status', 'failed')->count(),
                'parked' => (clone $open)->where('status', 'parked')->count(),
                'oldest_at' => ($oldest = (clone $open)->min('occurred_at')) ? Carbon::parse($oldest)->toIso8601ZuluString() : null,
                'last_sent_at' => ($sent = OutboxEvent::where('status', 'acked')->max('updated_at')) ? Carbon::parse($sent)->toIso8601ZuluString() : null,
            ],
            'open_business_date' => $openShifts && ($date = (clone $openShifts)->min('business_date')) ? substr((string) $date, 0, 10) : null,
            'open_shifts' => $openShifts ? $openShifts->count() : 0,
            'last_error' => $lastPull['error'] ?? null,
        ];
    }

    /** @return array{ok:bool, clock_skew_seconds?:int, central_cursor?:int, device?:array, error?:string} */
    public function send(): array
    {
        try {
            $payload = $this->payload();
            $response = $this->central->request(10)->post('heartbeat', $payload)->throw()->json();
            $skew = (int) round(Carbon::parse($payload['sent_at'])->diffInSeconds(Carbon::parse($response['server_time']), false) * -1);
            $result = ['ok' => true, 'clock_skew_seconds' => $skew, 'central_cursor' => $response['central_cursor'] ?? null, 'device' => $response['device'] ?? null];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
        }
        SystemState::put('sync:last_heartbeat', $result + ['at' => now()->toIso8601ZuluString()]);
        if ($result['ok']) {
            SystemState::put('sync:last_ok_heartbeat', now()->toIso8601ZuluString());
        }

        return $result;
    }
}
