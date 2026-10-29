@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Support\Decimal')
@section('content')
@php $draft = $version->status === 'draft'; @endphp
<p><a href="{{ route('core.prices.index') }}">&larr; Semua versi harga</a></p>
<div class="card">
  <div class="actions" style="justify-content:space-between">
    <dl class="dl">
      <dt>Cakupan</dt><dd>{{ $version->outlet ? $version->outlet->code.' '.$version->outlet->name : 'Semua outlet' }} · {{ $version->channel ? $channels[$version->channel] : 'semua kanal' }}</dd>
      <dt>Berlaku mulai</dt><dd>{{ Fmt::dt($version->effective_from) }}</dd>
      <dt>Dibuat oleh</dt><dd>{{ $names[$version->created_by] ?? '-' }}</dd>
      <dt>Status</dt><dd>@if($draft)<span class="b warn">Draft</span>@else<span class="b ok">Terbit</span> oleh {{ $names[$version->published_by] ?? '-' }} {{ Fmt::dt($version->published_at) }}@endif</dd>
      @if($version->note)<dt>Catatan</dt><dd>{{ $version->note }}</dd>@endif
    </dl>
    @if ($draft)
      <form method="post" action="{{ route('core.prices.publish', $version) }}">@csrf
        <button class="btn" data-confirm="Terbitkan {{ $version->code }}? Harga ini akan dipakai Rasentra POS mulai {{ Fmt::dt($version->effective_from) }} dan tidak bisa diubah lagi." @disabled($version->created_by === auth()->id())>Terbitkan</button>
        @if($version->created_by === auth()->id())<div class="muted small">Pembuat draft tidak dapat menerbitkan sendiri.</div>@endif
      </form>
    @endif
  </div>
</div>

<form method="post" action="{{ route('core.prices.update', $version) }}">
  @csrf @method('put')
  <h2>Harga ({{ $items->count() }} produk)</h2>
  <div class="tablewrap">
  <table class="t">
    <thead><tr><th>Kode</th><th>Produk</th><th>Satuan</th><th class="n">Harga berlaku sekarang</th><th class="n">Harga versi ini</th><th class="n">Perubahan</th></tr></thead>
    <tbody data-rows="new">
    @foreach ($items as $i)
      @php
        $cur = $current[$i->product_id] ?? null;
        $delta = $cur !== null && (float) $cur > 0 ? round(((float) $i->unit_price - (float) $cur) / (float) $cur * 100, 1) : null;
      @endphp
      <tr>
        <td class="mono">{{ $i->product->code }}</td>
        <td>{{ $i->product->name }}</td>
        <td>{{ $i->product->uom_code }}</td>
        <td class="n">{{ $cur !== null ? Fmt::money($cur) : '-' }}</td>
        <td class="n">@if($draft)<input name="prices[{{ $i->product_id }}]" value="{{ (float) $i->unit_price }}" inputmode="decimal" style="width:130px;text-align:right">@else{{ Fmt::money($i->unit_price) }}@endif</td>
        <td class="n">@if($delta === null)<span class="muted">baru</span>@elseif($delta == 0)<span class="muted">0%</span>@else<span @class(['b', 'warn' => $delta > 0, 'info' => $delta < 0])>{{ $delta > 0 ? '+' : '' }}{{ $delta }}%</span>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  @if ($draft)
    <template data-row-template="new"><tr><td><input name="new_code[__i__]" placeholder="Kode / barcode" class="mono" style="width:140px"></td><td colspan="3" class="muted small">Produk baru di versi ini</td><td class="n"><input name="new_price[__i__]" inputmode="decimal" style="width:130px;text-align:right"></td><td><a href="#" data-remove-row>hapus</a></td></tr></template>
    <p class="actions"><a href="#" class="btn alt" data-add-row="new">Tambah produk</a><button class="btn">Simpan harga</button><span class="muted small">Kosongkan harga untuk mengeluarkan produk dari versi ini.</span></p>
  @endif
</form>
@endsection
