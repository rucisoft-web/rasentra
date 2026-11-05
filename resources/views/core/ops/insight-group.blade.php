@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@include('core.ops._range')
@php $max = max(1, $rows->max('net')); $chan = ['retail' => 'Retail', 'dine_in' => 'Dine-in', 'takeaway' => 'Takeaway', 'pickup' => 'Pickup', 'delivery' => 'Delivery']; @endphp
<div class="grid g4" style="margin-bottom:14px">
  <div class="card kpi"><div class="label">Penjualan bersih grup</div><div class="value">{{ Fmt::money($totals->net) }}</div><div class="note">{{ number_format($totals->orders, 0, ',', '.') }} struk</div></div>
  <div class="card kpi"><div class="label">Rata-rata per struk</div><div class="value">{{ Fmt::money($totals->orders ? $totals->net / $totals->orders : 0) }}</div><div class="note">semua outlet</div></div>
  <div class="card kpi"><div class="label">Poin member</div><div class="value">{{ number_format($totals->points, 0, ',', '.') }}</div><div class="note">diberikan · {{ number_format($redeemed, 0, ',', '.') }} ditukar</div></div>
  <div class="card kpi"><div class="label">Delivery order</div><div class="value">{{ $totals->deliveries }}</div><div class="note">semua mitra & kurir internal</div></div>
</div>
<div class="grid g2" style="margin-bottom:14px">
  <div class="card"><h2>Per perusahaan</h2>
    <dl class="dl">@foreach ($companies as $name => $c)<dt>{{ $name }}</dt><dd><b>{{ Fmt::money($c->net) }}</b> <span class="small muted">· {{ $c->orders }} struk</span></dd>@endforeach</dl></div>
  <div class="card"><h2>Metode pembayaran</h2>
    <dl class="dl">@forelse ($payments as $p)<dt>{{ $p->method }}</dt><dd>{{ Fmt::money($p->amount) }} <span class="small muted">· {{ $p->n }} transaksi</span></dd>@empty<dt>-</dt><dd class="muted">Belum ada data</dd>@endforelse</dl></div>
</div>
<div class="tablewrap"><table class="t">
  <thead><tr><th>Outlet</th><th>Penjualan bersih</th><th class="n">Struk</th><th class="n">Rata-rata</th><th>Kanal</th><th class="n">Porsi member</th><th class="n">Delivery (tepat waktu)</th><th class="n">Absen (terlambat)</th></tr></thead>
  <tbody>@foreach ($rows as $r)
    <tr><td><b>{{ $r->outlet->code }}</b> {{ $r->outlet->name }}<br><span class="small muted">{{ $r->outlet->businessUnit?->name }}</span></td>
      <td style="min-width:180px">{{ Fmt::money($r->net) }}<div style="height:6px;background:#e6f0eb;border-radius:3px;margin-top:4px"><div style="height:6px;width:{{ round($r->net / $max * 100) }}%;background:#1f5a40;border-radius:3px"></div></div></td>
      <td class="n">{{ $r->orders }}</td><td class="n">{{ Fmt::money($r->avg) }}</td>
      <td class="small">{{ $r->channels->map(fn ($v, $k) => ($chan[$k] ?? $k).' '.Fmt::money($v))->implode(' · ') ?: '-' }}</td>
      <td class="n">{{ number_format($r->member_share, 1, ',', '.') }}%</td>
      <td class="n">{{ $r->deliveries }} @if ($r->delivered)<span class="small muted">({{ $r->on_time }}/{{ $r->delivered }})</span>@endif</td>
      <td class="n">{{ $r->attendance }} @if ($r->late)<span class="b warn">{{ $r->late }}</span>@endif</td></tr>
  @endforeach</tbody>
</table></div>
@endsection
