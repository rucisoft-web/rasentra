<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Produksi · Rasentra Kitchen</title>
<link rel="stylesheet" href="{{ asset('assets/kitchen/kitchen.css') }}">
</head>
<body class="kds report" data-production data-reasons='@json($reasons)'>
<header class="kbar">
  <a class="brand" href="{{ route('kitchen') }}"><i></i>Rasentra Kitchen</a>
  <b class="station-name">Produksi</b>
  <span class="muted">{{ $outlet?->code }} · {{ $outlet?->name }} · {{ auth()->user()->name }}</span>
</header>
<main class="prod">
  <section class="prod-main">
    <nav class="seg" id="mode"><button data-mode="recipe" class="on">Dari resep</button><button data-mode="cutting">Potong &amp; porsi</button></nav>

    <div id="pane-recipe">
      <p class="muted">Pilih resep berstok (saus, kaldu, bumbu). Bahan standar mengikuti resep yang disetujui kantor pusat; koreksi bila pemakaian aktual berbeda, lalu timbang hasilnya.</p>
      <div class="recipes" id="recipes"></div>
      <div class="pform" id="recipe-form" hidden></div>
    </div>

    <div id="pane-cutting" hidden>
      <p class="muted">Potong daging atau ikan utuh menjadi porsi siap masak dan produk samping. Semua dalam KG: susut = bahan masuk dikurangi semua hasil.</p>
      <div class="pform" id="cut-form"></div>
    </div>
  </section>

  <aside class="prod-side">
    <h2>Stok hasil prep</h2>
    <div id="prep-stock" class="mini"></div>
    <h2>Produksi hari ini</h2>
    <div id="history"></div>
  </aside>
</main>
<div class="toast" id="toast" hidden></div>
<script src="{{ asset('assets/kitchen/production.js') }}" defer></script>
@include('partials.demo-bar')
</body>
</html>
