<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Rasentra Mobile Terminal</title>
<link rel="stylesheet" href="{{ asset('assets/mobile/mobile.css') }}">
</head>
<body>
<header class="mbar">
  <div class="brand"><i></i>Rasentra Mobile Terminal</div>
  <span class="who" id="who">Memuat…</span>
  <span class="pill" id="net">LAN</span>
  <form method="post" action="{{ route('pos.logout') }}">@csrf<button class="btn ghost light">Keluar</button></form>
</header>

<main>
  <section id="view-map">
    <div class="maphead">
      <nav class="chips" id="areas"></nav>
      <div class="legend"><span class="dot"></span>Kosong <span class="dot occupied"></span>Terisi <span class="dot billing"></span>Minta bayar <span class="dot ready"></span>Siap diantar</div>
    </div>
    <div class="tables" id="tables"><p class="muted">Memuat meja…</p></div>
  </section>

  <section id="view-table" hidden>
    <div class="thead">
      <button class="btn ghost" data-act="back">‹ Peta meja</button>
      <div class="tinfo"><b id="t-code"></b><span id="t-meta" class="muted"></span></div>
      <div class="tacts">
        <button class="btn soft" data-act="guests">Tamu</button>
        <button class="btn soft" data-act="move">Pindah meja</button>
        <button class="btn soft" data-act="cook" id="btn-cook" hidden>Masak daging tamu</button>
      </div>
    </div>
    <nav class="seg narrow" id="pane-switch"><button data-pane="menu" class="on">Menu</button><button data-pane="order">Pesanan <span class="badge" id="o-count">0</span></button></nav>
    <div class="split" data-pane-show="menu">
      <section class="menu">
        <input id="m-search" class="search" placeholder="Cari menu" autocomplete="off" inputmode="search">
        <nav class="chips" id="m-cats"></nav>
        <div class="mgrid" id="m-grid"></div>
      </section>
      <aside class="order">
        <div class="o-lines" id="o-lines"></div>
        <footer class="o-foot" id="o-foot"></footer>
      </aside>
    </div>
  </section>
</main>

<div class="dialog" id="dialog" hidden><div class="dialog-body" role="dialog" aria-modal="true"></div></div>
<div class="toast" id="toast" hidden></div>
<script src="{{ asset('assets/mobile/mobile.js') }}" defer></script>
@include('partials.demo-bar')
</body>
</html>
