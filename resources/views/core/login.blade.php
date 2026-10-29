<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk · Rasentra Core</title>
<link rel="stylesheet" href="{{ asset('assets/core/core.css') }}">
</head>
<body class="login">
<form class="card grid" method="post" action="{{ route('core.login') }}">
  @csrf
  <div>
    <div class="brand"><i></i>Rasentra Core</div>
    <div class="muted">Admin kantor pusat CV Pasar Tani</div>
  </div>
  @if ($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif
  <label class="f"><span>Email</span><input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
  <label class="f"><span>Kata sandi</span><input type="password" name="password" required></label>
  <button class="btn">Masuk</button>
  <p class="muted small">Petugas outlet masuk lewat Rasentra POS dengan PIN.</p>
</form>
</body>
</html>
