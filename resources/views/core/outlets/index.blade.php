@extends('core.layout')
@section('content')
<h2>Perusahaan di {{ config('rasentra.group_name') }}</h2>
<p class="muted small">Setiap perusahaan memposting penjualan ke pembukuannya sendiri: SAP Business One (SQL Server) atau Accurate Online. Untuk SAP B1, alamat dan kredensial Service Layer tiap instance diatur di server Rasentra Central (SAP_SOLO_*, SAP_LOMBOK_*), bukan di layar ini.</p>
<div class="grid g2">
  @foreach ($companies as $c)
    <form class="card" method="post" action="{{ route('core.companies.update', $c) }}">
      @csrf @method('put')
      <div class="fields">
        <label class="f"><span>Kode</span><input value="{{ $c->code }}" disabled></label>
        <label class="f"><span>Nama perusahaan</span><input name="name" value="{{ $c->name }}" required></label>
        <label class="f"><span>Pembukuan</span><select name="accounting_system">@foreach (\App\Models\BusinessUnit::ACCOUNTING as $k => $v)<option value="{{ $k }}" @selected(($c->accounting_system ?? 'sap_b1') === $k)>{{ $v }}</option>@endforeach</select></label>
        <label class="f"><span>Kode database (SAP / Accurate)</span><input name="sap_company_db" class="mono" value="{{ $c->sap_company_db }}" required></label>
        @if ($c->usesAccurate())
          @if (\Illuminate\Support\Facades\Schema::hasTable('crm_accurate_connections') && \App\Models\Crm\AccurateConnection::where('business_unit_id', $c->id)->whereNotNull('api_token')->exists())
            <p class="hint" style="grid-column:1/-1">Posting memakai API Token dari Rasentra CRM → Accurate Online untuk CV ini. Kolom di bawah hanya cadangan.</p>
          @endif
          <label class="f"><span>Accurate API Token</span><input name="accurate_api_token" type="password" autocomplete="off" placeholder="{{ $c->accurate_api_token ? 'tersimpan, isi untuk mengganti' : 'belum diisi' }}"></label>
          <label class="f"><span>Accurate Signature Secret</span><input name="accurate_signature_secret" type="password" autocomplete="off" placeholder="{{ $c->accurate_signature_secret ? 'tersimpan, isi untuk mengganti' : 'belum diisi' }}"></label>
          <label class="f"><span>Host Accurate (opsional)</span><input name="accurate_host" value="{{ $c->accurate_host }}" placeholder="otomatis dari token"></label>
        @endif
        <label class="f"><span>Outlet</span><input value="{{ $c->outlets_count }} outlet" disabled></label>
      </div>
      <p><button class="btn alt sm" data-confirm="Mengubah database SAP memindahkan tujuan posting perusahaan ini. Lanjutkan?">Simpan</button></p>
    </form>
  @endforeach
</div>
<h2>Outlet</h2>
<p class="muted">{{ $outlets->count() }} outlet terdaftar. Fasilitas outlet menentukan menu yang tampil di Rasentra POS (resto, cooking fee) dan profil Rasentra Lokal yang dipasang.</p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Nama</th><th>Perusahaan</th><th>Fasilitas</th><th>Gudang SAP</th><th class="n">Lokasi</th><th class="n">Petugas</th><th class="n">Perangkat</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @foreach ($outlets as $o)
    <tr>
      <td class="mono"><b>{{ $o->code }}</b></td>
      <td>{{ $o->name }} <span class="muted small">· {{ $o->timezone === 'Asia/Makassar' ? 'WITA' : ($o->timezone === 'Asia/Jayapura' ? 'WIT' : 'WIB') }}</span></td>
      <td>{{ $o->businessUnit->name }}<div class="muted small mono">{{ $o->businessUnit->sap_company_db }}</div></td>
      <td class="actions">
        @if($o->has_retail)<span class="b">{{ $o->retail_name ?: 'Retail' }}</span>@endif
        @if($o->has_restaurant)<span class="b info">{{ $o->restaurant_name ?: 'Resto' }}</span>@endif
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
