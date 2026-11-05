@extends('core.layout')
@section('content')
<p class="muted">Centang izin per peran. Izin peran outlet dikirim ke semua Rasentra Lokal, jadi aturan kerja sama di seluruh outlet. Admin pusat selalu memegang semua izin.</p>
<form method="post" action="{{ route('core.access.update') }}">@csrf @method('put')
@foreach (['central' => ['Kantor pusat (Rasentra Core)', $central], 'outlet' => ['Outlet (Rasentra Lokal & aplikasinya)', $outlet]] as $scope => [$label, $perms])
  @php $scoped = $roles->where('scope', $scope); @endphp
  <h3>{{ $label }}</h3>
  <div class="tablewrap" style="margin-bottom:16px"><table class="t">
    <thead><tr><th>Izin</th>@foreach ($scoped as $r)<th>{{ $r->name }}<br><span class="mono small muted">{{ $r->code }}</span></th>@endforeach</tr></thead>
    <tbody>@foreach ($perms as $key => $name)
      <tr><td>{{ $name }} <span class="mono small muted">{{ $key }}</span></td>
      @foreach ($scoped as $r)
        <td>@if ($r->code === 'admin')✓@else<input type="checkbox" name="perm[{{ $r->code }}][]" value="{{ $key }}" @checked(in_array($key, $r->permissions ?? [], true) || in_array('*', $r->permissions ?? [], true))>@endif</td>
      @endforeach</tr>
    @endforeach</tbody>
  </table></div>
@endforeach
<p><button class="btn">Simpan izin</button></p>
</form>
<details class="card"><summary><b>Peran baru</b></summary>
  <form method="post" action="{{ route('core.access.store') }}" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:end">@csrf
    <label class="f"><span>Kode</span><input name="code" required class="mono" placeholder="supervisor_resto"></label>
    <label class="f"><span>Nama</span><input name="name" required placeholder="Supervisor resto"></label>
    <label class="f"><span>Lingkup</span><select name="scope"><option value="outlet">Outlet</option><option value="central">Kantor pusat</option></select></label>
    <button class="btn">Buat peran</button>
  </form>
</details>
@endsection
