@php
  $money = fn ($v) => number_format((float) $v, 0, ',', '.');
  $methods = ['cash' => 'Tunai', 'qris' => 'QRIS', 'edc' => 'EDC', 'edc_manual' => 'EDC manual', 'voucher' => 'Voucher'];
  $channels = ['retail' => 'Retail', 'dine_in' => 'Dine-in', 'takeaway' => 'Takeaway', 'pickup' => 'Pickup', 'delivery' => 'Delivery'];
  $moves = ['cash_in' => 'Kas masuk', 'cash_out' => 'Kas keluar', 'no_sale_open' => 'Buka laci'];
  $tz = $outlet->timezone;
@endphp
@extends('pos.print.layout')
@section('title', 'Laporan shift '.$shift->user?->name)
@section('body')
  <div class="stamp">{{ $shift->status === 'open' ? 'LAPORAN SHIFT (SEMENTARA)' : 'LAPORAN TUTUP SHIFT' }}</div>
  <table>
    <tr><td>Kasir</td><td class="r b">{{ $shift->user?->name }}</td></tr>
    <tr><td>Tanggal bisnis</td><td class="r">{{ $shift->business_date->format('d/m/Y') }}</td></tr>
    <tr><td>Buka</td><td class="r">{{ $shift->opened_at->timezone($tz)->format('d/m H:i') }}</td></tr>
    <tr><td>Tutup</td><td class="r">{{ $shift->closed_at?->timezone($tz)->format('d/m H:i') ?? '-' }}</td></tr>
  </table>
  <hr>
  <div class="b">Penjualan</div>
  <table>
    <tr><td>Jumlah struk</td><td class="r">{{ $report['orders'] }}</td></tr>
    @foreach ($report['channels'] as $ch => $n)<tr class="sub"><td>&nbsp; {{ $channels[$ch] ?? $ch }}</td><td class="r">{{ $n }}</td></tr>@endforeach
    <tr><td>Penjualan kotor</td><td class="r">{{ $money($report['gross']) }}</td></tr>
    <tr><td>Diskon promo</td><td class="r">-{{ $money($report['discount']) }}</td></tr>
    <tr><td>Penjualan bersih</td><td class="r">{{ $money($report['net']) }}</td></tr>
    <tr><td>Retur ({{ $report['returns']->count() }})</td><td class="r">-{{ $money($report['returns']->sum('net_amount')) }}</td></tr>
    <tr class="b"><td>Bersih setelah retur</td><td class="r">{{ $money($report['net_after_returns']) }}</td></tr>
  </table>
  <hr>
  <div class="b">Pembayaran diterima</div>
  <table>
    @forelse ($report['payments'] as $m => $v)<tr><td>{{ $methods[$m] ?? $m }}</td><td class="r">{{ $money($v) }}</td></tr>@empty<tr><td class="muted">Belum ada</td></tr>@endforelse
    @foreach ($report['refunds'] as $m => $v)<tr><td>Refund {{ $methods[$m] ?? $m }}</td><td class="r">-{{ $money($v) }}</td></tr>@endforeach
  </table>
  @if ($report['returns']->isNotEmpty())
    <table>@foreach ($report['returns'] as $r)<tr class="sub"><td>&nbsp; {{ $r->doc_no }}</td><td class="r">-{{ $money($r->net_amount) }}</td></tr>@endforeach</table>
  @endif
  <hr>
  <div class="b">Laci kas</div>
  <table>
    <tr><td>Modal awal</td><td class="r">{{ $money($shift->opening_cash) }}</td></tr>
    @foreach ($report['movements'] as $mv)
      <tr class="sub"><td>&nbsp; {{ $moves[$mv->type] ?? $mv->type }} · {{ \Illuminate\Support\Str::limit($mv->reason, 18) }}</td><td class="r">{{ $mv->type === 'cash_out' ? '-' : '' }}{{ $money($mv->amount) }}</td></tr>
    @endforeach
    <tr><td>Tunai seharusnya</td><td class="r">{{ $money($report['expected']['cash'] ?? 0) }}</td></tr>
    @if ($shift->status !== 'open')
      <tr><td>Tunai dihitung</td><td class="r">{{ $money($report['counted']['cash'] ?? 0) }}</td></tr>
      <tr class="b"><td>Selisih</td><td class="r">{{ (float) $report['difference'] > 0 ? '+' : '' }}{{ $money($report['difference']) }}</td></tr>
      @if ($approver)<tr class="sub"><td>Disetujui</td><td class="r">{{ $approver }}</td></tr>@endif
    @endif
  </table>
  <hr>
  <div class="c muted">Dicetak {{ now()->timezone($tz)->format('d/m/Y H:i') }}</div>
  <div class="sign"><div>Kasir</div><div>Kepala outlet</div></div>
@endsection
