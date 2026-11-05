<?php

namespace App\Http\Controllers\Core;

use App\Domain\Operations\DeliveryService;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Delivery;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Business-wide insights: satu layar untuk membandingkan semua outlet dan CV. Data berasal
 * dari event yang dikirim setiap Rasentra Lokal ke Central (penjualan, pembayaran, poin
 * member, pengiriman, absensi), jadi angka yang sama dipakai kantor pusat dan outlet.
 */
class InsightController extends Controller
{
    public function group(Request $request): View
    {
        [$from, $to] = $this->range($request);
        $outlets = Outlet::with('businessUnit')->orderBy('code')->get()->keyBy('id');

        $sales = DB::table('hq_orders')->whereBetween('business_date', [$from, $to])
            ->selectRaw("outlet_id, COALESCE(SUM(net_amount),0) as net, SUM(CASE WHEN kind = 'sale' THEN 1 ELSE 0 END) as orders, COALESCE(SUM(discount_amount),0) as discount")
            ->groupBy('outlet_id')->get()->keyBy('outlet_id');
        $channels = DB::table('hq_orders')->whereBetween('business_date', [$from, $to])->where('kind', 'sale')
            ->selectRaw('outlet_id, channel, COALESCE(SUM(net_amount),0) as net')->groupBy('outlet_id', 'channel')->get()->groupBy('outlet_id');
        $payments = DB::table('hq_order_payments as p')->join('hq_orders as o', 'o.id', '=', 'p.hq_order_id')->whereBetween('o.business_date', [$from, $to])
            ->selectRaw('p.method, COALESCE(SUM(p.amount),0) as amount, COUNT(*) as n')->groupBy('p.method')->orderByDesc('amount')->get();
        $member = DB::table('member_point_ledger')->whereBetween('business_date', [$from, $to])->where('type', 'earn')
            ->selectRaw('outlet_id, COALESCE(SUM(order_amount),0) as sales, COUNT(DISTINCT order_id) as orders, COALESCE(SUM(points),0) as points')->groupBy('outlet_id')->get()->keyBy('outlet_id');
        $redeemed = DB::table('member_point_ledger')->whereBetween('business_date', [$from, $to])->where('type', 'redeem')->sum('points');
        $deliveries = Delivery::whereBetween('business_date', [$from, $to])
            ->selectRaw("outlet_id, COUNT(*) as n, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN status = 'delivered' AND promised_at IS NOT NULL AND delivered_at <= promised_at THEN 1 ELSE 0 END) as on_time")
            ->groupBy('outlet_id')->get()->keyBy('outlet_id');
        $attendance = Attendance::whereBetween('work_date', [$from, $to])
            ->selectRaw('outlet_id, COUNT(*) as n, SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late')->groupBy('outlet_id')->get()->keyBy('outlet_id');

        $rows = $outlets->map(function ($o) use ($sales, $channels, $member, $deliveries, $attendance) {
            $s = $sales->get($o->id);
            $net = (float) ($s->net ?? 0);
            $orders = (int) ($s->orders ?? 0);
            $m = $member->get($o->id);
            $d = $deliveries->get($o->id);
            $a = $attendance->get($o->id);

            return (object) [
                'outlet' => $o,
                'net' => $net,
                'orders' => $orders,
                'avg' => $orders ? $net / $orders : 0,
                'discount' => (float) ($s->discount ?? 0),
                'channels' => collect($channels->get($o->id, []))->pluck('net', 'channel'),
                'member_share' => $net > 0 ? (float) ($m->sales ?? 0) / $net * 100 : 0,
                'points' => (float) ($m->points ?? 0),
                'deliveries' => (int) ($d->n ?? 0),
                'delivered' => (int) ($d->delivered ?? 0),
                'on_time' => (int) ($d->on_time ?? 0),
                'attendance' => (int) ($a->n ?? 0),
                'late' => (int) ($a->late ?? 0),
            ];
        })->sortByDesc('net')->values();

        return view('core.ops.insight-group', [
            'title' => 'Ringkasan grup',
            'from' => $from, 'to' => $to,
            'rows' => $rows,
            'companies' => $rows->groupBy(fn ($r) => $r->outlet->businessUnit?->name ?? '-')->map(fn ($g) => (object) ['net' => $g->sum('net'), 'orders' => $g->sum('orders')]),
            'payments' => $payments,
            'redeemed' => abs((float) $redeemed),
            'totals' => (object) ['net' => $rows->sum('net'), 'orders' => $rows->sum('orders'), 'points' => $rows->sum('points'), 'deliveries' => $rows->sum('deliveries')],
        ]);
    }

    public function deliveries(Request $request): View
    {
        [$from, $to] = $this->range($request);

        return view('core.ops.insight-deliveries', [
            'title' => 'Laporan delivery order',
            'from' => $from, 'to' => $to,
            'rows' => Delivery::whereBetween('business_date', [$from, $to])->when($request->query('outlet'), fn ($q, $o) => $q->where('outlet_id', $o))
                ->orderByDesc('business_date')->orderByDesc('created_at')->paginate(50)->withQueryString(),
            'providers' => Delivery::whereBetween('business_date', [$from, $to])->selectRaw('provider, COUNT(*) as n, COALESCE(SUM(fee),0) as fee, COALESCE(SUM(order_amount),0) as amount')->groupBy('provider')->get(),
            'outlets' => Outlet::orderBy('code')->pluck('code', 'id'),
            'status' => DeliveryService::STATUS,
        ]);
    }

    public function attendance(Request $request): View
    {
        [$from, $to] = $this->range($request);

        return view('core.ops.insight-attendance', [
            'title' => 'Laporan kehadiran staf',
            'from' => $from, 'to' => $to,
            'rows' => Attendance::whereBetween('work_date', [$from, $to])->when($request->query('outlet'), fn ($q, $o) => $q->where('outlet_id', $o))
                ->selectRaw('outlet_id, user_id, MAX(user_name) as user_name, COUNT(*) as days, SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_days, COALESCE(SUM(late_minutes),0) as late_minutes, COALESCE(SUM(worked_minutes),0) as worked_minutes')
                ->groupBy('outlet_id', 'user_id')->orderBy('outlet_id')->get(),
            'outlets' => Outlet::orderBy('code')->pluck('code', 'id'),
        ]);
    }

    private function range(Request $request): array
    {
        $to = Carbon::parse($request->query('to', now()->toDateString()))->toDateString();
        $from = Carbon::parse($request->query('from', Carbon::parse($to)->subDays(6)->toDateString()))->toDateString();

        return [$from, $to];
    }
}
