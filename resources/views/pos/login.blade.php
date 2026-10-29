<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Masuk · Rasentra POS</title>
<link rel="stylesheet" href="{{ asset('assets/pos/pos.css') }}">
</head>
<body class="login">
<main class="login-wrap">
  <header class="login-head">
    <div class="brand"><i></i>Rasentra POS</div>
    <div class="muted">{{ $outlet?->name ?? 'Outlet belum diatur' }}</div>
  </header>

  <form method="post" action="{{ url('/pos/login') }}" id="login-form" class="login-grid">
    @csrf
    <section>
      <h2>Pilih nama Anda</h2>
      <div class="user-list">
        @forelse ($users as $u)
          <label class="user-tile">
            <input type="radio" name="user_id" value="{{ $u->id }}" @checked(old('user_id') == $u->id)>
            <span class="avatar">{{ mb_substr($u->name, 0, 1) }}</span>
            <span><b>{{ $u->name }}</b><small>{{ str_replace('_', ' ', $u->role) }}</small></span>
          </label>
        @empty
          <p class="muted">Belum ada petugas dengan PIN di outlet ini.</p>
        @endforelse
      </div>
    </section>
    <section class="pin-pane">
      <h2>Masukkan PIN</h2>
      <input type="password" name="pin" id="pin" inputmode="numeric" autocomplete="off" maxlength="8" class="pin-display" readonly>
      @error('pin')<p class="error">{{ $message }}</p>@enderror
      <div class="keypad" data-target="pin">
        @foreach ([1,2,3,4,5,6,7,8,9] as $k)<button type="button" data-key="{{ $k }}">{{ $k }}</button>@endforeach
        <button type="button" data-key="clear" class="soft">Hapus</button>
        <button type="button" data-key="0">0</button>
        <button type="button" data-key="back" class="soft">⌫</button>
      </div>
      <button type="submit" class="btn primary block xl">Masuk</button>
    </section>
  </form>
</main>
<script>
document.querySelectorAll('.keypad').forEach(function (pad) {
  var input = document.getElementById(pad.dataset.target);
  pad.addEventListener('click', function (e) {
    var k = e.target.closest('button'); if (!k) return;
    var key = k.dataset.key;
    if (key === 'clear') input.value = '';
    else if (key === 'back') input.value = input.value.slice(0, -1);
    else if (input.value.length < 8) input.value += key;
  });
});
</script>
</body>
</html>
