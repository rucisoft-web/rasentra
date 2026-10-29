@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@include('core.reports._filter', ['extra' => '<a class="btn alt grow" href="'.e(route('core.reports.sales.csv', request()->query())).'">Unduh CSV</a>'])
@php
    $maxDay = max(1, (float) $days->max(fn ($d) => (float) $d->net));
    $maxCat = max(1, (float) $byCategory->max(fn ($c) => (float) $c->net));
@endphp
<div class="grid g4">
  <div class="card kpi"><div class="label">Penjualan bersih</div><div class="value">{{ Fmt::money($totals->net) }}</div><div class="note">{{ $r->from()->translatedFormat('d M') }} – {{ $r->to()->translatedFormat('d M Y') }}@if($totals->returns) · setelah {{ $totals->returns }} retur {{ Fmt::money($totals->returned) }}@endif</div></div>
  <div class="card kpi"><div class="label">Jumlah struk</div><div class="value">{{ number_format($totals->orders, 0, ',', '.') }}</div><div class="note">rata-rata {{ Fmt::money($totals->avg) }} per struk</div></div>
  <div class="card kpi"><div class="label">Penjualan kotor</div><div class="value">{{ Fmt::money($totals->gross) }}</div><div class="note">sebelum potongan promo</div></div>
  <div class="card kpi"><div class="label">Potongan promo</div><div class="value">{{ Fmt::money($totals->discount) }}</div><div class="note">@if((float) $totals->gross > 0){{ number_format((float) $totals->discount / (float) $totals->gross * 100, 1, ',', '.') }}% dari kotor @else - @endif</div></div>
</div>

<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <h2>Per hari</h2>
    <div class="bars">
      @foreach ($days as $d)
        <div class="barrow"><span>{{ \Illuminate\Support\Carbon::parse($d->day)->translatedFormat('D, d M') }}</span><span class="track"><span class="fill" style="display:block;width:{{ round((float) $d->net / $maxDay * 100, 1) }}%"></span></span><span class="v">{{ Fmt::money($d->net) }}</span></div>
      @endforeach
    </div>
  </div>
  <div class="card">
    <h2>Per kategori</h2>
    <div class="bars">
      @forelse ($byCategory as $c)
        <div class="barrow"><span>{{ $c->category }}</span><span class="track"><span class="fill" style="display:block;width:{{ round((float) $c->net / $maxCat * 100, 1) }}%"></span></span><span class="v">{{ Fmt::money($c->net) }}</span></div>
      @empty
        <p class="muted">Belum ada penjualan pada rentang ini.</p>
      @endforelse
    </div>
  </div>
</div>

<div class="grid g4" style="margin-top:14px">
  <div class="card">
    <h2>Per perusahaan</h2>
    <table class="t">
      <thead><tr><th>Perusahaan</th><th class="n">Struk</th><th class="n">Bersih</th></tr></thead>
      <tbody>@forelse ($byCompany as $c)<tr><td>{{ $c->name }}<div class="muted small mono">{{ $c->sap_company_db }}</div></td><td class="n">{{ $c->orders }}</td><td class="n">{{ Fmt::money($c->net) }}</td></tr>@empty<tr><td colspan="3" class="muted">-</td></tr>@endforelse</tbody>
    </table>
  </div>
  <div class="card">
    <h2>Per outlet</h2>
    <table class="t">
      <thead><tr><th>Outlet</th><th class="n">Struk</th><th class="n">Bersih</th></tr></thead>
      <tbody>@forelse ($byOutlet as $o)<tr><td><b>{{ $o->code }}</b> {{ $o->name }}</td><td class="n">{{ $o->orders }}</td><td class="n">{{ Fmt::money($o->net) }}</td></tr>@empty<tr><td colspan="3" class="muted">-</td></tr>@endforelse</tbody>
    </table>
  </div>
  <div class="card">
    <h2>Per kanal</h2>
    <table class="t">
      <thead><tr><th>Kanal</th><th class="n">Struk</th><th class="n">Bersih</th></tr></thead>
      <tbody>@forelse ($byChannel as $c)<tr><td>{{ str_replace('_', ' ', $c->channel) }}</td><td class="n">{{ $c->orders }}</td><td class="n">{{ Fmt::money($c->net) }}</td></tr>@empty<tr><td colspan="3" class="muted">-</td></tr>@endforelse</tbody>
    </table>
  </div>
  <div class="card">
    <h2>Per metode bayar</h2>
    <table class="t">
      <thead><tr><th>Metode</th><th class="n">Transaksi</th><th class="n">Nilai</th></tr></thead>
      <tbody>@forelse ($byPayment as $p)<tr><td>{{ strtoupper($p->method) }}</td><td class="n">{{ $p->payments }}</td><td class="n">{{ Fmt::money($p->amount) }}</td></tr>@empty<tr><td colspan="3" class="muted">-</td></tr>@endforelse</tbody>
    </table>
  </div>
</div>

<h2>20 produk teratas</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Produk</th><th>Jenis baris</th><th class="n">Kuantitas</th><th class="n">Baris</th><th class="n">Diskon</th><th class="n">Bersih</th></tr></thead>
  <tbody>
  @forelse ($top as $p)
    <tr><td class="mono">{{ $p->code }}</td><td>{{ $p->name }}</td><td><span class="b mute">{{ str_replace('_', ' ', $p->line_type) }}</span></td><td class="n">{{ Fmt::qty($p->quantity) }} {{ $p->uom }}</td><td class="n">{{ $p->line_count }}</td><td class="n">{{ Fmt::money($p->discount) }}</td><td class="n">{{ Fmt::money($p->net) }}</td></tr>
  @empty
    <tr><td colspan="7" class="muted">Belum ada penjualan yang tersinkron pada rentang ini.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
<p class="muted small">Sumber: struk final yang sudah diterima Rasentra Central dari tiap Rasentra Lokal. Struk yang masih antre di outlet belum terhitung.</p>
@endsection
