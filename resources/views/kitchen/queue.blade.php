<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Antrean pesanan · Rasentra</title>
<link rel="stylesheet" href="{{ asset('assets/kitchen/kitchen.css') }}">
</head>
<body class="qscreen" data-queue>
<header class="qhead"><div class="brand"><i></i>{{ $outlet?->name }}</div><span class="clock mono" id="clock"></span></header>
<main class="qgrid">
  <section class="qcol prep"><h2>Sedang disiapkan <small>Preparing</small></h2><div class="qnums" id="q-prep"></div></section>
  <section class="qcol ready"><h2>Silakan ambil <small>Ready for pickup</small></h2><div class="qnums" id="q-ready"></div></section>
</main>
<script src="{{ asset('assets/kitchen/kitchen.js') }}" defer></script>
</body>
</html>
