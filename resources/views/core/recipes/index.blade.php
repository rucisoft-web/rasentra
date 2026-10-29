@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<form class="bar" method="get">
  <label class="f"><span>Cari</span><input name="q" value="{{ request('q') }}" placeholder="Nama resep"></label>
  <label class="f"><span>Jenis</span><select name="kind"><option value="">Semua</option>@foreach ($kinds as $k => $v)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $v }}</option>@endforeach</select></label>
  <label class="f"><span>Status</span><select name="status"><option value="">Semua</option><option value="draft" @selected(request('status') === 'draft')>Punya draft</option></select></label>
  <button class="btn alt">Saring</button>
</form>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Resep</th><th>Produk</th><th>Jenis</th><th>Versi aktif</th><th>Hasil</th><th>Draft</th><th></th></tr></thead>
  <tbody>
  @forelse ($recipes as $r)
    @php $active = $r->versions->firstWhere('status', 'approved'); $draft = $r->versions->firstWhere('status', 'draft'); $latest = $r->versions->first(); @endphp
    <tr>
      <td><b>{{ $r->name }}</b></td>
      <td class="mono">{{ $r->product?->code }}</td>
      <td>{{ $kinds[$latest?->kind] ?? '-' }}</td>
      <td>{{ $active ? 'v'.$active->version : '-' }}@if($active?->effective_from)<div class="muted small">sejak {{ Fmt::dt($active->effective_from) }}</div>@endif</td>
      <td>{{ $active ? Fmt::qty($active->yield_quantity, 2).' '.$active->yield_uom : '-' }}</td>
      <td>@if($draft)<span class="b warn">v{{ $draft->version }} draft</span>@endif</td>
      <td><a class="btn alt sm" href="{{ route('core.recipes.show', $r) }}">Buka</a></td>
    </tr>
  @empty
    <tr><td colspan="7" class="muted">Tidak ada resep.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection
