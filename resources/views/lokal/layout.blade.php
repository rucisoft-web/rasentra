@use('App\Http\Middleware\EnsureLokalAccess')
@php
    $user = auth()->user();
    $canConsole = EnsureLokalAccess::allows($user);
    $nav = [
        ['lokal.index', 'Status server'],
        ['lokal.outbox', 'Antrean kirim'],
        ['lokal.publications', 'Harga, promo & resep'],
        ['lokal.close-day', 'Tutup hari'],
        ['lokal.setup', 'Pemasangan'],
    ];
    $outletName = \App\Models\Outlet::where('code', config('rasentra.outlet_code'))->value('name');
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'Rasentra Lokal' }} · Rasentra Lokal</title>
<link rel="stylesheet" href="{{ asset('assets/core/core.css') }}">
<script src="{{ asset('assets/core/core.js') }}" defer></script>
</head>
<body>
<div class="shell">
  <nav class="side">
    <div class="brand"><i></i>Rasentra Lokal</div>
    <div class="sub">Server outlet {{ $outletName ?? 'belum dipasang' }}{{ config('rasentra.outlet_code') ? ' · '.config('rasentra.outlet_code') : '' }}</div>
    @if ($canConsole)
      <h6>Server outlet</h6>
      @foreach ($nav as [$route, $label])
        <a href="{{ route($route) }}" @class(['on' => request()->routeIs($route)])>{{ $label }}</a>
      @endforeach
      <h6>Aplikasi outlet</h6>
      <a href="{{ route('pos') }}">Rasentra POS</a>
      <a href="{{ route('kitchen') }}">Rasentra Kitchen</a>
      <a href="{{ route('inventory') }}">Rasentra Inventory</a>
    @endif
  </nav>
  <div class="main">
    <header class="top">
      <h1>{{ $title ?? 'Rasentra Lokal' }}</h1>
      <div class="who">
        @if ($user)
          <span>{{ $user->name }} · {{ str_replace('_', ' ', $user->role) }}</span>
          <form method="post" action="{{ route('pos.logout') }}">@csrf<button class="btn alt sm">Keluar</button></form>
        @else
          <a class="btn alt sm" href="{{ route('pos.login') }}">Masuk dengan PIN</a>
        @endif
      </div>
    </header>
    <div class="content">
      @if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
      @if (session('error'))<div class="alert err">{{ session('error') }}</div>@endif
      @if ($errors->any())
        <div class="alert err">Periksa isian berikut:<ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @endif
      @yield('content')
    </div>
  </div>
</div>
@include('partials.demo-bar')
</body>
</html>
