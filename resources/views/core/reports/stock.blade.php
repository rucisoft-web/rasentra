@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@include('core.reports._filter', ['selected' => $outletId])
@php
    $status = ['planned' => ['Direncanakan', 'mute'], 'frozen' => ['Dibekukan', 'info'], 'counting' => ['Menghitung', 'info'], 'recount' => ['Hitung ulang', 'warn'], 'review' => ['Review', 'warn'], 'approved' => ['Disetujui', 'ok'], 'posted_sap' => ['Terposting SAP', 'ok']];
    $reasons = ['complimentary' => 'Komplimen', 'staff_meal' => 'Makan staf', 'tasting' => 'Tasting', 'waste' => 'Waste', 'remake' => 'Masak ulang'];
@endphp
<h2>Sesi stok opname</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Outlet</th><th>Cut-off</th><th>Jenis</th><th>Blind</th><th class="n">Baris hitung</th><th>Status</th></tr></thead>
  <tbody>
  @forelse ($sessions as $s)
    <tr>
      <td><b>{{ $outletNames[$s->outlet_id] ?? '-' }}</b></td>
      <td>{{ Fmt::dt($s->cutoff_at) }}</td>
      <td>{{ ['full' => 'Penuh', 'cycle' => 'Siklus', 'special' => 'Khusus'][$s->type] ?? $s->type }}</td>
      <td>{{ $s->blind ? 'Ya' : 'Tidak' }}</td>
      <td class="n">{{ $s->lines_count }}</td>
      <td><span class="b {{ $status[$s->status][1] ?? 'mute' }}">{{ $status[$s->status][0] ?? $s->status }}</span></td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Tidak ada sesi opname pada rentang ini.</td></tr>
  @endforelse
  </tbody>
</table>
</div>

<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <h2>Penyesuaian hasil opname</h2>
    <table class="t">
      <thead><tr><th>Outlet</th><th>Produk</th><th class="n">Selisih</th></tr></thead>
      <tbody>@forelse ($adjustments as $m)<tr><td>{{ $m->outlet }}</td><td>{{ $m->name }}</td><td class="n">@if((float) $m->quantity < 0)<span class="b err">{{ Fmt::qty($m->quantity) }}</span>@else{{ Fmt::qty($m->quantity) }}@endif {{ $m->uom_code }}</td></tr>@empty<tr><td colspan="3" class="muted">Belum ada penyesuaian.</td></tr>@endforelse</tbody>
    </table>
  </div>
  <div class="card">
    <h2>Waste stok</h2>
    <table class="t">
      <thead><tr><th>Outlet</th><th>Produk</th><th class="n">Kuantitas</th></tr></thead>
      <tbody>@forelse ($waste as $m)<tr><td>{{ $m->outlet }}</td><td>{{ $m->name }}</td><td class="n">{{ Fmt::qty(abs((float) $m->quantity)) }} {{ $m->uom_code }}</td></tr>@empty<tr><td colspan="3" class="muted">Belum ada waste tercatat.</td></tr>@endforelse</tbody>
    </table>
  </div>
</div>

<h2>Pemakaian bahan di luar penjualan</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Outlet</th><th>Alasan</th><th>Bahan</th><th class="n">Kuantitas</th></tr></thead>
  <tbody>
  @forelse ($nonSale as $c)
    <tr><td>{{ $c->outlet }}</td><td><span class="b warn">{{ $reasons[$c->reason] ?? $c->reason }}</span></td><td>{{ $c->name }}</td><td class="n">{{ Fmt::qty($c->quantity) }} {{ $c->uom_code }}</td></tr>
  @empty
    <tr><td colspan="4" class="muted">Tidak ada komplimen, makan staf, tasting, waste atau masak ulang pada rentang ini.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection
