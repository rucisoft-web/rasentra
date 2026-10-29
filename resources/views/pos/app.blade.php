<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Rasentra POS</title>
<link rel="stylesheet" href="{{ asset('assets/pos/pos.css') }}">
</head>
<body class="pos">
<header class="topbar">
  <div class="brand"><i></i>Rasentra POS</div>
  <div class="topbar-info" id="outlet-info">Memuat…</div>
  <div class="topbar-actions">
    <span class="status-pill" id="net-status" title="Status server lokal">LAN</span>
    <button class="btn ghost" data-action="held">Transaksi ditahan <span class="badge" id="held-count">0</span></button>
    <button class="btn ghost" data-action="shift">Shift</button>
    <form method="post" action="{{ route('pos.logout') }}">@csrf<button class="btn ghost">Keluar</button></form>
  </div>
</header>

<main class="workspace">
  <section class="catalog">
    <div class="scanbar">
      <input id="scan" class="scan-input" placeholder="Pindai Pack ID / barcode, atau ketik kode lalu Enter" autocomplete="off" inputmode="search">
      <button class="btn" data-action="scan-submit">Tambah</button>
    </div>
    <nav class="chips" id="categories"></nav>
    <div class="grid" id="products"></div>
  </section>

  <aside class="cart">
    <div class="cart-head">
      <div>
        <div class="cart-title">Pesanan <span id="order-no" class="mono muted"></span></div>
        <div class="segmented" id="channel">
          <button data-channel="retail" class="on">Retail</button>
          <button data-channel="dine_in">Dine-in</button>
          <button data-channel="takeaway">Takeaway</button>
        </div>
      </div>
      <button class="btn ghost" data-action="new">Baru</button>
    </div>
    <ol class="lines" id="lines"><li class="empty">Pindai produk atau sentuh menu untuk mulai.</li></ol>
    <div class="totals">
      <div><span>Subtotal</span><b class="mono" id="t-gross">0</b></div>
      <div class="disc"><span>Diskon promo</span><b class="mono" id="t-disc">0</b></div>
      <div class="grand"><span>Total</span><b class="mono" id="t-net">Rp0</b></div>
    </div>
    <div class="cart-actions">
      <button class="btn xl soft" data-action="hold">Tahan</button>
      <button class="btn xl soft" data-action="kitchen" id="btn-kitchen" hidden>Kirim dapur</button>
      <button class="btn xl primary" data-action="pay" id="btn-pay">Bayar</button>
    </div>
  </aside>
</main>

<div class="sheet" id="sheet" hidden>
  <div class="sheet-body" role="dialog" aria-modal="true">
    <header class="sheet-head"><h2 id="sheet-title"></h2><button class="btn ghost" data-action="close">Tutup</button></header>
    <div id="sheet-content"></div>
  </div>
</div>

<div class="toast" id="toast" hidden></div>
<script src="{{ asset('assets/pos/pos.js') }}" defer></script>
</body>
</html>
