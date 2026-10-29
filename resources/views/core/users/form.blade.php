@extends('core.layout')
@section('content')
<p><a href="{{ route('core.users.index') }}">&larr; Semua pengguna</a></p>
<form class="card" method="post" action="{{ $user->exists ? route('core.users.update', $user) : route('core.users.store') }}" style="max-width:880px">
  @csrf @if($user->exists) @method('put') @endif
  <div class="fields">
    <label class="f"><span>Nama</span><input name="name" value="{{ old('name', $user->name) }}" required></label>
    <label class="f"><span>Email</span><input type="email" name="email" value="{{ old('email', $user->email) }}" required></label>
    <label class="f"><span>Peran</span>
      <select name="role">
        <optgroup label="Outlet">@foreach (\App\Http\Controllers\Core\UserController::OUTLET_ROLES as $k => $v)<option value="{{ $k }}" @selected(old('role', $user->role) === $k)>{{ $v }}</option>@endforeach</optgroup>
        <optgroup label="Kantor pusat">@foreach (\App\Domain\Core\CoreAccess::ROLES as $k => $v)<option value="{{ $k }}" @selected(old('role', $user->role) === $k)>{{ $v }}</option>@endforeach</optgroup>
      </select>
    </label>
    <label class="f"><span>Outlet</span>
      <select name="outlet_id"><option value="">Kantor pusat (tanpa outlet)</option>
        @foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(old('outlet_id', $user->outlet_id) == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select>
    </label>
    <label class="f"><span>PIN {{ $user->exists ? 'baru' : '' }}</span><input name="pin" inputmode="numeric" autocomplete="new-password" placeholder="{{ $user->exists ? 'Kosongkan bila tidak diganti' : '4 sampai 8 angka' }}"><small>Untuk petugas outlet, dipakai di Rasentra POS, Kitchen dan Inventory.</small></label>
    <label class="f"><span>Kata sandi {{ $user->exists ? 'baru' : '' }}</span><input type="password" name="password" autocomplete="new-password" placeholder="Minimal 10 karakter"><small>Untuk akun kantor pusat (Rasentra Core).</small></label>
    <label class="f"><span>Limit approval (Rp)</span><input name="approval_limit" inputmode="decimal" value="{{ old('approval_limit', (float) $user->approval_limit ?: '') }}"><small>Hanya berlaku untuk kepala outlet dan admin pusat.</small></label>
    <label class="check wide"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active ?? true))> Aktif</label>
  </div>
  <p><button class="btn">{{ $user->exists ? 'Simpan perubahan' : 'Buat pengguna' }}</button></p>
</form>
@endsection
