<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Sync\DayReconciler;
use App\Http\Controllers\Controller;
use App\Models\DailyReconciliation;
use App\Models\Outlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Rekonsiliasi harian: ringkasan tutup hari dari Rasentra Lokal dibanding data Rasentra Central. */
class ReconciliationController extends Controller
{
    public const STATUS = [
        'matched' => ['Cocok', 'ok'],
        'waiting' => ['Menunggu struk', 'warn'],
        'mismatch' => ['Selisih', 'err'],
    ];

    public function index(Request $request): View
    {
        $to = Carbon::parse($request->query('to', now(config('rasentra.display_timezone'))->subDay()->toDateString()));
        $from = Carbon::parse($request->query('from', $to->copy()->subDays(6)->toDateString()));
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $rows = DailyReconciliation::whereBetween('business_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])->get()
            ->keyBy(fn ($r) => $r->outlet_id.'|'.$r->business_date->toDateString());
        $outlets = Outlet::with('businessUnit')->where('is_active', true)->orderBy('business_unit_id')->orderBy('code')->get();

        $days = [];
        for ($d = $to->copy(); $d->gte($from); $d->subDay()) {
            $days[] = $d->toDateString();
        }

        return view('core.central.reconciliation', [
            'title' => 'Rekonsiliasi harian',
            'rows' => $rows,
            'outlets' => $outlets,
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'statuses' => self::STATUS,
            'detail' => $request->query('show') ? DailyReconciliation::with('outlet')->find($request->query('show')) : null,
        ]);
    }

    public function recheck(Request $request, DailyReconciliation $reconciliation, DayReconciler $reconciler): RedirectResponse
    {
        $before = $reconciliation->only(['status', 'central_orders', 'central_net']);
        $row = $reconciler->check($reconciliation->outlet_id, $reconciliation->business_date->toDateString());
        Audit::record('reconciliation.rechecked', $row, $request->user()->id, before: $before, after: $row->only(['status', 'central_orders', 'central_net']), outletId: $row->outlet_id);

        return back()->with('ok', 'Diperiksa ulang: '.self::STATUS[$row->status][0].'.');
    }
}
