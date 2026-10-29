<?php

namespace App\Domain\Core;

use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kueri laporan penjualan Rasentra Core di atas proyeksi hq_*. Rentang memakai tanggal
 * bisnis outlet (inklusif). Angka uang dijumlahkan oleh database dalam DECIMAL.
 */
final class SalesReport
{
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
        private readonly ?int $outletId = null,
    ) {}

    public static function range(?string $from, ?string $to, ?int $outletId = null, int $defaultDays = 7): self
    {
        $today = Carbon::now(config('rasentra.display_timezone'))->startOfDay();
        $toDate = $to ? Carbon::parse($to) : $today;
        $fromDate = $from ? Carbon::parse($from) : $toDate->copy()->subDays($defaultDays - 1);
        if ($fromDate->greaterThan($toDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return new self($fromDate->startOfDay(), $toDate->startOfDay(), $outletId);
    }

    public function from(): Carbon
    {
        return $this->from;
    }

    public function to(): Carbon
    {
        return $this->to;
    }

    public function orders(): Builder
    {
        return DB::table('hq_orders as o')
            ->whereBetween('o.business_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->when($this->outletId, fn ($q, $id) => $q->where('o.outlet_id', $id));
    }

    /** @return object{orders:int, gross:string, discount:string, net:string} */
    public function totals(): object
    {
        $row = $this->orders()->selectRaw('COUNT(*) as orders, COALESCE(SUM(o.gross_amount),0) as gross, COALESCE(SUM(o.discount_amount),0) as discount, COALESCE(SUM(o.net_amount),0) as net')->first();
        $row->avg = $row->orders > 0 ? (string) Decimal::of((string) $row->net)->dividedBy($row->orders, 2, Decimal::rounding()) : '0';

        return $row;
    }

    public function byOutlet(): Collection
    {
        return $this->orders()->join('outlets', 'outlets.id', '=', 'o.outlet_id')
            ->groupBy('o.outlet_id', 'outlets.code', 'outlets.name')
            ->selectRaw('o.outlet_id, outlets.code, outlets.name, COUNT(*) as orders, SUM(o.discount_amount) as discount, SUM(o.net_amount) as net')
            ->orderByDesc('net')->get();
    }

    public function byDay(): Collection
    {
        $rows = $this->orders()->groupBy('o.business_date')
            ->selectRaw('o.business_date as day, COUNT(*) as orders, SUM(o.net_amount) as net')
            ->get()->keyBy(fn ($r) => Carbon::parse($r->day)->toDateString());

        // Hari tanpa transaksi tetap tampil sebagai nol agar tren terbaca.
        $days = collect();
        for ($d = $this->from->copy(); $d->lessThanOrEqualTo($this->to); $d->addDay()) {
            $key = $d->toDateString();
            $days->push((object) ['day' => $key, 'orders' => (int) ($rows[$key]->orders ?? 0), 'net' => (string) ($rows[$key]->net ?? '0')]);
        }

        return $days;
    }

    public function byChannel(): Collection
    {
        return $this->orders()->groupBy('o.channel')
            ->selectRaw('o.channel, COUNT(*) as orders, SUM(o.net_amount) as net')->orderByDesc('net')->get();
    }

    public function byPayment(): Collection
    {
        return $this->orders()->join('hq_order_payments as p', 'p.hq_order_id', '=', 'o.id')
            ->groupBy('p.method')->selectRaw('p.method, COUNT(*) as payments, SUM(p.amount) as amount')->orderByDesc('amount')->get();
    }

    public function byCategory(): Collection
    {
        return $this->orders()->join('hq_order_lines as l', 'l.hq_order_id', '=', 'o.id')
            ->join('products', 'products.id', '=', 'l.product_id')
            ->groupBy('products.category')
            ->selectRaw("COALESCE(products.category, 'Lainnya') as category, SUM(l.net_amount) as net")
            ->orderByDesc('net')->get();
    }

    public function topProducts(int $limit = 15): Collection
    {
        return $this->orders()->join('hq_order_lines as l', 'l.hq_order_id', '=', 'o.id')
            ->join('products', 'products.id', '=', 'l.product_id')
            ->groupBy('l.product_id', 'products.code', 'products.name', 'l.uom', 'l.line_type')
            ->selectRaw('l.product_id, products.code, products.name, l.uom, l.line_type, SUM(l.quantity) as quantity, COUNT(*) as lines, SUM(l.discount_amount) as discount, SUM(l.net_amount) as net')
            ->orderByDesc('net')->limit($limit)->get();
    }

    public function promotions(): Collection
    {
        return $this->orders()->join('hq_order_discounts as d', 'd.hq_order_id', '=', 'o.id')
            ->leftJoin('promotion_versions as v', 'v.id', '=', 'd.promotion_version_id')
            ->leftJoin('promotions as p', 'p.id', '=', 'v.promotion_id')
            ->groupBy('d.promotion_version_id', 'p.code', 'p.name', 'v.version', 'v.type', 'd.funded_by')
            ->selectRaw('d.promotion_version_id, p.code, p.name, v.version, v.type, d.funded_by, COUNT(DISTINCT o.id) as orders, COUNT(*) as lines, SUM(d.amount) as discount')
            ->orderByDesc('discount')->get();
    }
}
