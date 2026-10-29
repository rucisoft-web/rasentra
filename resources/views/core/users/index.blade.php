@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<form class="bar" method="get">
  <label class="f"><span>Cari</span><input name="q" value="{{ request('q') }}" placeholder="Nama atau email"></label>
  <label class="f"><span>Outlet</span>
    <select name="outlet"><option value="">Semua</option><option value="hq" @selected(request('outlet') === 'hq')>Kantor pusat</option>
      @foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(request('outlet') == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select>
  </label>
  <label class="f"><span>Peran</span>
    <select name="role"><option value="">Semua</option>@foreach ($roles as $k => $v)<option value="{{ $k }}" @selected(request('role') === $k)>{{ $v }}</option>@endforeach</select>
  </label>
  <button class="btn alt">Saring</button>
  <a class="btn grow" href="{{ route('core.users.create') }}">Pengguna baru</a>
</form>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Outlet</th><th>Login</th><th class="n">Limit approval</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @forelse ($users as $u)
    <tr>
      <td><b>{{ $u->name }}</b></td>
      <td>{{ $u->email }}</td>
      <td>{{ $roles[$u->role] ?? $u->role }}</td>
      <td>{{ $u->outlet ? $u->outlet->code.' '.$u->outlet->name : 'Kantor pusat' }}</td>
      <td>{{ $u->outlet_id ? ($u->pin_hash ? 'PIN' : 'PIN belum diatur') : 'Email + kata sandi' }}</td>
      <td class="n">{{ (float) $u->approval_limit > 0 ? Fmt::money($u->approval_limit) : '-' }}</td>
      <td>@if($u->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</td>
      <td><a class="btn alt sm" href="{{ route('core.users.edit', $u) }}">Ubah</a></td>
    </tr>
  @empty
    <tr><td colspan="8" class="muted">Tidak ada pengguna yang cocok.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $users->links('core.pager') }}
@endsection
