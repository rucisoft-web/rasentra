<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Rasentra Kitchen · {{ $station === 'expo' ? 'Expeditor' : $stations[$station]['name'] }}</title>
<link rel="stylesheet" href="{{ asset('assets/kitchen/kitchen.css') }}">
</head>
<body class="kds" data-station="{{ $station }}">
<header class="kbar">
  <a class="brand" href="{{ route('kitchen') }}"><i></i>Rasentra Kitchen</a>
  <b class="station-name">{{ $station === 'expo' ? 'Expeditor' : $stations[$station]['name'] }}</b>
  <div class="counts"><span data-i18n="active">Aktif</span> <b id="count">0</b> · <span data-i18n="late">Terlambat</span> <b id="late">0</b></div>
  <input id="scan" class="kscan" autocomplete="off" inputmode="numeric" data-i18n-ph="scan" placeholder="Scan barcode tiket">
  <div class="push">
    <span class="pill" id="conn">LIVE</span>
    <button class="kbtn ghost" id="lang">EN</button>
    <span class="clock mono" id="clock"></span>
  </div>
</header>
<main class="board" id="board"><p class="empty" data-i18n="empty">Belum ada tiket.</p></main>
<div class="toast" id="toast" hidden></div>
<script src="{{ asset('assets/kitchen/kitchen.js') }}" defer></script>
</body>
</html>
