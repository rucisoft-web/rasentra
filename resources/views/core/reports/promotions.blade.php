@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@include('core.reports._filter')
@php
    $funders = ['internal' => 'Internal', 'supplier' => 'Supplier', 'shared' => 'Bersama'];
    $byFunder = $rows->groupBy('funded_by')->map(fn ($g) => $g->sum(fn ($x) => (float) $x->discount));
@endphp
<div class="grid g4">
  <div class="card kpi"><div class="label">Total potongan promo</div><div class="value">{{ Fmt::money($totals->discount) }}</div><div class="note">dari penjualan kotor {{ Fmt::money($totals->gross) }}</div></div>
  @foreach ($funders as $k => $v)
    <div class="card kpi"><div class="label">Ditanggung {{ strtolower($v) }}</div><div class="value">{{ Fmt::money($byFunder[$k] ?? 0) }}</div><div class="note">{{ $rows->where('funded_by', $k)->count() }} versi promo</div></div>
  @endforeach
</div>
<h2>Per versi promo</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Promo</th><th>Versi</th><th>Jenis</th><th>Ditanggung</th><th class="n">Struk</th><th class="n">Baris</th><th class="n">Potongan</th></tr></thead>
  <tbody>
  @forelse ($rows as $p)
    <tr>
      <td>@if($p->code)<b>{{ $p->code }}</b> {{ $p->name }}@else<span class="muted">Promo #{{ $p->promotion_version_id }}</span>@endif</td>
      <td>v{{ $p->version ?? '-' }}</td>
      <td>{{ str_replace('_', ' ', $p->type ?? '-') }}</td>
      <td><span class="b {{ $p->funded_by === 'supplier' ? 'info' : 'mute' }}">{{ $funders[$p->funded_by] ?? $p->funded_by }}</span></td>
      <td class="n">{{ $p->orders }}</td>
      <td class="n">{{ $p->line_count }}</td>
      <td class="n">{{ Fmt::money($p->discount) }}</td>
    </tr>
  @empty
    <tr><td colspan="7" class="muted">Belum ada potongan promo pada rentang ini.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
<p class="muted small">Potongan yang ditanggung supplier menjadi dasar klaim ke supplier. Alokasi per baris tersimpan di setiap struk, jadi retur memotong klaim dengan nilai yang sama.</p>
@endsection
