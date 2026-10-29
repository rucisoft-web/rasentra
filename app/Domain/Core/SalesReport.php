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
    /** Retur tersimpan sebagai dokumen negatif: nilai otomatis bersih, tetapi jumlah struk hanya menghitung penjualan. */
    private const SALES_COUNT = "SUM(CASE WHEN o.kind = 'sale' THEN 1 ELSE 0 END)";

    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
        private readonly ?int $outletId = null,
        private readonly ?int $companyId = null,
    ) {}

    public static function range(?string $from, ?string $to, ?int $outletId = null, int $defaultDays = 7, ?int $companyId = null): self
    {
        $today = Carbon::now(config('rasentra.display_timezone'))->startOfDay();
        $toDate = $to ? Carbon::parse($to) : $today;
        $fromDate = $from ? Carbon::parse($from) : $toDate->copy()->subDays($defaultDays - 1);
        if ($fromDate->greaterThan($toDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return new self($fromDate->startOfDay(), $toDate->startOfDay(), $outletId, $companyId);
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
            ->when($this->outletId, fn ($q, $id) => $q->where('o.outlet_id', $id))
            ->when($this->companyId, fn ($q, $id) => $q->whereIn('o.outlet_id', DB::table('outlets')->where('business_unit_id', $id)->select('id')));
    }

    /** Per perusahaan (CV) di grup, beserta instance SAP-nya. */
    public function byCompany(): Collection
    {
        return $this->orders()->join('outlets', 'outlets.id', '=', 'o.outlet_id')->join('business_units as bu', 'bu.id', '=', 'outlets.business_unit_id')
            ->groupBy('bu.id', 'bu.name', 'bu.sap_company_db')
            ->selectRaw('bu.id, bu.name, bu.sap_company_db, '.self::SALES_COUNT.' as orders, SUM(o.discount_amount) as discount, SUM(o.net_amount) as net')
            ->orderByDesc('net')->get();
    }

    /** @return object{orders:int, gross:string, discount:string, net:string} */
    public function totals(): object
    {
        $row = $this->orders()->selectRaw('COALESCE('.self::SALES_COUNT.',0) as orders, COALESCE(SUM(o.gross_amount),0) as gross, COALESCE(SUM(o.discount_amount),0) as discount, COALESCE(SUM(o.net_amount),0) as net,'
            ."SUM(CASE WHEN o.kind = 'return' THEN 1 ELSE 0 END) as returns, COALESCE(SUM(CASE WHEN o.kind = 'return' THEN -o.net_amount ELSE 0 END),0) as returned")->first();
        $row->orders = (int) $row->orders;
        $row->returns = (int) $row->returns;
        $row->avg = $row->orders > 0 ? (string) Decimal::of((string) $row->net)->dividedBy($row->orders, 2, Decimal::rounding()) : '0';

        return $row;
    }

    public function byOutlet(): Collection
    {
        return $this->orders()->join('outlets', 'outlets.id', '=', 'o.outlet_id')
            ->groupBy('o.outlet_id', 'outlets.code', 'outlets.name')
            ->selectRaw('o.outlet_id, outlets.code, outlets.name, '.self::SALES_COUNT.' as orders, SUM(o.discount_amount) as discount, SUM(o.net_amount) as net')
            ->orderByDesc('net')->get();
    }

    public function byDay(): Collection
    {
        $rows = $this->orders()->groupBy('o.business_date')
            ->selectRaw('o.business_date as day, '.self::SALES_COUNT.' as orders, SUM(o.net_amount) as net')
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
            ->selectRaw('o.channel, '.self::SALES_COUNT.' as orders, SUM(o.net_amount) as net')->orderByDesc('net')->get();
    }

    public function byPayment(): Collection
    {
        return $this->orders()->join('hq_order_payments as p', 'p.hq_order_id', '=', 'o.id')
            ->groupBy('p.method')->selectRaw('p.method, SUM(CASE WHEN p.amount > 0 THEN 1 ELSE 0 END) as payments, SUM(p.amount) as amount')->orderByDesc('amount')->get();
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
