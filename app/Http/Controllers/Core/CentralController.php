<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Integration\Sap\SapClientFactory;
use App\Domain\Integration\Sap\SapHealth;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\DailyReconciliation;
use App\Models\Device;
use App\Models\InboxEvent;
use App\Models\Outlet;
use App\Models\OutletSyncState;
use App\Models\PublicationVersion;
use App\Support\SystemState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Halaman kesehatan Rasentra Central: API sinkronisasi, arus inbox, job terjadwal, koneksi
 * SAP B1 per instance, dan kondisi tiap Rasentra Lokal dari heartbeat-nya.
 */
class CentralController extends Controller
{
    /** Heartbeat lebih tua dari ini dianggap terlambat / terputus (menit). */
    public const LATE = 3;

    public const LOST = 15;

    /** Selisih jam perangkat yang perlu ditindak (detik). */
    public const SKEW = 120;

    public function index(): View
    {
        $cursor = (int) PublicationVersion::max('id');
        $states = OutletSyncState::with('device')->get()->keyBy('outlet_id');
        $minApplied = (int) ($states->min('applied_cursor') ?? 0);
        $pending = PublicationVersion::where('id', '>', $minApplied)->orderBy('id')->get(['id', 'entity_type', 'outlet_ids']);

        $outlets = Outlet::with('businessUnit')->where('is_active', true)->orderBy('business_unit_id')->orderBy('code')->get()
            ->map(function (Outlet $o) use ($states, $pending) {
                $state = $states[$o->id] ?? null;
                $lag = $state ? $pending->filter(fn ($p) => $p->id > $state->applied_cursor && ($p->outlet_ids === null || in_array($o->id, $p->outlet_ids, true))) : collect();

                return (object) ['outlet' => $o, 'state' => $state, 'lag' => $lag->count(), 'lagTypes' => $lag->countBy('entity_type')->all(), 'health' => self::health($state, $lag->count())];
            });

        $companies = BusinessUnit::whereNotNull('sap_company_db')->orderBy('id')->get()->map(fn (BusinessUnit $c) => (object) [
            'company' => $c,
            'configured' => ! empty(SapClientFactory::config($c->sap_company_db)['url']),
            'last' => SapHealth::last($c->sap_company_db),
        ]);

        return view('core.central.index', [
            'title' => 'Rasentra Central',
            'profile' => config('rasentra.profile'),
            'cursor' => $cursor,
            'outlets' => $outlets,
            'companies' => $companies,
            'inbox' => [
                'hour' => InboxEvent::where('created_at', '>=', now()->subHour())->count(),
                'day' => InboxEvent::where('created_at', '>=', now()->subDay())->count(),
                'waiting' => InboxEvent::where('event_type', 'order.finalized')->where('status', 'accepted')->count(),
                'rejected' => InboxEvent::where('status', 'rejected')->where('created_at', '>=', now()->subDay())->count(),
                'last' => InboxEvent::max('created_at'),
            ],
            'jobs' => collect(['post-sap' => 'Posting SAP (tiap menit)', 'sap-check' => 'Cek koneksi SAP (15 menit)', 'reconcile' => 'Rekonsiliasi ulang (tiap jam)'])
                ->map(fn ($label, $key) => (object) ['label' => $label, 'last' => SystemState::get("job:{$key}")]),
            'servers' => Device::where('app', 'outlet_server')->where('is_active', true)->count(),
            'reconciliation' => DailyReconciliation::where('business_date', '>=', now()->subDays(7)->toDateString())->get()->countBy('status'),
        ]);
    }

    public function sapCheck(Request $request, SapHealth $health): RedirectResponse
    {
        $results = $health->checkAll();
        SystemState::ran('sap-check', ['ok' => collect($results)->where('ok', true)->count(), 'failed' => collect($results)->where('ok', false)->count()]);
        Audit::record('sap.connection_checked', 'sap:instances', $request->user()->id, after: $results);
        $failed = collect($results)->reject(fn ($r) => $r['ok'])->keys();

        return back()->with($failed->isEmpty() ? 'ok' : 'error', $failed->isEmpty()
            ? 'Semua instance SAP dapat dihubungi.'
            : 'Tidak dapat terhubung ke: '.$failed->implode(', ').'.');
    }

    /** ok, warn, err, none: dipakai juga oleh halaman sinkronisasi dan dashboard. */
    public static function health(?OutletSyncState $state, int $lag = 0): string
    {
        if ($state?->last_heartbeat_at === null) {
            return 'none';
        }
        $age = $state->last_heartbeat_at->diffInMinutes(now());
        if ($age >= self::LOST || $state->outbox_parked > 0 || abs((int) $state->clock_skew_seconds) > self::SKEW) {
            return 'err';
        }
        if ($age >= self::LATE || $lag > 0 || $state->outbox_failed > 0 || $state->last_error) {
            return 'warn';
        }

        return 'ok';
    }

    public static function ago(mixed $time): string
    {
        return $time ? Carbon::parse($time)->diffForHumans() : '-';
    }
}
