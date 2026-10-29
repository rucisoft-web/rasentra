@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<form class="bar" method="get">
  <label class="f"><span>Cari</span><input name="q" value="{{ request('q') }}" placeholder="Nama, kode atau barcode"></label>
  <label class="f"><span>Tipe</span><select name="type"><option value="">Semua</option>@foreach ($types as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select></label>
  <label class="f"><span>Kategori</span><select name="category"><option value="">Semua</option>@foreach ($categories as $c)<option @selected(request('category') === $c)>{{ $c }}</option>@endforeach</select></label>
  <button class="btn alt">Saring</button>
  <a class="btn grow" href="{{ route('core.products.create') }}">Produk baru</a>
</form>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Nama</th><th>Tipe</th><th>Satuan</th><th>Kategori</th><th>Station</th><th class="n">Harga terbit</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @forelse ($products as $p)
    @php $pr = $published[$p->id] ?? null; @endphp
    <tr>
      <td class="mono"><b>{{ $p->code }}</b>@if($p->barcode)<div class="muted small">{{ $p->barcode }}</div>@endif</td>
      <td>{{ $p->name }}</td>
      <td>{{ $types[$p->type] ?? $p->type }}</td>
      <td>{{ $p->uom_code }}</td>
      <td>{{ $p->category ?? '-' }}</td>
      <td>{{ $p->kitchen_station ?? '-' }}</td>
      <td class="n">@if($pr){{ Fmt::money($pr->lo) }}@if($pr->hi != $pr->lo) – {{ Fmt::money($pr->hi) }}@endif<div class="muted small">{{ $pr->n }} versi</div>@else<span class="muted">-</span>@endif</td>
      <td>@if($p->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</td>
      <td><a class="btn alt sm" href="{{ route('core.products.edit', $p) }}">Ubah</a></td>
    </tr>
  @empty
    <tr><td colspan="9" class="muted">Tidak ada produk yang cocok.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $products->links('core.pager') }}
@endsection
