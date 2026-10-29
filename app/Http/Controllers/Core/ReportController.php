<?php

namespace App\Http\Controllers\Core;

use App\Domain\Core\SalesReport;
use App\Domain\Kitchen\KitchenBoard;
use App\Http\Controllers\Controller;
use App\Models\CountSession;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan Rasentra Core. Penjualan dan promo dibaca dari proyeksi inbox Rasentra Central;
 * waktu saji, opname dan waste dibaca dari data outlet yang tersinkron.
 */
class ReportController extends Controller
{
    public function sales(Request $request): View
    {
        $report = $this->report($request);

        return view('core.reports.sales', [
            'title' => 'Laporan penjualan',
            'r' => $report,
            'totals' => $report->totals(),
            'days' => $report->byDay(),
            'byOutlet' => $report->byOutlet(),
            'byChannel' => $report->byChannel(),
            'byPayment' => $report->byPayment(),
            'byCategory' => $report->byCategory(),
            'top' => $report->topProducts(20),
            'byCompany' => $report->byCompany(),
            'outlets' => Outlet::orderBy('code')->get(),
        ]);
    }

    public function salesCsv(Request $request): StreamedResponse
    {
        $report = $this->report($request);
        $rows = $report->orders()->join('outlets', 'outlets.id', '=', 'o.outlet_id')
            ->orderBy('o.business_date')->orderBy('o.finalized_at')
            ->get(['o.business_date', 'outlets.code', 'o.doc_no', 'o.channel', 'o.gross_amount', 'o.discount_amount', 'o.net_amount', 'o.finalized_at']);
        $name = 'penjualan_'.$report->from()->format('Ymd').'_'.$report->to()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['tanggal_bisnis', 'outlet', 'no_struk', 'kanal', 'bruto', 'diskon', 'bersih', 'waktu_final']);
            foreach ($rows as $r) {
                fputcsv($out, [Carbon::parse($r->business_date)->toDateString(), $r->code, $r->doc_no, $r->channel, $r->gross_amount, $r->discount_amount, $r->net_amount, $r->finalized_at]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function promotions(Request $request): View
    {
        $report = $this->report($request);

        return view('core.reports.promotions', [
            'title' => 'Laporan promo',
            'r' => $report,
            'totals' => $report->totals(),
            'rows' => $report->promotions(),
            'outlets' => Outlet::orderBy('code')->get(),
        ]);
    }

    public function kitchen(Request $request, KitchenBoard $board): View
    {
        $outlets = Outlet::where('has_restaurant', true)->orderBy('code')->get();
        $outletId = (int) ($request->query('outlet') ?: Outlet::where('code', 'JMS')->value('id') ?: $outlets->first()?->id);
        $report = SalesReport::range($request->query('from'), $request->query('to'), $outletId, 1);
        $tz = config('rasentra.display_timezone');

        return view('core.reports.kitchen', [
            'title' => 'Laporan waktu saji',
            'r' => $report,
            'outlets' => $outlets,
            'outletId' => $outletId,
            'data' => $outletId ? $board->analytics($outletId, Carbon::parse($report->from()->toDateString(), $tz)->startOfDay()->utc(), Carbon::parse($report->to()->toDateString(), $tz)->endOfDay()->utc()) : null,
            'stations' => config('rasentra.kitchen.stations'),
        ]);
    }

    public function stock(Request $request): View
    {
        $report = $this->report($request, 30);
        $from = $report->from()->toDateString();
        $to = $report->to()->toDateString();
        $outletId = $request->integer('outlet') ?: null;

        // Dokumen penyesuaian stok yang dikirim Rasentra Lokal (event stock.adjusted). Kuantitas bertanda:
        // positif bila masuk ke stok jual, negatif bila keluar.
        $docs = fn () => DB::table('hq_stock_adjustments as a')->whereBetween('a.business_date', [$from, $to])
            ->when($outletId, fn ($q, $id) => $q->where('a.outlet_id', $id));
        $moves = fn (string $kind) => $docs()->where('a.kind', $kind)
            ->join('hq_stock_adjustment_lines as l', 'l.hq_stock_adjustment_id', '=', 'a.id')
            ->join('products', 'products.id', '=', 'l.product_id')->join('outlets', 'outlets.id', '=', 'a.outlet_id')
            ->groupBy('outlets.code', 'products.code', 'products.name', 'l.uom')
            ->selectRaw("outlets.code as outlet, products.code, products.name, l.uom as uom_code, SUM(CASE WHEN l.to_state = 'available' THEN l.quantity ELSE -l.quantity END) as quantity, COUNT(*) as moves")
            ->orderBy('quantity')->limit(50)->get();

        return view('core.reports.stock', [
            'title' => 'Opname & waste',
            'r' => $report,
            'outletId' => $outletId,
            'outlets' => Outlet::orderBy('code')->get(),
            'sessions' => CountSession::query()->when($outletId, fn ($q, $id) => $q->where('outlet_id', $id))
                ->whereBetween('cutoff_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
                ->withCount('lines')->latest('cutoff_at')->limit(50)->get(),
            'adjustments' => $moves('count'),
            'production' => $moves('production'),
            'waste' => $moves('waste'),
            'documents' => $docs()->join('outlets', 'outlets.id', '=', 'a.outlet_id')->whereIn('a.kind', ['waste', 'release', 'production'])
                ->orderByDesc('a.created_at')->limit(30)->get(['a.id', 'a.doc_no', 'a.kind', 'a.reason', 'a.business_date', 'outlets.code as outlet']),
            'documentLines' => DB::table('hq_stock_adjustment_lines as l')->join('products', 'products.id', '=', 'l.product_id')
                ->whereIn('l.hq_stock_adjustment_id', $docs()->whereIn('a.kind', ['waste', 'release', 'production'])->orderByDesc('a.created_at')->limit(30)->pluck('a.id'))
                ->get(['l.hq_stock_adjustment_id', 'products.name', 'l.quantity', 'l.uom', 'l.from_state', 'l.to_state'])->groupBy('hq_stock_adjustment_id'),
            'nonSale' => DB::table('consumption_events as c')->join('products', 'products.id', '=', 'c.product_id')->join('outlets', 'outlets.id', '=', 'c.outlet_id')
                ->where('c.reason', '!=', 'sale')->whereBetween('c.occurred_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
                ->when($outletId, fn ($q, $id) => $q->where('c.outlet_id', $id))
                ->groupBy('outlets.code', 'c.reason', 'products.name', 'c.uom_code')
                ->selectRaw('outlets.code as outlet, c.reason, products.name, c.uom_code, SUM(c.quantity) as quantity')
                ->orderBy('c.reason')->limit(50)->get(),
            'outletNames' => Outlet::pluck('code', 'id'),
        ]);
    }

    private function report(Request $request, int $days = 7): SalesReport
    {
        return SalesReport::range($request->query('from'), $request->query('to'), $request->integer('outlet') ?: null, $days, $request->integer('company') ?: null);
    }
}
