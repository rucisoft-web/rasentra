@extends('core.layout')
@section('content')
<p><a href="{{ route('core.outlets.index') }}">&larr; Semua outlet</a></p>
<div class="grid g2">
  <form class="card" method="post" action="{{ route('core.outlets.update', $outlet) }}">
    @csrf @method('put')
    <h2>Data outlet</h2>
    <div class="fields">
      <label class="f"><span>Kode</span><input value="{{ $outlet->code }}" disabled></label>
      <label class="f"><span>Nama</span><input name="name" value="{{ old('name', $outlet->name) }}" required></label>
      <label class="f"><span>Nama usaha retail</span><input name="retail_name" value="{{ old('retail_name', $outlet->retail_name) }}" placeholder="Meatshop & Grocery"></label>
      <label class="f"><span>Nama restoran</span><input name="restaurant_name" value="{{ old('restaurant_name', $outlet->restaurant_name) }}" placeholder="R&B Solo"></label>
      <label class="f"><span>Wilayah · perusahaan</span>
        <select name="region_id">@foreach ($regions as $r)<option value="{{ $r->id }}" @selected($r->id == $outlet->region_id)>{{ $r->name }} · {{ $r->businessUnit->name }} ({{ $r->businessUnit->sap_company_db }})</option>@endforeach</select>
      </label>
      <label class="f"><span>Zona waktu</span>
        <select name="timezone">@foreach (['Asia/Jakarta' => 'WIB (Asia/Jakarta)', 'Asia/Makassar' => 'WITA (Asia/Makassar)', 'Asia/Jayapura' => 'WIT (Asia/Jayapura)'] as $tz => $label)<option value="{{ $tz }}" @selected($outlet->timezone === $tz)>{{ $label }}</option>@endforeach</select>
      </label>
      <label class="f"><span>Kode gudang SAP</span><input name="sap_warehouse_code" value="{{ old('sap_warehouse_code', $outlet->sap_warehouse_code) }}"></label>
      <div class="wide actions">
        <label class="check"><input type="checkbox" name="has_retail" value="1" @checked($outlet->has_retail)> Retail</label>
        <label class="check"><input type="checkbox" name="has_restaurant" value="1" @checked($outlet->has_restaurant)> Restoran</label>
        <label class="check"><input type="checkbox" name="has_cooking_fee" value="1" @checked($outlet->has_cooking_fee)> Cooking fee</label>
        <label class="check"><input type="checkbox" name="is_active" value="1" @checked($outlet->is_active)> Aktif</label>
      </div>
    </div>
    <p><button class="btn">Simpan</button></p>
  </form>

  <div class="card">
    <h2>Lokasi stok</h2>
    <p class="muted small">Kode lokasi dicetak sebagai QR dan dipindai petugas gudang saat opname.</p>
    <table class="t">
      <thead><tr><th>Kode</th><th>Nama</th><th>Tipe</th><th>Status</th><th></th></tr></thead>
      <tbody>
      @foreach ($outlet->locations->sortBy('code') as $l)
        <tr>
          <td class="mono"><b>{{ $l->code }}</b></td><td>{{ $l->name }}</td><td>{{ $types[$l->type] ?? $l->type }}</td>
          <td>@if($l->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</td>
          <td><form class="inline" method="post" action="{{ route('core.outlets.locations.toggle', $l) }}">@csrf<button class="btn alt sm">{{ $l->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <form method="post" action="{{ route('core.outlets.locations.store', $outlet) }}" class="bar" style="margin-top:12px">
      @csrf
      <label class="f"><span>Kode</span><input name="code" size="6" required></label>
      <label class="f"><span>Nama</span><input name="name" required></label>
      <label class="f"><span>Tipe</span><select name="type">@foreach ($types as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
      <button class="btn">Tambah lokasi</button>
    </form>
  </div>
</div>
@endsection
