@php
  $money = fn ($v) => number_format((float) $v, 0, ',', '.');
  $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
  $methods = ['cash' => 'Tunai', 'qris' => 'QRIS', 'edc' => 'EDC', 'edc_manual' => 'EDC manual', 'voucher' => 'Voucher'];
  $disp = ['restock' => 'kembali ke stok', 'quarantine' => 'karantina', 'none' => 'tidak ke stok'];
@endphp
@extends('pos.print.layout')
@section('title', 'Retur '.$return->doc_no)
@section('body')
  <div class="stamp">RETUR PENJUALAN</div>
  <table>
    <tr><td>No retur</td><td class="r b">{{ $return->doc_no }}</td></tr>
    <tr><td>Struk asal</td><td class="r">{{ $return->order->doc_no }}</td></tr>
    <tr><td>Waktu</td><td class="r">{{ $return->created_at->timezone($outlet->timezone)->format('d/m/Y H:i') }}</td></tr>
    <tr><td>Kasir</td><td class="r">{{ $return->user?->name }}</td></tr>
    <tr><td>Disetujui</td><td class="r">{{ $return->approver?->name }}</td></tr>
  </table>
  <div>Alasan: {{ $return->reason }}</div>
  <hr>
  <table>
    @foreach ($return->lines as $l)
      <tr class="item"><td colspan="2">{{ $l->product->name }}</td></tr>
      <tr class="sub"><td>{{ $qty($l->quantity) }} {{ $l->uom_code }} · {{ $disp[$l->disposition] ?? $l->disposition }}</td><td class="r">-{{ $money($l->net_amount) }}</td></tr>
    @endforeach
  </table>
  <hr>
  <table>
    <tr class="b big"><td>DIKEMBALIKAN</td><td class="r">Rp{{ $money($return->net_amount) }}</td></tr>
    <tr><td>Lewat</td><td class="r">{{ $methods[$return->refund_method] ?? $return->refund_method }}@if ($return->refund_ref) ({{ $return->refund_ref }})@endif</td></tr>
  </table>
  <div class="sign"><div>Pelanggan</div><div>Kasir</div></div>
@endsection
