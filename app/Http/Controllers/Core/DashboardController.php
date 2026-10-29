<?php

namespace App\Http\Controllers\Core;

use App\Domain\Core\SalesReport;
use App\Http\Controllers\Controller;
use App\Models\IntegrationException;
use App\Models\Outlet;
use App\Models\PriceVersion;
use App\Models\PromotionTarget;
use App\Models\PromotionVersion;
use App\Models\SapDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Ringkasan semua outlet: penjualan hari ini, kesehatan sinkronisasi, SAP dan pekerjaan tertunda. */
class DashboardController extends Controller
{
    public function index(): View
    {
        $today = Carbon::now(config('rasentra.display_timezone'))->toDateString();
        $day = SalesReport::range($today, $today);
        $week = SalesReport::range(null, $today, null, 7);

        $lastInbox = DB::table('inbox_events')->groupBy('outlet_id')->selectRaw('outlet_id, MAX(created_at) as last_at')->pluck('last_at', 'outlet_id');
        $outbox = DB::table('outbox_events')->whereIn('status', ['pending', 'sending', 'failed', 'parked'])
            ->groupBy('outlet_id', 'status')->selectRaw('outlet_id, status, COUNT(*) as n')->get()->groupBy('outlet_id');
        $sales = $day->byOutlet()->keyBy('outlet_id');

        $outlets = Outlet::with('region')->where('is_active', true)->orderBy('code')->get()->map(function (Outlet $o) use ($lastInbox, $outbox, $sales) {
            $last = isset($lastInbox[$o->id]) ? Carbon::parse($lastInbox[$o->id]) : null;
            $queue = collect($outbox->get($o->id, []))->pluck('n', 'status');

            return (object) [
                'outlet' => $o,
                'net' => $sales[$o->id]->net ?? '0',
                'orders' => $sales[$o->id]->orders ?? 0,
                'last_sync' => $last,
                'pending' => (int) (($queue['pending'] ?? 0) + ($queue['sending'] ?? 0) + ($queue['failed'] ?? 0)),
                'parked' => (int) ($queue['parked'] ?? 0),
                'health' => match (true) {
                    $last === null => 'none',
                    $last->greaterThan(now()->subMinutes(15)) => 'ok',
                    default => 'late',
                },
            ];
        });

        return view('core.dashboard', [
            'title' => 'Dashboard',
            'today' => $today,
            'totals' => $day->totals(),
            'weekTotals' => $week->totals(),
            'days' => $week->byDay(),
            'channels' => $day->byChannel(),
            'outlets' => $outlets,
            'sap' => SapDocument::groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status'),
            'exceptions' => IntegrationException::where('status', 'open')->count(),
            'promoDrafts' => PromotionVersion::with('promotion')->whereIn('status', ['draft', 'simulated'])->latest()->limit(5)->get(),
            'priceDrafts' => PriceVersion::where('status', 'draft')->count(),
            'pendingReceipts' => PromotionTarget::where('ack_status', 'pending')->count(),
        ]);
    }
}
