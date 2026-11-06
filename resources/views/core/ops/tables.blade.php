@extends('core.layout')
@section('content')
<p class="muted">Kelola meja setiap resto dari pusat. Setiap meja punya QR untuk self ordering; tamu memindai, memilih menu, dan pesanan menunggu diterima pelayan.</p>
<form method="get" style="margin-bottom:12px;display:flex;gap:8px">
  <select name="outlet" onchange="this.form.submit()">@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected($outlet?->id === $o->id)>{{ $o->code }} · {{ $o->restaurant_name ?? $o->name }}</option>@endforeach</select>
  @if ($outlet)<a class="btn alt" href="{{ route('core.tables.print', $outlet) }}" target="_blank">Cetak QR semua meja</a>@endif
</form>
@if ($outlet)
<div class="tablewrap" style="margin-bottom:14px"><table class="t">
  <thead><tr><th>Meja</th><th>Area · kapasitas · aktif</th><th>QR</th></tr></thead>
  <tbody>@foreach ($tables as $t)
    <tr><td class="mono"><b>{{ $t->code }}</b></td>
      <td><form method="post" action="{{ route('core.tables.update', $t) }}" style="display:flex;gap:6px;align-items:center">@csrf @method('put')
        <input name="area" value="{{ $t->area }}" placeholder="Area" style="width:120px"><input name="capacity" type="number" min="1" value="{{ $t->capacity }}" style="width:70px">
        <label><input type="checkbox" name="is_active" value="1" @checked($t->is_active)> aktif</label><button class="btn sm alt">Simpan</button></form></td>
      <td>@if ($t->qr_token)<a class="small" href="{{ url('order/'.$t->qr_token) }}" target="_blank">buka</a>@else<span class="muted small">belum ada</span>@endif
        <form method="post" action="{{ route('core.tables.qr', $t) }}" style="display:inline">@csrf<button class="btn sm alt">{{ $t->qr_token ? 'Ganti QR' : 'Buat QR' }}</button></form></td></tr>
  @endforeach</tbody>
</table></div>
<details class="card"><summary><b>Meja baru</b></summary>
  <form method="post" action="{{ route('core.tables.store') }}" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:end">@csrf
    <input type="hidden" name="outlet_id" value="{{ $outlet->id }}">
    <label class="f"><span>Kode meja</span><input name="code" required class="mono" placeholder="T12"></label>
    <label class="f"><span>Area</span><input name="area" placeholder="Indoor"></label>
    <label class="f"><span>Kapasitas</span><input name="capacity" type="number" min="1" value="4" required></label>
    <button class="btn">Tambah</button>
  </form>
</details>
@else
  <div class="card muted">Belum ada outlet dengan resto.</div>
@endif
@endsection
