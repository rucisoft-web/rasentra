@php
  $money = fn ($v) => number_format((float) $v, 0, ',', '.');
  $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
  $channels = ['retail' => 'Retail', 'dine_in' => 'Dine-in', 'takeaway' => 'Takeaway', 'pickup' => 'Pickup', 'delivery' => 'Delivery'];
  $methods = ['cash' => 'Tunai', 'qris' => 'QRIS', 'edc' => 'EDC', 'edc_manual' => 'EDC manual', 'voucher' => 'Voucher'];
  $tz = $outlet->timezone;
@endphp
@extends('pos.print.layout')
@section('title', 'Struk '.$order->doc_no)
@section('body')
  @if ($copy > 0)<div class="stamp">SALINAN ke-{{ $copy }}</div>@endif
  <table>
    <tr><td>No</td><td class="r b">{{ $order->doc_no }}</td></tr>
    <tr><td>Waktu</td><td class="r">{{ $order->finalized_at->timezone($tz)->format('d/m/Y H:i') }}</td></tr>
    <tr><td>Kasir</td><td class="r">{{ $order->user?->name }}</td></tr>
    <tr><td>Kanal</td><td class="r">{{ $channels[$order->channel] ?? $order->channel }}</td></tr>
  </table>
  @if ($order->customer_name)
    <hr>
    <div>Pemesan: <b>{{ $order->customer_name }}</b> · {{ $order->customer_phone }}</div>
    @if ($order->delivery_address)<div>Antar ke: {{ $order->delivery_address }}</div>@endif
    @if ($order->promised_at)<div>{{ $order->channel === 'delivery' ? 'Dikirim' : 'Diambil' }}: {{ $order->promised_at->timezone($tz)->format('d/m H:i') }}</div>@endif
    @if ($order->fulfilment_note)<div>Catatan: {{ $order->fulfilment_note }}</div>@endif
  @endif
  <hr>
  <table>
    @foreach ($order->lines as $l)
      <tr class="item"><td colspan="2">{{ $l->product->name }}</td></tr>
      <tr class="sub">
        <td>{{ $l->pack ? $l->pack->pack_code.' ' : '' }}{{ $qty($l->quantity) }} {{ $l->uom_code }} x {{ $money($l->unit_price) }}</td>
        <td class="r">{{ $money($l->gross_amount) }}</td>
      </tr>
      @if ((float) $l->discount_amount > 0)
        <tr class="sub"><td>&nbsp; Promo</td><td class="r">-{{ $money($l->discount_amount) }}</td></tr>
      @endif
    @endforeach
  </table>
  <hr>
  <table>
    <tr><td>Subtotal</td><td class="r">{{ $money($order->gross_amount) }}</td></tr>
    @if ((float) $order->discount_amount > 0)<tr><td>Hemat promo</td><td class="r">-{{ $money($order->discount_amount) }}</td></tr>@endif
    <tr class="b big"><td>TOTAL</td><td class="r">Rp{{ $money($order->net_amount) }}</td></tr>
  </table>
  <hr>
  <table>
    @foreach ($order->payments as $p)
      <tr><td>{{ $methods[$p->method] ?? $p->method }}@if ($p->provider_ref) <span class="muted">({{ $p->provider_ref }})</span>@endif</td><td class="r">{{ $money($p->tendered ?: $p->amount) }}</td></tr>
      @if ((float) $p->change_amount > 0)<tr><td>Kembali</td><td class="r">{{ $money($p->change_amount) }}</td></tr>@endif
    @endforeach
  </table>
  @if ($order->returns->isNotEmpty())
    <hr>
    @foreach ($order->returns as $r)
      <div>Retur {{ $r->doc_no }}: -{{ $money($r->net_amount) }}</div>
    @endforeach
  @endif
  <hr>
  <div class="c">{{ config('rasentra.pos.receipt_footer') }}</div>
  <div class="c muted">Retur maks. {{ config('rasentra.pos.return_days') }} hari dengan struk ini.</div>
  <div class="c muted">Daging segar diretur utuh sesuai label Pack ID.</div>
@endsection
