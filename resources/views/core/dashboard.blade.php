@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@php
    $maxDay = max(1, (float) $days->max(fn ($d) => (float) $d->net));
    $synced = $outlets->where('health', 'ok')->count();
    $sapIssues = ($sap['needs_review'] ?? 0) + ($sap['failed'] ?? 0) + ($sap['partially_posted'] ?? 0);
@endphp
<div class="grid g4">
  <div class="card kpi"><div class="label">Penjualan bersih hari ini</div><div class="value">{{ Fmt::money($totals->net) }}</div><div class="note">{{ $totals->orders }} struk · rata-rata {{ Fmt::money($totals->avg) }}</div></div>
  <div class="card kpi"><div class="label">Potongan promo hari ini</div><div class="value">{{ Fmt::money($totals->discount) }}</div><div class="note">dari penjualan kotor {{ Fmt::money($totals->gross) }}</div></div>
  <div class="card kpi"><div class="label">Outlet tersinkron (15 menit)</div><div class="value">{{ $synced }} / {{ $outlets->count() }}</div><div class="note">{{ $outlets->where('health', 'none')->count() }} outlet belum mengirim data</div></div>
  <div class="card kpi"><div class="label">Perlu tindakan</div><div class="value">{{ $sapIssues + $exceptions }}</div><div class="note">{{ $sapIssues }} dokumen SAP, {{ $exceptions }} masalah integrasi</div></div>
</div>

<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <h2>Penjualan 7 hari</h2>
    <div class="bars">
      @foreach ($days as $d)
        <div class="barrow"><span>{{ \Illuminate\Support\Carbon::parse($d->day)->translatedFormat('D, d M') }}</span><span class="track"><span class="fill" style="display:block;width:{{ round((float) $d->net / $maxDay * 100, 1) }}%"></span></span><span class="v">{{ Fmt::money($d->net) }}</span></div>
      @endforeach
    </div>
    <p class="muted small">Total 7 hari {{ Fmt::money($weekTotals->net) }} dari {{ $weekTotals->orders }} struk. <a href="{{ route('core.reports.sales') }}">Laporan lengkap</a></p>
  </div>
  <div class="card">
    <h2>Pekerjaan tertunda</h2>
    <dl class="dl">
      <dt>Promo menunggu persetujuan</dt><dd>{{ $promoDrafts->count() }} @if($promoDrafts->count())<span class="muted small">· {{ $promoDrafts->map(fn ($v) => $v->promotion->code)->implode(', ') }}</span>@endif</dd>
      <dt>Versi harga draft</dt><dd>{{ $priceDrafts }}</dd>
      <dt>Promo belum diterima outlet</dt><dd>{{ $pendingReceipts }} target</dd>
      <dt>SAP perlu review</dt><dd>{{ $sap['needs_review'] ?? 0 }} @if(($sap['needs_review'] ?? 0) > 0)<span class="b warn">cek</span>@endif</dd>
      <dt>SAP gagal / parsial</dt><dd>{{ ($sap['failed'] ?? 0) + ($sap['partially_posted'] ?? 0) }}</dd>
      <dt>SAP terposting</dt><dd>{{ $sap['posted'] ?? 0 }}</dd>
    </dl>
    @if ($channels->count())
      <h2>Kanal hari ini</h2>
      <div class="actions">@foreach ($channels as $c)<span class="b">{{ str_replace('_', ' ', $c->channel) }} · {{ Fmt::money($c->net) }}</span>@endforeach</div>
    @endif
  </div>
</div>

<h2>Outlet</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Outlet</th><th>Wilayah</th><th class="n">Struk hari ini</th><th class="n">Penjualan bersih</th><th>Sinkron terakhir</th><th class="n">Antre kirim</th><th>Status</th></tr></thead>
  <tbody>
  @foreach ($outlets as $r)
    <tr>
      <td><b>{{ $r->outlet->code }}</b> {{ $r->outlet->name }}</td>
      <td>{{ $r->outlet->region->name }}</td>
      <td class="n">{{ $r->orders }}</td>
      <td class="n">{{ Fmt::money($r->net) }}</td>
      <td>{{ $r->last_sync ? Fmt::dt($r->last_sync) : '-' }}</td>
      <td class="n">{{ $r->pending }}@if($r->parked) <span class="b err">{{ $r->parked }} parked</span>@endif</td>
      <td>
        @switch($r->health)
          @case('ok')<span class="b ok">Tersambung</span>@break
          @case('late')<span class="b warn">Terlambat</span>@break
          @default<span class="b mute">Belum ada data</span>
        @endswitch
      </td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>
@endsection
