<?php

namespace App\Domain\Lokal;

use App\Domain\Sync\PublicationApplier;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\Shift;
use App\Support\Decimal;
use App\Support\SystemState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kondisi server Rasentra Lokal untuk layar status, POS (indikator offline) dan health check.
 */
final class LokalStatus
{
    /** Heartbeat sukses dalam N menit terakhir = Central dianggap terhubung. */
    public const ONLINE_MINUTES = 3;

    public function __construct(private readonly PublicationApplier $applier) {}

    public function outlet(): ?Outlet
    {
        return Outlet::where('code', config('rasentra.outlet_code'))->first();
    }

    /** @return array{state:string, at:?string, error:?string, skew:?int} state: online, offline, unknown */
    public function central(): array
    {
        $hb = SystemState::get('sync:last_heartbeat');
        $lastOk = SystemState::get('sync:last_ok_heartbeat');
        $state = match (true) {
            $hb === null => 'unknown',
            ($hb['ok'] ?? false) && Carbon::parse($hb['at'])->gt(now()->subMinutes(self::ONLINE_MINUTES)) => 'online',
            default => 'offline',
        };

        return ['state' => $state, 'at' => $hb['at'] ?? null, 'last_ok' => $lastOk, 'error' => $hb['error'] ?? null, 'skew' => $hb['clock_skew_seconds'] ?? null];
    }

    public function outbox(): array
    {
        $rows = OutboxEvent::whereIn('status', ['pending', 'sending', 'failed', 'parked', 'held'])->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');

        return [
            'pending' => (int) (($rows['pending'] ?? 0) + ($rows['sending'] ?? 0)),
            'failed' => (int) ($rows['failed'] ?? 0),
            'parked' => (int) ($rows['parked'] ?? 0),
            'oldest' => OutboxEvent::whereIn('status', ['pending', 'sending', 'failed', 'parked'])->min('occurred_at'),
            'last_acked' => OutboxEvent::where('status', 'acked')->max('updated_at'),
        ];
    }

    /** Ringkasan untuk indikator di Rasentra POS. */
    public function forPos(): array
    {
        $central = $this->central();
        $outbox = $this->outbox();

        return ['central' => $central['state'], 'queued' => $outbox['pending'] + $outbox['failed'] + $outbox['parked'], 'parked' => $outbox['parked']];
    }

    public function today(Outlet $outlet): array
    {
        $date = Outlet::businessDate($outlet->id);
        $orders = DB::table('orders')->where('outlet_id', $outlet->id)->whereDate('business_date', $date)->whereNotNull('finalized_at');

        return ['date' => $date, 'orders' => (clone $orders)->count(), 'net' => Decimal::money(Decimal::sum((clone $orders)->pluck('net_amount')))];
    }

    public function shifts(Outlet $outlet)
    {
        return Shift::with('user:id,name')->where('outlet_id', $outlet->id)->where('status', 'open')->orderBy('opened_at')->get();
    }

    public function storage(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path) ?: null;
        $total = @disk_total_space($path) ?: null;
        $db = DB::connection();
        $size = match ($db->getDriverName()) {
            'sqlite' => is_file($db->getDatabaseName()) ? filesize($db->getDatabaseName()) : null,
            'mysql', 'mariadb' => (int) DB::selectOne('SELECT SUM(data_length + index_length) AS s FROM information_schema.tables WHERE table_schema = ?', [$db->getDatabaseName()])?->s,
            default => null,
        };

        return ['free' => $free, 'total' => $total, 'database' => $size, 'backup' => SystemState::get('job:backup')];
    }

    public function sync(): array
    {
        return ['cursor' => $this->applier->cursor(), 'last_pull' => SystemState::get('sync:last_pull'), 'central_cursor' => SystemState::get('sync:last_heartbeat')['central_cursor'] ?? null];
    }

    /** Untuk GET /lokal/health: tanpa data sensitif. */
    public function health(): array
    {
        $outbox = $this->outbox();
        $central = $this->central();

        return [
            'status' => $outbox['parked'] > 0 ? 'degraded' : 'ok',
            'profile' => config('rasentra.profile'),
            'outlet' => config('rasentra.outlet_code'),
            'version' => config('rasentra.version'),
            'central' => $central['state'],
            'outbox' => ['pending' => $outbox['pending'], 'failed' => $outbox['failed'], 'parked' => $outbox['parked']],
            'cursor' => $this->applier->cursor(),
            'time' => now()->toIso8601ZuluString(),
        ];
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'TB') {
                return number_format($bytes / 1024 ** $i, $i ? 1 : 0, ',', '.').' '.$unit;
            }
        }

        return (string) $bytes;
    }
}
