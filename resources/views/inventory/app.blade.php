<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Rasentra Inventory</title>
<link rel="stylesheet" href="{{ asset('assets/inventory/inventory.css') }}">
</head>
<body data-role="{{ auth()->user()->role }}" data-approvers='@json($approvers)'>
<header class="mbar">
  <div class="brand"><i></i>Rasentra Inventory</div>
  <span class="muted">{{ $outlet?->code }} · {{ auth()->user()->name }}</span>
  <form method="post" action="{{ route('pos.logout') }}">@csrf<button class="link">Keluar</button></form>
</header>

<div class="scanrow">
  <input id="scan" autocomplete="off" placeholder="Scan barcode / Pack ID / QR lokasi" inputmode="search">
  <button id="scan-go">OK</button>
</div>

<main>
  <section class="tab" id="tab-receive">
    <h2>Penerimaan kiriman</h2>
    <p class="muted">Scan Pack ID satu per satu. Pack rusak: tekan Tolak lalu scan.</p>
    <div class="seg"><button data-mode="accept" class="on">Terima</button><button data-mode="reject">Tolak</button></div>
    <div id="receive-result"></div>
    <h3>Masih dalam perjalanan <span class="badge" id="expected-count">0</span></h3>
    <ul class="list" id="expected"></ul>
  </section>

  <section class="tab" id="tab-count" hidden>
    <h2>Stok opname</h2>
    <div id="count-sessions"></div>
    <div id="count-active" hidden>
      <div class="loc">Lokasi: <b id="loc-current">belum dipilih</b></div>
      <div class="locs">@foreach ($locations as $l)<button data-loc="{{ $l->code }}">{{ $l->code }}<small>{{ $l->name }}</small></button>@endforeach</div>
      <div class="qty"><label>Jumlah (untuk SKU satuan)</label><input id="count-qty" inputmode="decimal" placeholder="Kosongkan untuk Pack ID"></div>
      <div id="count-info"></div>
      <ul class="list" id="count-lines"></ul>
      <div id="count-head" hidden>
        <button class="btn" id="btn-review">Review selisih</button>
        <div id="review"></div>
      </div>
    </div>
  </section>

  <section class="tab" id="tab-quarantine" hidden>
    <h2>Karantina &amp; kedaluwarsa</h2>
    <p class="muted">Periksa barang, lalu lepas kembali ke stok jual atau buang. Butuh kepala outlet. Scan kode untuk memilih tindakan.</p>
    <div id="q-content"><p class="muted">Memuat…</p></div>
  </section>

  <section class="tab" id="tab-check" hidden>
    <h2>Cek stok</h2>
    <p class="muted">Scan Pack ID untuk status dan riwayat, atau barcode produk untuk saldo outlet.</p>
    <div id="check-result"></div>
  </section>
</main>

<nav class="tabs">
  <button data-tab="receive" class="on">Terima</button>
  <button data-tab="count">Opname</button>
  <button data-tab="quarantine">Karantina <span class="badge" id="q-count" hidden></span></button>
  <button data-tab="check">Cek stok</button>
</nav>
<div class="dialog" id="dialog" hidden><div class="dialog-body" role="dialog" aria-modal="true"></div></div>
<div class="toast" id="toast" hidden></div>
<script src="{{ asset('assets/inventory/inventory.js') }}" defer></script>
@include('partials.demo-bar')
</body>
</html>
