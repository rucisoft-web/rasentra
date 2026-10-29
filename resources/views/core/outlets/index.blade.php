@extends('core.layout')
@section('content')
<p class="muted">{{ $outlets->count() }} outlet terdaftar. Fasilitas outlet menentukan menu yang tampil di Rasentra POS (resto, cooking fee) dan profil Rasentra Lokal yang dipasang.</p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Nama</th><th>Wilayah</th><th>Fasilitas</th><th>Gudang SAP</th><th class="n">Lokasi</th><th class="n">Petugas</th><th class="n">Perangkat</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @foreach ($outlets as $o)
    <tr>
      <td class="mono"><b>{{ $o->code }}</b></td>
      <td>{{ $o->name }}</td>
      <td>{{ $o->region->name }}</td>
      <td class="actions">
        @if($o->has_retail)<span class="b">Retail</span>@endif
        @if($o->has_restaurant)<span class="b info">Resto</span>@endif
        @if($o->has_cooking_fee)<span class="b warn">Cooking fee</span>@endif
      </td>
      <td class="mono">{{ $o->sap_warehouse_code ?? '-' }}</td>
      <td class="n">{{ $o->locations_count }}</td>
      <td class="n">{{ $users[$o->id] ?? 0 }}</td>
      <td class="n">{{ $devices[$o->id] ?? 0 }}</td>
      <td>@if($o->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</td>
      <td><a class="btn alt sm" href="{{ route('core.outlets.edit', $o) }}">Atur</a></td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>
@endsection
