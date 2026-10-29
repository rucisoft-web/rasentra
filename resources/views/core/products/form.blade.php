@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<p><a href="{{ route('core.products.index') }}">&larr; Semua produk</a></p>
<div class="grid g2">
<form class="card" method="post" action="{{ $product->exists ? route('core.products.update', $product) : route('core.products.store') }}">
  @csrf @if($product->exists) @method('put') @endif
  <h2>Data produk</h2>
  <div class="fields">
    <label class="f"><span>Kode</span><input name="code" value="{{ old('code', $product->code) }}" required class="mono"><small>Teks, nol di depan dipertahankan.</small></label>
    <label class="f"><span>Barcode</span><input name="barcode" value="{{ old('barcode', $product->barcode) }}"></label>
    <label class="f wide"><span>Nama</span><input name="name" value="{{ old('name', $product->name) }}" required></label>
    <label class="f"><span>Tipe</span><select name="type">@foreach ($types as $k => $v)<option value="{{ $k }}" @selected(old('type', $product->type) === $k)>{{ $v }}</option>@endforeach</select></label>
    <label class="f"><span>Satuan</span><select name="uom_code">@foreach ($uoms as $k => $v)<option value="{{ $k }}" @selected(old('uom_code', $product->uom_code) === $k)>{{ $k }} · {{ $v }}</option>@endforeach</select></label>
    <label class="f"><span>Kategori</span><input name="category" list="cats" value="{{ old('category', $product->category) }}"><datalist id="cats">@foreach ($categories as $c)<option>{{ $c }}</option>@endforeach</datalist></label>
    <label class="f"><span>Station dapur</span><select name="kitchen_station"><option value="">-</option>@foreach ($stations as $k => $s)<option value="{{ $k }}" @selected(old('kitchen_station', $product->kitchen_station) === $k)>{{ $s['name'] }}</option>@endforeach</select></label>
    <div class="wide actions">
      <label class="check"><input type="checkbox" name="is_weighed" value="1" @checked(old('is_weighed', $product->is_weighed))> Ditimbang</label>
      <label class="check"><input type="checkbox" name="is_stocked" value="1" @checked(old('is_stocked', $product->is_stocked))> Dikelola stoknya</label>
      <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))> Aktif</label>
    </div>
  </div>
  <p><button class="btn">{{ $product->exists ? 'Simpan' : 'Buat produk' }}</button></p>
</form>
@if ($product->exists)
<div class="card">
  <h2>Harga di semua versi</h2>
  <table class="t">
    <thead><tr><th>Versi</th><th>Outlet</th><th>Kanal</th><th>Berlaku</th><th>Status</th><th class="n">Harga</th></tr></thead>
    <tbody>
    @forelse ($prices as $pr)
      <tr><td><a href="{{ route('core.prices.show', $pr->version_id) }}" class="mono">{{ $pr->code }}</a></td><td>{{ $pr->outlet_code ?? 'Semua' }}</td><td>{{ $pr->channel ?? 'Semua' }}</td><td>{{ Fmt::dt($pr->effective_from) }}</td>
        <td><span @class(['b', 'ok' => $pr->status === 'published', 'warn' => $pr->status === 'draft'])>{{ $pr->status }}</span></td><td class="n">{{ Fmt::money($pr->unit_price) }}/{{ $product->uom_code }}</td></tr>
    @empty
      <tr><td colspan="6" class="muted">Belum ada harga. Produk tidak tampil di Rasentra POS sampai harganya terbit.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endif
</div>
@endsection
