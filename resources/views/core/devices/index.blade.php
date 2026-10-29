@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@if (session('token'))
  <div class="alert info">
    <b>Token untuk {{ session('token')['device'] }}</b>. Salin sekarang; token tidak akan ditampilkan lagi.
    <div class="token" style="margin-top:6px">{{ session('token')['token'] }}</div>
    <div class="small" style="margin-top:6px">Untuk server Rasentra Lokal: isi <span class="mono">RASENTRA_CENTRAL_TOKEN</span> dengan token ini dan <span class="mono">RASENTRA_SOURCE_ID={{ session('token')['device'] }}</span>.</div>
  </div>
@endif

<form class="card bar" method="post" action="{{ route('core.devices.store') }}">
  @csrf
  <label class="f"><span>Outlet</span><select name="outlet_id">@foreach ($outlets as $o)<option value="{{ $o->id }}">{{ $o->code }} {{ $o->name }}</option>@endforeach</select></label>
  <label class="f"><span>Aplikasi</span><select name="app">@foreach ($apps as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
  <label class="f"><span>Kode</span><input name="code" placeholder="SRV-JMS / POS-JMS-03" required></label>
  <label class="f"><span>Nama</span><input name="name" placeholder="Kasir 3" required></label>
  <button class="btn">Daftarkan & buat token</button>
</form>

<form class="bar" method="get">
  <label class="f"><span>Outlet</span><select name="outlet" onchange="this.form.submit()"><option value="">Semua</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(request('outlet') == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select></label>
</form>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Nama</th><th>Aplikasi</th><th>Outlet</th><th>Terakhir terlihat</th><th>Token</th><th></th></tr></thead>
  <tbody>
  @forelse ($devices as $d)
    <tr>
      <td class="mono"><b>{{ $d->code }}</b></td>
      <td>{{ $d->name }}</td>
      <td>{{ $apps[$d->app] ?? $d->app }}</td>
      <td>{{ $outlets[$d->outlet_id]->code ?? '-' }}</td>
      <td>{{ Fmt::dt($d->last_seen_at) }}</td>
      <td>@if($d->is_active && $d->token_hash)<span class="b ok">Aktif</span>@elseif($d->is_active)<span class="b warn">Tanpa token</span>@else<span class="b mute">Dicabut</span>@endif</td>
      <td class="actions">
        <form class="inline" method="post" action="{{ route('core.devices.token', $d) }}">@csrf<button class="btn alt sm" data-confirm="Token lama {{ $d->code }} langsung tidak berlaku. Lanjutkan?">Buat token baru</button></form>
        @if($d->is_active)<form class="inline" method="post" action="{{ route('core.devices.revoke', $d) }}">@csrf<button class="btn danger sm" data-confirm="Cabut token {{ $d->code }}?">Cabut</button></form>@endif
      </td>
    </tr>
  @empty
    <tr><td colspan="7" class="muted">Belum ada perangkat terdaftar.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection
