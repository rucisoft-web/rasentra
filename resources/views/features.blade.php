<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fitur Aplikasi · Rasentra</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Source+Sans+3:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap">
<link rel="stylesheet" href="{{ asset('assets/site/doc.css') }}">
</head>
@php
  $layers = ['outlet' => 'Outlet', 'pusat' => 'Kantor pusat', 'sap' => 'SAP B1'];
  $guide = fn (string $heading) => route('panduan').'#'.\Illuminate\Support\Str::slug($heading);
  $img = fn (string $id) => asset("assets/guide/{$id}.jpg");
  $max = max(array_map(fn ($a) => count($a['features']), $apps));
  $short = ['pos' => 'POS', 'mobile' => 'Mobile', 'kitchen' => 'Kitchen', 'inventory' => 'Inventory', 'lokal' => 'Lokal', 'central' => 'Central', 'sap' => 'SAP'];
@endphp
<body class="features">
<header class="top"><div class="wrap">
  <a class="brand" href="{{ route('landing') }}"><i></i>Rasentra</a>
  <nav class="links" aria-label="Navigasi">
    <a href="{{ route('landing') }}">Beranda</a>
    <a href="{{ route('panduan') }}">Panduan Peran</a>
    @if (config('rasentra.demo.enabled'))<a class="btn" href="{{ route('demo') }}">Coba demo</a>@endif
  </nav>
</div></header>

<nav class="appnav" aria-label="Aplikasi"><div class="wrap">
  @foreach ($apps as $a)<a href="#{{ $a['key'] }}" class="l-{{ $a['layer'] }}">{{ $a['name'] }}</a>@endforeach
</div></nav>

<main class="wrap fx">
  <section class="fx-hero">
    <p class="eyebrow">Fitur aplikasi</p>
    <h1>Semua perangkat lunak Rasentra dan fiturnya</h1>
    <p class="lead">Rasentra terdiri dari aplikasi outlet di jaringan lokal, server outlet Rasentra Lokal, Rasentra Central di kantor pusat, dan integrasi ke SAP Business One milik setiap CV. Sentuh nama aplikasi di gambar arsitektur atau di menu atas untuk melihat fiturnya.</p>
    <div class="stats">
      <div><b>{{ count($apps) }}</b><span>komponen perangkat lunak</span></div>
      <div><b>{{ $total }}</b><span>fitur yang sudah berjalan</span></div>
      <div><b>{{ count($roles) }}</b><span>peran pengguna</span></div>
      <div><b>2</b><span>instance SAP B1 (Solo, Lombok)</span></div>
    </div>
  </section>

  <figure class="arch">
    <a href="{{ $img('arsitektur-sistem') }}" target="_blank" rel="noopener"><img src="{{ $img('arsitektur-sistem') }}" alt="Arsitektur sistem Rasentra: aplikasi outlet, Rasentra Lokal, Rasentra Central dan SAP Business One"></a>
    <figcaption>Arsitektur sistem Rasentra. Aplikasi outlet hanya bergantung pada Rasentra Lokal di LAN, sehingga outlet tetap berjualan saat internet putus.</figcaption>
    <div class="legend">
      @foreach ($apps as $i => $a)<a href="#{{ $a['key'] }}" class="l-{{ $a['layer'] }}"><span>{{ $i + 1 }}</span>{{ $a['name'] }}</a>@endforeach
    </div>
  </figure>

  <section class="charts">
    <article class="chart">
      <h2>Fitur per aplikasi</h2>
      <p class="muted">Jumlah fitur yang sudah berjalan, dikelompokkan menurut lokasi aplikasi.</p>
      @php $rowH = 38; $labelW = 150; $barW = 360; @endphp
      <svg class="bars" viewBox="0 0 {{ $labelW + $barW + 40 }} {{ count($apps) * $rowH + 6 }}" role="img" aria-label="Jumlah fitur per aplikasi">
        @foreach ($apps as $i => $a)
          @php $n = count($a['features']); $w = max(6, round($barW * $n / $max)); $y = $i * $rowH; @endphp
          <a href="#{{ $a['key'] }}">
            <text x="{{ $labelW - 10 }}" y="{{ $y + 22 }}" text-anchor="end" class="lbl">{{ $short[$a['key']] ?? $a['name'] }}</text>
            <rect x="{{ $labelW }}" y="{{ $y + 6 }}" width="{{ $w }}" height="24" rx="6" class="bar l-{{ $a['layer'] }}"/>
            <text x="{{ $labelW + $w + 8 }}" y="{{ $y + 23 }}" class="val">{{ $n }}</text>
          </a>
        @endforeach
      </svg>
      <div class="keys">@foreach ($layers as $k => $t)<span class="l-{{ $k }}"><i></i>{{ $t }}</span>@endforeach</div>
    </article>

    <article class="chart">
      <h2>Perjalanan satu penjualan</h2>
      <p class="muted">Dari kasir sampai pembukuan SAP, tanpa input ulang.</p>
      <ol class="journey">
        <li class="l-outlet"><b>Rasentra POS</b><span>Kasir scan Pack ID, terima pembayaran, cetak struk.</span></li>
        <li class="l-outlet"><b>Rasentra Lokal</b><span>Struk tersimpan di server outlet dan masuk antrean kirim. Internet putus pun aman.</span></li>
        <li class="l-pusat"><b>Sinkronisasi</b><span>Rasentra Central menerima event, menolak duplikat, memperbarui laporan.</span></li>
        <li class="l-sap"><b>Posting SAP</b><span>A/R Invoice dan Incoming Payment di SAP milik CV outlet.</span></li>
        <li class="l-pusat"><b>Rekonsiliasi</b><span>Tutup hari outlet dicocokkan dengan data pusat oleh keuangan.</span></li>
      </ol>
    </article>

    <article class="chart wide">
      <h2>Siapa memakai apa</h2>
      <p class="muted">Setiap peran masuk dengan akun sendiri dan hanya melihat aplikasi yang dibutuhkan.</p>
      <div class="matrix-wrap">
        <table class="matrix">
          <thead><tr><th>Peran</th>@foreach ($apps as $a)<th class="l-{{ $a['layer'] }}">{{ $short[$a['key']] ?? $a['name'] }}</th>@endforeach</tr></thead>
          <tbody>
          @foreach ($roles as $role => $uses)
            <tr><th>{{ $role }}</th>@foreach ($apps as $a)<td>@if (in_array($a['key'], $uses, true))<i class="dot l-{{ $a['layer'] }}" aria-label="memakai {{ $a['name'] }}"></i>@endif</td>@endforeach</tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </article>
  </section>

  @foreach ($apps as $i => $a)
    <section class="app l-{{ $a['layer'] }}" id="{{ $a['key'] }}">
      <header class="app-head">
        <span class="num">{{ $i + 1 }}</span>
        <div>
          <p class="eyebrow">{{ $layers[$a['layer']] }} · {{ count($a['features']) }} fitur</p>
          <h2>{{ $a['name'] }}</h2>
          <p class="summary">{{ $a['summary'] }}</p>
          <p class="meta"><span>Perangkat: {{ $a['where'] }}</span><span>Pengguna: {{ $a['users'] }}</span></p>
        </div>
      </header>
      <div class="fgrid">
        @foreach ($a['features'] as [$title, $desc, $heading])
          <article class="feat"><h3>{{ $title }}</h3><p>{{ $desc }}</p><a href="{{ $guide($heading) }}">Cara pakai: {{ \Illuminate\Support\Str::before($heading, ' ') }} ›</a></article>
        @endforeach
      </div>
      <div class="shots">
        @foreach ($a['shots'] as [$id, $caption])
          <figure><a href="{{ $img($id) }}" target="_blank" rel="noopener"><img src="{{ $img($id) }}" alt="{{ $a['name'] }}: {{ $caption }}" loading="lazy"></a><figcaption>{{ $caption }}</figcaption></figure>
        @endforeach
      </div>
    </section>
  @endforeach

  <p class="fx-end">Langkah lengkap per peran ada di <a href="{{ route('panduan') }}">Panduan Peran</a>.@if (config('rasentra.demo.enabled')) Coba langsung di <a href="{{ route('demo') }}">mode tamu</a>.@endif</p>
</main>
</body>
</html>
