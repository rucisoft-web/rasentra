<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coba Rasentra · Mode tamu</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Source+Sans+3:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap">
<style>
:root{
  --bg:#f2f5f3; --surface:#fff; --ink:#15261e; --muted:#53645b; --line:#d3ddd7;
  --green:#1f5a40; --green-soft:#e3efe8; --amber:#e3a21a; --amber-soft:#fff3d6; --red:#a8322d;
  --display:"Bricolage Grotesque", "Segoe UI", system-ui, sans-serif;
  --body:"Source Sans 3", "Segoe UI", system-ui, sans-serif;
  --mono:"JetBrains Mono", ui-monospace, Menlo, monospace;
}
@media (prefers-color-scheme: dark){:root{
  --bg:#0f1713; --surface:#16211b; --ink:#e6eee9; --muted:#9fb1a7; --line:#2a3a31;
  --green:#6fc49a; --green-soft:#1c3328; --amber:#f0b73c; --amber-soft:#2d2410; --red:#e2716b; color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--body);font-size:17px;line-height:1.55}
.wrap{max-width:1120px;margin:0 auto;padding:0 16px}
header{background:var(--surface);border-bottom:1px solid var(--line)}
header .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:68px}
.brand{font-family:var(--display);font-weight:800;font-size:1.25rem;color:var(--ink);text-decoration:none;display:flex;align-items:center;gap:10px}
.brand i{display:inline-block;width:34px;height:34px;background:url({{ asset('assets/brand/rasentra-mark.png') }}) center/contain no-repeat}
header a.back{color:var(--green);font-weight:600;text-decoration:none}
.intro{padding:48px 0 24px;display:grid;gap:12px;max-width:760px}
.eyebrow{font-family:var(--mono);font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
h1{font-family:var(--display);font-size:clamp(2rem,4.5vw,3rem);line-height:1.08;margin:0;letter-spacing:-.01em}
h2{font-family:var(--display);font-size:1.3rem;margin:32px 0 12px}
.lead{color:var(--muted);margin:0}
.notice{background:var(--amber-soft);border-left:4px solid var(--amber);padding:12px 16px;border-radius:8px;font-size:.95rem}
.alert{background:#fbe9e7;color:var(--red);padding:12px 16px;border-radius:8px;font-weight:600}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px;display:flex;flex-direction:column;gap:8px}
.card .app{font-family:var(--mono);font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:var(--green)}
.card h3{margin:0;font-family:var(--display);font-size:1.2rem}
.card p{margin:0;color:var(--muted);font-size:.95rem;flex:1}
.card form{margin-top:6px}
.btn{width:100%;display:inline-flex;justify-content:center;align-items:center;gap:8px;padding:12px 18px;border-radius:10px;font:inherit;font-weight:700;border:2px solid var(--green);background:var(--green);color:var(--surface);cursor:pointer}
.btn:hover{filter:brightness(1.08)}
.btn:focus-visible{outline:3px solid var(--amber);outline-offset:2px}
.tips{margin:36px 0 56px;color:var(--muted);font-size:.95rem}
.tips li{margin:4px 0}
.mono{font-family:var(--mono)}
</style>
</head>
<body>
<header><div class="wrap">
  <a class="brand" href="{{ route('landing') }}"><i></i>Rasentra</a>
  <a class="back" href="{{ route('landing') }}">‹ Kembali ke beranda</a>
</div></header>

<main class="wrap">
  <section class="intro">
    <p class="eyebrow">Mode tamu · data contoh Indoguna Group</p>
    <h1>Coba aplikasi Rasentra tanpa login</h1>
    <p class="lead">Pilih aplikasi dan peran, lalu tekan <b>Masuk</b>. Anda langsung masuk dengan akun contoh dan bisa mencoba transaksi, dapur, gudang sampai laporan kantor pusat.</p>
    <p class="notice">Semua data di sini fiktif dan dibangun ulang setiap hari pukul <span class="mono">{{ $resetAt }}</span> WIB. Perubahan pengguna, outlet dan pemasangan server dinonaktifkan untuk tamu.</p>
    @if (session('error'))<p class="alert">{{ session('error') }}</p>@endif
  </section>

  @php
    $groups = [
        'Kantor pusat · Rasentra Central' => ['central', 'finance', 'merch'],
        'Outlet Solo · Meatshop & Grocery dan restoran R&B' => ['pos-retail', 'pos-resto', 'mobile', 'kitchen', 'inventory', 'lokal'],
    ];
  @endphp
  @foreach ($groups as $title => $keys)
    <h2>{{ $title }}</h2>
    <div class="grid">
      @foreach ($keys as $key)
        @continue(! isset($accounts[$key]))
        @php $a = $accounts[$key]; @endphp
        <article class="card">
          <span class="app">{{ $a['app'] }}</span>
          <h3>{{ $a['role'] }}</h3>
          <p>{{ $a['note'] }}</p>
          <form method="post" action="{{ route('demo.enter', $key) }}">@csrf<button class="btn">Masuk sebagai {{ strtolower($a['role']) }}</button></form>
        </article>
      @endforeach
    </div>
  @endforeach

  <ul class="tips">
    <li>Untuk berpindah aplikasi, tekan tombol <b>Mode tamu</b> di pojok layar atau buka kembali halaman ini.</li>
    <li>Coba alur restoran: pesan dari <b>Mobile Terminal</b>, lihat tiketnya di <b>Kitchen</b>, lalu bayar di <b>POS kasir restoran</b>.</li>
    <li>Persetujuan yang meminta PIN memakai kepala outlet Hendra, PIN <span class="mono">9999</span>.</li>
  </ul>
</main>
</body>
</html>
