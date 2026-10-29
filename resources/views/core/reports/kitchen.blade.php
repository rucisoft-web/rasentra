@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@include('core.reports._filter', ['required' => true, 'selected' => $outletId])
@if (! $data || $data['overall']['count'] === 0)
  <div class="card"><p class="muted">Belum ada item dapur yang selesai pada rentang ini.</p></div>
@else
@php $o = $data['overall']; @endphp
<div class="grid g4">
  <div class="card kpi"><div class="label">Item selesai</div><div class="value">{{ $o['count'] }}</div><div class="note">dari tiket dapur yang tersinkron</div></div>
  <div class="card kpi"><div class="label">Rata-rata total</div><div class="value">{{ Fmt::seconds($o['avg_seconds']) }}</div><div class="note">tunggu {{ Fmt::seconds($o['avg_wait_seconds']) }} · masak {{ Fmt::seconds($o['avg_cook_seconds']) }}</div></div>
  <div class="card kpi"><div class="label">Median (P50)</div><div class="value">{{ Fmt::seconds($o['p50_seconds']) }}</div><div class="note">separuh item lebih cepat dari ini</div></div>
  <div class="card kpi"><div class="label">P90</div><div class="value">{{ Fmt::seconds($o['p90_seconds']) }}</div><div class="note">9 dari 10 item lebih cepat dari ini</div></div>
</div>

<h2>Per station</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Station</th><th class="n">Item</th><th class="n">Rata-rata</th><th class="n">P90</th><th class="n">Tunggu</th><th class="n">Masak</th><th class="n">Target</th><th class="n">Terlambat</th></tr></thead>
  <tbody>
  @foreach ($data['by_station'] as $s)
    <tr>
      <td>{{ $stations[$s['station']]['name'] ?? $s['station'] }}</td>
      <td class="n">{{ $s['count'] }}</td>
      <td class="n">{{ Fmt::seconds($s['avg_seconds']) }}</td>
      <td class="n">{{ Fmt::seconds($s['p90_seconds']) }}</td>
      <td class="n">{{ Fmt::seconds($s['avg_wait_seconds']) }}</td>
      <td class="n">{{ Fmt::seconds($s['avg_cook_seconds']) }}</td>
      <td class="n">{{ $s['target_seconds'] ? Fmt::seconds($s['target_seconds']) : '-' }}</td>
      <td class="n">@if($s['late'])<span class="b warn">{{ $s['late'] }}</span>@else 0 @endif</td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>

<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <h2>Menu paling lama</h2>
    <table class="t">
      <thead><tr><th>Menu</th><th class="n">Item</th><th class="n">Rata-rata</th><th class="n">P90</th></tr></thead>
      <tbody>@foreach ($data['by_product']->take(15) as $p)<tr><td>{{ $p['product'] }}</td><td class="n">{{ $p['count'] }}</td><td class="n">{{ Fmt::seconds($p['avg_seconds']) }}</td><td class="n">{{ Fmt::seconds($p['p90_seconds']) }}</td></tr>@endforeach</tbody>
    </table>
  </div>
  <div class="card">
    <h2>Per jam pesan</h2>
    @php
        $maxH = max(1, $data['by_hour']->max('avg_seconds'));
        $offset = (int) \Illuminate\Support\Carbon::now(config('rasentra.display_timezone'))->utcOffset() / 60;
        $hours = $data['by_hour']->map(fn ($h) => ['local' => ($h['hour'] + $offset + 24) % 24] + $h)->sortBy('local');
    @endphp
    <div class="bars">
      @foreach ($hours as $h)
        <div class="barrow"><span>{{ str_pad($h['local'], 2, '0', STR_PAD_LEFT) }}:00 · {{ $h['count'] }} item</span><span class="track"><span class="fill" style="display:block;width:{{ round($h['avg_seconds'] / $maxH * 100, 1) }}%"></span></span><span class="v">{{ Fmt::seconds($h['avg_seconds']) }}</span></div>
      @endforeach
    </div>
  </div>
</div>
@endif
@endsection
