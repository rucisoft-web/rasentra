@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<form class="bar" method="get">
  <label class="f"><span>Cari</span><input name="q" value="{{ request('q') }}" placeholder="Kode atau nama promo"></label>
  <label class="f"><span>Status</span><select name="status"><option value="">Semua</option>@foreach ($statuses as $k => [$label])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $label }}</option>@endforeach</select></label>
  <button class="btn alt">Saring</button>
  <a class="btn grow" href="{{ route('core.promotions.create') }}">Promo baru</a>
</form>
<p class="muted small">Alur: draft &rarr; simulasi &rarr; diterbitkan oleh rekan pusat lain &rarr; outlet mengonfirmasi penerimaan. Versi terbit tidak dapat diubah; perubahan dibuat sebagai versi baru.</p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Nama</th><th>Tipe</th><th class="n">Prioritas</th><th>Periode</th><th>Kanal</th><th class="n">Outlet</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @forelse ($versions as $v)
    @php [$label, $tone] = $statuses[$v->status] ?? [$v->status, 'mute']; @endphp
    <tr>
      <td class="mono"><b>{{ $v->promotion->code }}</b> <span class="muted">v{{ $v->version }}</span></td>
      <td>{{ $v->promotion->name }}</td>
      <td>{{ $types[$v->type] ?? $v->type }}<div class="muted small">{{ $v->scope === 'basket' ? 'keranjang' : 'per barang' }} · {{ str_replace('_', ' ', $v->stacking) }}</div></td>
      <td class="n">{{ $v->priority }}</td>
      <td class="small">{{ Fmt::dt($v->starts_at) }}<br>{{ $v->ends_at ? 's/d '.Fmt::dt($v->ends_at) : 'tanpa batas' }}</td>
      <td class="small">{{ empty($v->channels) ? 'Semua' : implode(', ', $v->channels) }}</td>
      <td class="n">{{ $v->targets_count ?: '-' }}</td>
      <td><span class="b {{ $tone }}">{{ $label }}</span></td>
      <td><a class="btn alt sm" href="{{ route('core.promotions.show', $v) }}">Buka</a></td>
    </tr>
  @empty
    <tr><td colspan="9" class="muted">Belum ada promo.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $versions->links('core.pager') }}
@endsection
