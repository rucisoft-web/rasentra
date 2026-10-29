<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1">
@verbatim
<title>Rasentra · Indoguna Group</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Source+Sans+3:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap">
<style>
/* Layout: lembar kerja toko daging. Hero = label timbang; seksi bergaris seperti struk; diagram alur dalam bingkai. */
:root{
  --bg:#f2f5f3; --surface:#ffffff; --ink:#15261e; --muted:#53645b; --line:#d3ddd7;
  --green:#1f5a40; --green-soft:#e3efe8; --amber:#e3a21a; --amber-ink:#5a3d00; --red:#a8322d;
  --display:"Bricolage Grotesque", "Segoe UI", system-ui, sans-serif;
  --body:"Source Sans 3", "Segoe UI", system-ui, sans-serif;
  --mono:"JetBrains Mono", ui-monospace, "Cascadia Mono", Menlo, monospace;
}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){
  --bg:#0f1713; --surface:#16211b; --ink:#e6eee9; --muted:#9fb1a7; --line:#2a3a31;
  --green:#6fc49a; --green-soft:#1c3328; --amber:#f0b73c; --amber-ink:#1d1300; --red:#e2716b; color-scheme:dark}}
:root[data-theme="dark"]{
  --bg:#0f1713; --surface:#16211b; --ink:#e6eee9; --muted:#9fb1a7; --line:#2a3a31;
  --green:#6fc49a; --green-soft:#1c3328; --amber:#f0b73c; --amber-ink:#1d1300; --red:#e2716b; color-scheme:dark}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
@media (prefers-reduced-motion: reduce){html{scroll-behavior:auto}}
body{background:var(--bg);color:var(--ink);font-family:var(--body);font-size:17px;line-height:1.6}
body::before{content:"";position:fixed;inset:0 0 auto;height:env(safe-area-inset-top,0px);z-index:10;background:var(--surface)}
.wrap{max-width:1120px;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px))}
h1,h2,h3{font-family:var(--display);line-height:1.1;text-wrap:balance;margin:0}
h2{font-size:clamp(1.8rem,3.5vw,2.6rem);font-weight:800;letter-spacing:-.01em}
h3{font-size:1.2rem;font-weight:700}
p{margin:0}
a{color:var(--green)}
a:focus-visible,button:focus-visible,summary:focus-visible{outline:3px solid var(--amber);outline-offset:2px;border-radius:4px}
.mono{font-family:var(--mono);font-variant-numeric:tabular-nums}
.eyebrow{font-family:var(--mono);font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}

/* nav */
header.top{position:sticky;top:env(safe-area-inset-top,0px);z-index:20;background:var(--surface);border-bottom:1px solid var(--line)}
header.top .wrap{display:flex;align-items:center;gap:24px;min-height:60px;flex-wrap:wrap}
.brand{font-family:var(--display);font-weight:800;font-size:1.25rem;color:var(--ink);text-decoration:none;display:flex;align-items:center;gap:10px}
.brand i{display:inline-block;flex:none;width:34px;height:34px;background:url(/assets/brand/rasentra-mark.png) center/contain no-repeat}
nav.menu{display:flex;gap:4px;margin-left:auto;flex-wrap:wrap;align-items:center}
nav.menu > a, nav.menu summary{font-weight:600;font-size:.95rem;color:var(--ink);text-decoration:none;padding:8px 12px;border-radius:6px;cursor:pointer;list-style:none}
nav.menu > a:hover, nav.menu summary:hover{background:var(--green-soft)}
nav.menu summary::-webkit-details-marker{display:none}
nav.menu summary::after{content:"▾";margin-left:6px;font-size:.8em;color:var(--muted)}
details.sub{position:relative}
details.sub .drop{position:absolute;right:0;top:calc(100% + 6px);background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:6px;min-width:250px;box-shadow:0 10px 30px rgba(0,0,0,.12);display:grid;gap:2px}
details.sub .drop a{display:block;padding:9px 12px;border-radius:6px;text-decoration:none;color:var(--ink)}
details.sub .drop a small{display:block;color:var(--muted);font-size:.82rem}
details.sub .drop a:hover{background:var(--green-soft)}
@media (max-width:640px){details.sub .drop{left:0;right:auto}}

/* hero */
.hero{padding-block:64px 56px}
.hero .wrap{display:grid;grid-template-columns:1.15fr .85fr;gap:48px;align-items:center}
.hero h1{font-size:clamp(2.3rem,5.4vw,4.1rem);font-weight:800;letter-spacing:-.02em}
.hero h1 em{font-style:normal;color:var(--green)}
.hero .lead{margin-top:20px;font-size:1.15rem;color:var(--muted);max-width:56ch}
.cta{display:flex;gap:12px;margin-top:28px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border-radius:8px;font-weight:700;text-decoration:none;border:2px solid var(--green)}
.btn.primary{background:var(--green);color:var(--surface)}
.btn.ghost{color:var(--green)}
.facts{display:flex;gap:28px;margin-top:36px;flex-wrap:wrap}
.facts div{display:grid}
.facts b{font-family:var(--display);font-size:1.8rem;line-height:1}
.facts span{font-size:.85rem;color:var(--muted)}
/* label timbang */
.label{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:22px 22px 18px;font-family:var(--mono);font-size:.9rem;box-shadow:0 18px 40px rgba(20,40,30,.12);transform:rotate(-1.5deg);position:relative;max-width:100%}
.label::before{content:"";position:absolute;inset:0 0 auto;height:8px;background:var(--amber);border-radius:6px 6px 0 0}
.label .row{display:flex;justify-content:space-between;gap:12px;padding:5px 0;border-bottom:1px dashed var(--line)}
.label .row:last-of-type{border-bottom:0}
.label .big{font-size:1.6rem;font-weight:600;color:var(--ink)}
.label .k{color:var(--muted)}
.barcode{height:46px;margin-top:12px;background:repeating-linear-gradient(90deg,var(--ink) 0 2px,transparent 2px 4px,var(--ink) 4px 5px,transparent 5px 8px,var(--ink) 8px 11px,transparent 11px 12px)}
.label .pid{text-align:center;letter-spacing:.2em;margin-top:6px}
.stamp{position:absolute;right:-10px;bottom:-14px;background:var(--amber);color:var(--amber-ink);font-weight:600;padding:6px 10px;border-radius:4px;transform:rotate(4deg);font-size:.8rem}

section.block{padding-block:72px;border-top:1px solid var(--line)}
.sec-head{display:grid;gap:12px;max-width:720px;margin-bottom:36px}
.sec-head p{color:var(--muted);font-size:1.08rem}

/* fitur */
.feat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.feat{background:var(--surface);padding:26px 24px;display:grid;gap:10px;align-content:start;min-width:0}
.feat .tag{font-family:var(--mono);font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:var(--green);background:var(--green-soft);padding:3px 8px;border-radius:4px;justify-self:start}
.feat p{color:var(--muted);font-size:.98rem}
.feat ul{margin:4px 0 0;padding-left:18px;font-size:.95rem;display:grid;gap:4px}
@media (max-width:900px){.feat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:600px){.feat-grid{grid-template-columns:1fr}.hero .wrap{grid-template-columns:1fr}.label{transform:none}}

/* contoh hitung */
.calc{margin-top:40px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}
.receipt{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:22px;font-family:var(--mono);font-size:.9rem;min-width:0}
.receipt h3{font-family:var(--mono);font-size:.82rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:12px}
.receipt .ln{display:flex;justify-content:space-between;gap:12px;padding:4px 0}
.receipt .tot{border-top:2px solid var(--ink);margin-top:8px;padding-top:8px;font-weight:600}
.receipt .note{font-family:var(--body);color:var(--muted);font-size:.88rem;margin-top:10px}
@media (max-width:760px){.calc{grid-template-columns:1fr}}

/* alur */
.flow{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:32px;margin-top:40px;align-items:start}
.flow + .flow{padding-top:40px;border-top:1px dashed var(--line)}
.diagram{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:16px;overflow-x:auto;min-width:0}
.diagram pre.mermaid{margin:0;background:transparent;font-family:var(--body);display:flex;justify-content:center}
.steps{display:grid;gap:14px;counter-reset:s;min-width:0}
.steps h3{margin-bottom:4px}
.steps > p{color:var(--muted)}
.steps ol{margin:0;padding:0;list-style:none;display:grid;gap:10px}
.steps li{counter-increment:s;display:grid;grid-template-columns:30px 1fr;gap:10px;font-size:.97rem}
.steps li::before{content:counter(s);font-family:var(--mono);font-weight:600;width:26px;height:26px;border-radius:50%;background:var(--green-soft);color:var(--green);display:grid;place-items:center;font-size:.8rem}
.subnav{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.subnav a{font-family:var(--mono);font-size:.8rem;padding:6px 10px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--ink);background:var(--surface)}
.subnav a:hover{border-color:var(--green)}
@media (max-width:900px){.flow{grid-template-columns:1fr}}

/* panduan */
.roles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}
.role{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:24px;display:grid;gap:12px;align-content:start;min-width:0}
.role header{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.role header span{font-family:var(--mono);font-size:.78rem;color:var(--muted)}
.role ol{margin:0;padding-left:20px;display:grid;gap:6px;font-size:.96rem}
.role .tip{font-size:.9rem;background:var(--green-soft);border-radius:8px;padding:10px 12px}
@media (max-width:760px){.roles{grid-template-columns:1fr}}

/* arsitektur */
.tbl{overflow-x:auto;border:1px solid var(--line);border-radius:12px;background:var(--surface)}
table{border-collapse:collapse;width:100%;font-size:.95rem;min-width:620px}
th,td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-family:var(--mono);font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600}
tr:last-child td{border-bottom:0}
.outlets{display:flex;flex-wrap:wrap;gap:8px;margin-top:24px}
.outlets span{font-size:.86rem;padding:5px 10px;border-radius:6px;border:1px solid var(--line);background:var(--surface)}
.outlets span b{font-family:var(--mono);font-weight:600;color:var(--green);margin-right:6px;font-size:.78rem}
footer{padding-block:40px;border-top:1px solid var(--line);color:var(--muted);font-size:.9rem}
footer .wrap{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}
</style>
@endverbatim
</head>
<body>
@verbatim


<header class="top">
  <div class="wrap">
    <a class="brand" href="#top"><i></i>Rasentra</a>
    <nav class="menu" aria-label="Menu utama">
      <a href="#produk">Produk</a>
      <a href="#fitur">Fitur</a>
      <details class="sub" id="menu-alur">
        <summary>Alur Kerja</summary>
        <div class="drop">
          <a href="#alur-retail">Retail / Meatshop<small>Penjualan Pack ID, penerimaan, opname, retur</small></a>
          <a href="#alur-resto">Restoran<small>Dine-in & KDS, cooking fee, produksi resep</small></a>
          <a href="#alur-sync">Pusat & SAP B1<small>Sinkronisasi, promo, posting</small></a>
        </div>
      </details>
      <a href="#panduan">Panduan</a>
      <a href="#arsitektur">Arsitektur</a>
    </nav>
  </div>
</header>

<main id="top">
<section class="hero">
  <div class="wrap">
    <div>
      <p class="eyebrow">Rasentra · meatshop, grocery & restoran · Indoguna Group</p>
      <h1>Setiap kemasan daging <em>tercatat sampai gram</em>, setiap outlet tetap jualan walau internet putus.</h1>
      <p class="lead">Rasentra adalah platform Laravel 13 dan MySQL untuk Indoguna Group: CV Solo Kulina Utama (Meatshop & Grocery dan restoran R&amp;B Solo) dan CV Lombok Kulina Utama (Meatshop dan restoran El Primo), masing-masing dengan SAP Business One sendiri. Rasentra Lokal, server di setiap outlet, melayani Rasentra POS di kasir, Rasentra Mobile Terminal di tablet pelayan, Rasentra Inventory di handheld untuk cek inventory, serta Rasentra Kitchen di layar dapur. Di kantor pusat, Rasentra Central menjadi pusat POS dengan tiga modul: Sinkronisasi menerima transaksi semua outlet, Posting SAP memposting ke SAP Business One tiap CV, dan Rasentra Core tempat admin mengatur master, harga, promo dan resep.</p>
      <div class="cta">
        <a class="btn primary" href="#alur-retail">Lihat alur kerja</a>
        <a class="btn ghost" href="#panduan">Panduan penggunaan</a>
      </div>
      <div class="facts">
        <div><b class="mono">20</b><span>kapasitas outlet</span></div>
        <div><b class="mono">≤1 dtk</b><span>scan Pack ID (p95)</span></div>
        <div><b class="mono">≤2 dtk</b><span>order sampai KDS</span></div>
        <div><b class="mono">7 hari</b><span>antrean offline</span></div>
      </div>
    </div>
    <div class="label" aria-label="Contoh label kemasan daging">
      <div class="row"><span class="k">PRODUK</span><span>SIRLOIN AUS</span></div>
      <div class="row"><span class="k">BERAT BERSIH</span><span class="big">1,500 kg</span></div>
      <div class="row"><span class="k">HARGA/KG</span><span>Rp200.000</span></div>
      <div class="row"><span class="k">HARGA</span><span class="big">Rp300.000</span></div>
      <div class="row"><span class="k">KEMAS · EXP</span><span>27-10-26 · 03-11-26</span></div>
      <div class="barcode" aria-hidden="true"></div>
      <div class="pid">PK-000184-7Q</div>
      <span class="stamp">1 Pack ID = 1 kemasan unik</span>
    </div>
  </div>
</section>

<section class="block" id="produk">
  <div class="wrap">
    <div class="sec-head">
      <p class="eyebrow">Keluarga produk</p>
      <h2>Delapan aplikasi, satu data</h2>
      <p>Setiap perangkat di outlet membuka aplikasi yang sesuai tugasnya. Semuanya terhubung ke Rasentra Lokal di outlet, dan Rasentra Lokal tersinkron ke Rasentra Central di kantor pusat.</p>
    </div>
    <div class="feat-grid">
      <article class="feat"><span class="tag">Kasir</span><h3>Rasentra POS</h3><p>Layar sentuh kasir retail dan resto: scan Pack ID, keranjang, promo, pembayaran, retur, shift dan closing. Menu supervisor untuk approval kepala outlet.</p></article>
      <article class="feat"><span class="tag">Tablet pelayan</span><h3>Rasentra Mobile Terminal</h3><p>Buka meja, catat order dengan modifier dan course, kirim ke dapur, pantau status sajian, dan setujui pesanan dari QR meja.</p></article>
      <article class="feat"><span class="tag">Dapur</span><h3>Rasentra Kitchen</h3><p>Kitchen Display per station (grill, hot, cold, bar, pastry, packing) dengan timer berwarna, bump lewat sentuh atau scan barcode tiket, layar expeditor, layar antrean pickup, produksi batch, yield dan waste.</p></article>
      <article class="feat"><span class="tag">Server outlet</span><h3>Rasentra Lokal</h3><p>Server lokal di setiap outlet, pusat semua perangkat POS: menyimpan transaksi, Pack ID, stok dan antrean dapur, menjalankan promo, dan tetap bekerja penuh saat internet putus. Data dikirim ke Rasentra Central begitu koneksi kembali. Kepala outlet memantau antrean kirim, tutup hari, pairing dan backup dari konsol <code>/lokal</code>.</p></article>
      <article class="feat"><span class="tag">Rasentra Central</span><h3>Sinkronisasi</h3><p>Modul pertama Rasentra Central, pusat POS di kantor pusat. Menerima transaksi dari semua outlet tanpa data ganda, menyebarkan versi harga, promo, resep dan master ke outlet, menyiapkan snapshot untuk outlet baru, serta memantau heartbeat, tutup hari dan rekonsiliasi harian sehingga tidak ada struk yang hilang.</p></article>
      <article class="feat"><span class="tag">Rasentra Central</span><h3>Posting SAP</h3><p>Memposting penjualan, retur dan penyesuaian stok ke SAP Business One milik tiap CV (sap_solo, sap_lombok) lewat Service Layer, tanpa posting ganda. Dokumen yang perlu review, gagal atau parsial bisa diposting ulang, dan mapping item, gudang serta akun diatur per instance.</p></article>
      <article class="feat"><span class="tag">Rasentra Central</span><h3>Rasentra Core</h3><p>Admin kantor pusat: master perusahaan, outlet, produk dan pengguna, versi harga, Promotion Designer, resep dan cooking fee, laporan grup serta audit log. Halaman Status Central merangkum ketiga modul. <a href="/central">Buka Rasentra Central</a></p></article>
      <article class="feat"><span class="tag">Tamu</span><h3>QR meja & kiosk</h3><p>Menu untuk tamu lewat token meja atau kiosk, pada jaringan tamu terpisah. Pesanan masuk setelah disetujui pelayan.</p></article>
      <article class="feat"><span class="tag">Handheld inventory</span><h3>Rasentra Inventory</h3><p>Cek inventory dengan scanner barcode/QR: penerimaan barang, stok opname blind count, cek saldo per lokasi dan Pack ID. Bekerja lewat Wi-Fi outlet walau internet putus.</p></article>
    </div>
  </div>
</section>

<section class="block" id="fitur">
  <div class="wrap">
    <div class="sec-head">
      <p class="eyebrow">Fitur</p>
      <h2>Satu sistem untuk meatshop, restoran dan gudang pusat</h2>
      <p>Fitur dikelompokkan sesuai cara kerja outlet. Semua fungsi inti berjalan di Rasentra Lokal (server outlet); fungsi yang butuh pusat (voucher global, pembayaran daring, posting SAP) ditandai jelas statusnya.</p>
    </div>
    <div class="feat-grid">
      <article class="feat"><span class="tag">Daging timbang</span><h3>Pack ID & berat aktual</h3><p>Setiap kemasan ditimbang di gudang pusat dan diberi Pack ID unik dengan berat pecahan seperti 1,500 kg atau 1,750 kg.</p><ul><li>Satu Pack ID hanya bisa terjual sekali, walau dua kasir memindai bersamaan</li><li>Blokir pack salah outlet, belum diterima, kedaluwarsa atau ditahan</li><li>Repack dengan neraca berat dan alasan susut</li></ul></article>
      <article class="feat"><span class="tag">Kasir retail</span><h3>Kasir cepat untuk barang satuan dan timbang</h3><p>Scan barcode SKU atau Pack ID, tahan dan lanjutkan transaksi, retur mengacu struk asal.</p><ul><li>Tunai, QRIS dinamis, EDC, voucher dan pembayaran campuran</li><li>Override harga dengan PIN penyetuju dan alasan</li><li>Cetak ulang struk memakai nomor yang sama</li></ul></article>
      <article class="feat"><span class="tag">Restoran</span><h3>Meja, course dan Kitchen Display</h3><p>Denah meja, pindah dan gabung meja, modifier kematangan, saus, alergi, lalu routing otomatis ke station.</p><ul><li>KDS untuk grill, hot, cold, bar, pastry dan packing</li><li>Expeditor memantau kelengkapan dan waktu tunggu</li><li>Split bill per item atau kuantitas</li></ul></article>
      <article class="feat"><span class="tag">Rasentra Kitchen</span><h3>Order management di dapur</h3><p>Tiket dari POS, Mobile Terminal, QR meja dan kiosk masuk ke station yang tepat secara otomatis. Dapur bekerja dari layar, bukan kertas.</p><ul><li>Timer per tiket: hijau, kuning lalu merah saat melewati target waktu saji</li><li>Bump dengan sentuh atau scan barcode tiket; status Baru, Dimasak, Siap, Disajikan</li><li>Expeditor melihat kelengkapan per meja sebelum makanan keluar</li><li>Layar antrean pickup untuk takeaway dan kiosk: nomor dipanggil saat siap</li><li>Analisis waktu saji per station, menu dan jam sibuk</li><li>Bahasa layar Indonesia atau Inggris per station</li></ul></article>
      <article class="feat"><span class="tag">Cooking fee</span><h3>Pilih daging, dimasak di restoran</h3><p>Pelanggan memilih kemasan di etalase lalu minta dimasak. Harga daging dan jasa memasak tampil sebagai baris terpisah.</p><ul><li>Tarif per kg mentah, per pack, per porsi atau bertingkat</li><li>Daging sudah dibayar di kasir? Resto hanya menagih jasa</li><li>Satu pack bisa dibagi beberapa porsi</li></ul></article>
      <article class="feat"><span class="tag">Resep & produksi</span><h3>Konsumsi bahan sesuai resep berversi</h3><p>Resep per menu dengan yield, subresep dan modifier. Produksi batch saus, kaldu atau potongan steak dicatat dengan hasil aktual.</p><ul><li>Konsumsi teoretis vs aktual</li><li>Waste, complimentary, makan karyawan dan remake dipisah</li><li>Versi resep lama tidak mengubah histori</li></ul></article>
      <article class="feat"><span class="tag">Promosi</span><h3>Promotion Designer di pusat, engine di outlet</h3><p>Diskon persen/nominal, harga khusus, beli X gratis Y, bundling, promo berat bertingkat, voucher dan membership.</p><ul><li>Target: semua outlet, perusahaan/wilayah (mis. Lombok) atau outlet tertentu</li><li>Simulasi keranjang sebelum publikasi</li><li>Pusat melihat outlet mana yang sudah menerima versi promo</li></ul></article>
      <article class="feat"><span class="tag">Inventory</span><h3>Stok opname dengan handheld</h3><p>Pindai QR lokasi, lalu produk atau Pack ID. Blind count menyembunyikan saldo sistem dari petugas hitung.</p><ul><li>Recount otomatis oleh petugas berbeda bila selisih melewati ambang</li><li>Ledger stok append-only per lokasi, batch dan Pack ID</li><li>Penerimaan barang parsial dan stok dalam perjalanan</li></ul></article>
      <article class="feat"><span class="tag">Kas & shift</span><h3>Shift, laci kas dan closing</h3><p>Saldo awal, cash in/out beralasan, hitungan aktual per metode bayar dan approval selisih.</p><ul><li>Closing lokal tetap bisa dilakukan tanpa pusat</li><li>Alarm anomali: void, refund, buka laci, selisih kas</li><li>Jejak audit tidak bisa dihapus kasir</li></ul></article>
      <article class="feat"><span class="tag">Integrasi</span><h3>Sinkronisasi pusat dan SAP Business One</h3><p>Transaksi dikirim lewat antrean yang tahan putus koneksi, lalu diposting ke SAP milik CV pemilik outlet (sap_solo atau sap_lombok) sebagai A/R Invoice, Incoming Payment dan dokumen stok.</p><ul><li>Tidak ada dokumen ganda saat kirim ulang</li><li>Adapter XML MSI/MSR untuk jalur lama</li><li>Dashboard rekonsiliasi harian untuk Finance</li></ul></article>
    </div>

    <div class="calc">
      <div class="receipt">
        <h3>Contoh struk retail · 2 Pack ID</h3>
        <div class="ln"><span>Sirloin PK-000184 · 1,500 kg</span><span>300.000</span></div>
        <div class="ln"><span>Sirloin PK-000191 · 1,750 kg</span><span>350.000</span></div>
        <div class="ln tot"><span>Bruto · 3,250 kg</span><span>Rp650.000</span></div>
        <p class="note">Harga Rp200.000/kg. Stok berkurang 3,250 kg dan kedua Pack ID berstatus terjual. Pembulatan berlaku pada rupiah, bukan pada berat.</p>
      </div>
      <div class="receipt">
        <h3>Contoh tagihan cooking fee</h3>
        <div class="ln"><span>Daging PK-000184 · 1,500 kg</span><span>300.000</span></div>
        <div class="ln"><span>Cooking fee 1,500 kg × 40.000</span><span>60.000</span></div>
        <div class="ln"><span>Kentang tambahan 2 × 25.000</span><span>50.000</span></div>
        <div class="ln tot"><span>Subtotal</span><span>Rp410.000</span></div>
        <p class="note">Sebelum pajak dan service charge. Daging keluar dari stok sekali; resep jasa hanya memotong butter, bumbu dan saus.</p>
      </div>
    </div>
  </div>
</section>

<section class="block" id="alur">
  <div class="wrap">
    <div class="sec-head">
      <p class="eyebrow">Diagram alur kerja</p>
      <h2>Bagaimana pekerjaan mengalir di outlet</h2>
      <p>Pilih alur yang ingin dipelajari. Setiap diagram disertai langkah penggunaan di aplikasi.</p>
      <div class="subnav">
        <a href="#alur-retail">Retail: penjualan</a><a href="#alur-terima">Retail: penerimaan barang</a><a href="#alur-opname">Retail: stok opname</a><a href="#alur-retur">Retail: retur</a>
        <a href="#alur-resto">Resto: dine-in & KDS</a><a href="#alur-cooking">Resto: cooking fee</a><a href="#alur-produksi">Resto: produksi resep</a><a href="#alur-sync">Pusat & SAP</a>
      </div>
    </div>

    <p class="eyebrow" style="margin-top:24px">Retail / meatshop</p>

    <div class="flow" id="alur-retail">
      <div class="diagram"><pre class="mermaid">
flowchart TD
  A([Buka shift: saldo awal laci]) --> B[Scan barcode SKU atau Pack ID]
  B --> C{Jenis kode?}
  C -- SKU biasa --> D[Tambah kuantitas]
  C -- Pack ID --> E{Pack valid di outlet ini?}
  E -- Tidak: outlet lain / hold / kedaluwarsa --> F[Blokir atau minta approval kepala outlet]
  E -- Ya --> G[Kunci pack, ambil berat aktual dan harga aktif]
  D --> H[Promo engine lokal menghitung diskon]
  G --> H
  H --> I{Pembayaran}
  I -- Tunai --> J[Hitung kembalian]
  I -- QRIS / EDC --> K{Status provider}
  K -- Berhasil --> L
  K -- Tidak pasti --> M[Cek status, jangan minta bayar ulang]
  M --> K
  J --> L[Finalisasi: stok keluar sekali, Pack ID terjual]
  L --> N[Cetak struk, event masuk outbox]
  N --> O([Tutup shift: hitung aktual per metode])
</pre></div>
      <div class="steps">
        <h3>Penjualan kasir retail</h3>
        <p>Dipakai kasir meatshop untuk barang satuan maupun daging timbang berlabel.</p>
        <ol>
          <li>Login dengan akun sendiri, lalu buka shift dan masukkan saldo awal laci.</li>
          <li>Pindai barcode SKU atau Pack ID pada label. Untuk Pack ID, berat dan harga terisi otomatis; berat tidak bisa diubah tanpa otorisasi.</li>
          <li>Bila muncul peringatan "Pack sudah terjual" atau "Pack outlet lain", jangan dipaksa. Panggil kepala outlet untuk approval atau pisahkan barangnya.</li>
          <li>Periksa diskon yang diterapkan promo. Masukkan nomor member atau voucher bila ada.</li>
          <li>Pilih metode bayar. Untuk QRIS, tunggu status berhasil dari sistem, bukan tangkapan layar pelanggan.</li>
          <li>Selesaikan transaksi dan serahkan struk. Jika printer gagal, transaksi tetap tersimpan; gunakan Cetak Ulang.</li>
        </ol>
      </div>
    </div>

    <div class="flow" id="alur-terima">
      <div class="diagram"><pre class="mermaid">
flowchart TD
  A([Outlet ajukan permintaan SKU, jumlah, tanggal perlu]) --> B[Pusat setujui, alokasi stok atau buat PO di SAP]
  B --> C[Gudang pusat timbang dan label Pack ID]
  C --> D[Dokumen transfer dan daftar Pack ID dikirim]
  D --> E[Status: dalam perjalanan, belum tersedia di outlet]
  E --> F[Outlet pindai dokumen dan setiap Pack ID]
  F --> G{Sesuai kiriman?}
  G -- Ya --> H[Konfirmasi terima: stok outlet bertambah]
  G -- Kurang / lebih / rusak --> I[Catat selisih, foto, karantina]
  I --> J[Pusat menyelesaikan selisih]
  H --> K[Integrasi posting transfer ke SAP]
  J --> K
</pre></div>
      <div class="steps">
        <h3>Penerimaan barang dari gudang pusat</h3>
        <p>Dipakai kepala outlet atau petugas gudang outlet.</p>
        <ol>
          <li>Buka menu Penerimaan dan pilih dokumen pengiriman yang berstatus Dalam Perjalanan.</li>
          <li>Pindai setiap Pack ID atau isi jumlah dan berat aktual untuk barang non-pack.</li>
          <li>Tandai barang rusak atau kedaluwarsa sebagai Karantina dan lampirkan foto.</li>
          <li>Simpan sebagai parsial bila kiriman belum lengkap; sisa tetap terbuka.</li>
          <li>Konfirmasi. Sistem tidak menyamakan jumlah secara otomatis, selisih dikirim ke pusat.</li>
        </ol>
      </div>
    </div>

    <div class="flow" id="alur-opname">
      <div class="diagram"><pre class="mermaid">
flowchart TD
  A([Buat sesi: penuh, cycle count atau khusus]) --> B[Tentukan lokasi, petugas, cutoff, blind count]
  B --> C[Bekukan pergerakan stok setelah tutup toko]
  C --> D[Petugas pindai QR lokasi di handheld]
  D --> E[Pindai SKU atau Pack ID, timbang barang curah]
  E --> F{Selisih melewati ambang?}
  F -- Ya --> G[Recount oleh petugas berbeda]
  G --> F
  F -- Tidak --> H[Review: transaksi belum sinkron diperhitungkan]
  H --> I[Kepala outlet / Finance menyetujui]
  I --> J([Posting Inventory Counting ke SAP])
</pre></div>
      <div class="steps">
        <h3>Stok opname dengan handheld</h3>
        <p>Dipakai petugas opname; persetujuan oleh kepala outlet dan Finance.</p>
        <ol>
          <li>Di Rasentra Inventory, pilih sesi opname yang ditugaskan. Saldo sistem tidak ditampilkan pada blind count.</li>
          <li>Pindai QR lokasi rak atau chiller lebih dahulu, baru barangnya.</li>
          <li>Pack ID dihitung sekali per sesi; pindai ulang tidak menambah jumlah.</li>
          <li>Barang curah atau terbuka wajib ditimbang ulang; jangan memakai berat label lama.</li>
          <li>Bila Wi-Fi hilang, aplikasi menahan input dengan pesan jelas. Lanjutkan setelah tersambung.</li>
        </ol>
      </div>
    </div>

    <div class="flow" id="alur-retur">
      <div class="diagram"><pre class="mermaid">
flowchart TD
  A([Pelanggan membawa struk]) --> B[Cari struk asal]
  B --> C[Pilih baris dan kuantitas, maksimal sisa yang belum diretur]
  C --> D[Hitung refund sesuai diskon teralokasi]
  D --> E{Kondisi barang}
  E -- Daging --> F[Karantina]
  F --> G{Keputusan petugas}
  G -- Layak --> H[Kembali ke stok]
  G -- Tidak layak --> I[Waste]
  E -- Barang lain layak --> H
  D --> J[Refund ke metode bayar asal dengan approval]
</pre></div>
      <div class="steps">
        <h3>Retur dan refund</h3>
        <p>Refund uang dan penerimaan kembali barang adalah dua langkah terpisah.</p>
        <ol>
          <li>Buka menu Retur dan pindai nomor struk.</li>
          <li>Pilih item yang diretur. Sistem membatasi jumlah sesuai sisa yang belum diretur.</li>
          <li>Nilai refund dihitung dari harga bersih setelah diskon promo.</li>
          <li>Untuk daging, pilih Karantina; keputusan layak jual atau waste dilakukan sesuai prosedur keamanan pangan.</li>
          <li>Minta approval kepala outlet sesuai limit, lalu proses refund.</li>
        </ol>
      </div>
    </div>

    <p class="eyebrow" style="margin-top:56px">Restoran</p>

    <div class="flow" id="alur-resto">
      <div class="diagram"><pre class="mermaid">
flowchart TD
  A([Buka meja: jumlah tamu, pelayan]) --> B[Catat order: menu, modifier, course, alergi]
  B --> C{Asal order}
  C -- Pelayan / tablet --> D[Konfirmasi order]
  C -- QR meja / kiosk --> E[Pelayan menyetujui] --> D
  D --> F[Reservasi bahan, tiket ke station KDS]
  F --> G[Station ack: grill, hot, cold, bar, pastry]
  G --> H[Mulai masak: konsumsi bahan sesuai resep]
  H --> I[Siap: expeditor cek kelengkapan]
  I --> J[Disajikan]
  J --> K{Tagihan}
  K -- Split / gabung --> L[Bagi per item atau kuantitas]
  K -- Normal --> M[Bayar]
  L --> M
  M --> N([Tutup meja, token kunjungan berakhir])
</pre></div>
      <div class="steps">
        <h3>Pelayanan dine-in dan Rasentra Kitchen</h3>
        <p>Dipakai pelayan, kasir resto, chef dan expeditor.</p>
        <ol>
          <li>Pelayan membuka meja di denah, mengisi jumlah tamu, lalu mencatat pesanan dengan modifier dan course.</li>
          <li>Tekan Kirim ke Dapur. Hanya item baru atau perubahan yang dikirim, jadi dapur tidak memasak ulang.</li>
          <li>Chef menekan Terima pada KDS, lalu Mulai saat memasak dan Siap saat selesai.</li>
          <li>Batal setelah dimasak tercatat sebagai waste dengan alasan; bahan tidak kembali ke stok.</li>
          <li>Kasir membagi tagihan bila perlu, menerima pembayaran, lalu menutup meja.</li>
          <li>Bila KDS terputus, layar menampilkan status station dan sistem mencetak tiket cadangan dengan nomor sama.</li>
          <li>Untuk takeaway dan kiosk, nomor pesanan tampil di layar antrean pickup; pelanggan dipanggil saat semua item Siap.</li>
        </ol>
      </div>
    </div>

    <div class="flow" id="alur-cooking">
      <div class="diagram"><pre class="mermaid">
flowchart TD
  A([Pelanggan memilih kemasan di etalase]) --> B[Scan Pack ID]
  B --> C{Sudah dibayar di kasir retail?}
  C -- Belum --> D[Pilih layanan: masak di restoran]
  D --> E[Pack direservasi untuk order ini]
  C -- Sudah --> F[Scan struk retail: buat hak layanan]
  E --> G[Isi instruksi: meja, porsi, metode, kematangan, saus]
  F --> G
  G --> H[Hitung: daging + cooking fee + tambahan]
  H --> I[KDS menampilkan Pack ID, berat, porsi]
  I --> J[Dapur konfirmasi terima kemasan]
  J --> K[Masak: resep jasa memotong butter, bumbu, saus]
  K --> L[Sajikan]
  L --> M([Bayar: daging dan jasa terpisah di struk])
</pre></div>
      <div class="steps">
        <h3>Pilih daging retail, dimasak di restoran</h3>
        <p>Layanan khas outlet yang punya meatshop dan restoran sekaligus.</p>
        <ol>
          <li>Pindai Pack ID kemasan pilihan pelanggan dan pilih Masak di Restoran.</li>
          <li>Jika pelanggan sudah membayar daging di kasir retail, pindai struknya. Resto hanya menagih jasa dan tambahan.</li>
          <li>Isi jumlah porsi, metode masak, tingkat kematangan, saus dan catatan alergi.</li>
          <li>Periksa rincian: harga daging, cooking fee (dihitung dari berat mentah) dan tambahan muncul sebagai baris terpisah.</li>
          <li>Serahkan kemasan ke dapur; petugas dapur menekan Terima Kemasan di KDS.</li>
          <li>Untuk sebagian dimasak sebagian dibawa pulang, timbang kedua bagian; totalnya harus cocok dengan berat awal.</li>
        </ol>
      </div>
    </div>

    <div class="flow" id="alur-produksi">
      <div class="diagram"><pre class="mermaid">
flowchart TD
  A([Chef menyusun resep versi baru]) --> B[Isi porsi, satuan, yield, bahan, subresep]
  B --> C[Persetujuan dan tanggal efektif]
  C --> D[Distribusi ke outlet yang dituju]
  D --> E[Produksi batch: saus, kaldu, potong steak]
  E --> F[Timbang bahan keluar dan hasil aktual]
  F --> G[Hasil, trimming dan susut tercatat]
  G --> H[Penjualan menu memotong hasil prep, bukan bahan asal]
  H --> I([Laporan teoretis vs aktual dan waste])
</pre></div>
      <div class="steps">
        <h3>Resep dan produksi bahan setengah jadi</h3>
        <p>Dipakai chef kepala dan petugas dapur.</p>
        <ol>
          <li>Buat versi resep baru; versi lama tetap dipakai transaksi sebelumnya.</li>
          <li>Tandai subresep sebagai Stok (dipotong sebagai item jadi) atau Virtual (diuraikan ke bahan).</li>
          <li>Catat produksi dengan berat aktual. Contoh: 10 kg daging menjadi 8 kg steak, 1,5 kg trimming, 0,5 kg susut.</li>
          <li>Catat waste, tasting, complimentary dan makan karyawan pada menu masing-masing agar analisis tidak tercampur.</li>
        </ol>
      </div>
    </div>

    <p class="eyebrow" style="margin-top:56px">Pusat dan SAP Business One</p>

    <div class="flow" id="alur-sync">
      <div class="diagram"><pre class="mermaid">
flowchart LR
  subgraph RL["Rasentra Lokal (outlet)"]
    T[Transaksi disimpan] --> O[(Outbox)]
    AK[Tarik delta dan konfirmasi]
  end
  subgraph RC["Rasentra Central"]
    subgraph SY["Modul Sinkronisasi"]
      S[Sync API] --> IN[(Inbox unik)]
      IN --> R[Rekonsiliasi harian]
      PB[Publikasi berversi]
    end
    subgraph SP["Modul Posting SAP"]
      V[Validasi dan mapping] -- mapping kosong --> X[Antrean pengecualian]
      V --> P[A/R Invoice] --> Q[Incoming Payment]
    end
    subgraph CO["Modul Rasentra Core"]
      PD[Harga, promo, resep disetujui]
    end
  end
  O -- kirim ulang aman --> S
  IN --> V
  PD --> PB --> AK
</pre></div>
      <div class="steps">
        <h3>Sinkronisasi, promo dan posting SAP</h3>
        <p>Dipakai admin pusat, tim pemasaran dan Finance.</p>
        <ol>
          <li>Pemasaran menyusun promo, memilih target (contoh: hanya outlet Lombok), lalu menjalankan simulasi keranjang.</li>
          <li>Penyetuju berbeda menyetujui dan menerbitkan. Dashboard menampilkan outlet yang belum menerima versi.</li>
          <li>Transaksi outlet tiba di pusat dalam sekitar 60 detik bila jaringan sehat; saat putus, tersimpan di antrean outlet.</li>
          <li>Finance memantau status posting: menunggu, terposting, sebagian, gagal, perlu pemeriksaan.</li>
          <li>Selisih dibuka sebagai pekerjaan rekonsiliasi dengan pemilik dan bukti penyelesaian.</li>
        </ol>
      </div>
    </div>
  </div>
</section>

<section class="block" id="panduan">
  <div class="wrap">
    <div class="sec-head">
      <p class="eyebrow">Panduan penggunaan</p>
      <h2>Apa yang dikerjakan setiap peran</h2>
      <p>Setiap pengguna memakai akun sendiri dan hanya melihat outlet yang ditugaskan. Tindakan sensitif seperti void, refund dan override harga selalu meminta penyetuju yang berbeda.</p>
    </div>
    <div class="roles">
      <article class="role"><header><h3>Kasir retail</h3><span>Rasentra POS</span></header>
        <ol><li>Buka shift dan isi saldo awal.</li><li>Scan barang, cek promo, terima pembayaran.</li><li>Gunakan Tahan Transaksi bila pelanggan masih memilih.</li><li>Cash in/out dan buka laci tanpa penjualan wajib alasan.</li><li>Tutup shift: hitung uang dan bukti per metode bayar.</li></ol>
        <p class="tip">Pembayaran QRIS berstatus "tidak pasti"? Tekan Cek Status dulu. Jangan meminta pelanggan membayar ulang.</p></article>
      <article class="role"><header><h3>Pelayan & kasir resto</h3><span>Rasentra Mobile Terminal / POS</span></header>
        <ol><li>Buka meja, isi jumlah tamu.</li><li>Catat menu, modifier, course dan alergi.</li><li>Kirim ke dapur; tahan course berikutnya bila perlu.</li><li>Setujui pesanan dari QR meja.</li><li>Split bill, terima pembayaran, tutup meja.</li></ol>
        <p class="tip">Pindah atau gabung meja tercatat di riwayat; item tidak terduplikasi.</p></article>
      <article class="role"><header><h3>Chef & dapur</h3><span>Rasentra Kitchen</span></header>
        <ol><li>Buka layar station; tiket baru muncul dengan timer.</li><li>Sentuh Mulai atau scan barcode tiket; bahan terpotong sesuai resep.</li><li>Sentuh Siap; tiket berubah warna bila melewati target waktu.</li><li>Expeditor memeriksa kelengkapan, lalu Disajikan.</li><li>Catat produksi batch, yield dan waste.</li><li>Konfirmasi penerimaan kemasan untuk cooking fee.</li></ol>
        <p class="tip">Pembatalan setelah dimasak harus diakui station dan tercatat sebagai waste.</p></article>
      <article class="role"><header><h3>Kepala outlet</h3><span>Rasentra POS · menu supervisor</span></header>
        <ol><li>Terima kiriman dari gudang pusat.</li><li>Setujui void, refund, override sesuai limit.</li><li>Buat sesi opname dan setujui hasil.</li><li>Pantau antrean sinkronisasi dan status printer/KDS.</li><li>Kunci akun lokal bila ada petugas keluar saat offline.</li></ol>
        <p class="tip">Ajukan permintaan barang lewat menu Permintaan, bukan lewat chat.</p></article>
      <article class="role"><header><h3>Petugas opname</h3><span>Rasentra Inventory</span></header>
        <ol><li>Pilih sesi yang ditugaskan.</li><li>Pindai QR lokasi, lalu barang.</li><li>Timbang barang curah dan bahan setengah jadi.</li><li>Pisahkan barang rusak dengan alasan dan foto.</li><li>Kirim hasil; recount bila diminta.</li></ol>
        <p class="tip">Petugas hitung tidak bisa menyetujui koreksinya sendiri.</p></article>
      <article class="role"><header><h3>Admin pusat, pemasaran & Finance</h3><span>Rasentra Central + MFA</span></header>
        <ol><li>Kelola outlet, perangkat, pengguna dan peran.</li><li>Susun harga, promo dan resep; simulasi lalu publikasi.</li><li>Pantau heartbeat dan umur antrean tiap outlet.</li><li>Finance memeriksa rekonsiliasi harian dan dokumen SAP.</li><li>Tangani antrean pengecualian mapping.</li></ol>
        <p class="tip">Perubahan pusat baru berlaku di outlet setelah outlet mengonfirmasi penerimaan versi.</p></article>
    </div>
  </div>
</section>

<section class="block" id="arsitektur">
  <div class="wrap">
    <div class="sec-head">
      <p class="eyebrow">Arsitektur</p>
      <h2>Satu basis kode, dua profil instalasi</h2>
      <p>Rasentra Lokal (server outlet) dan Rasentra Central dengan modul Sinkronisasi, Posting SAP dan Rasentra Core (kantor pusat) menjalankan aplikasi yang sama dengan profil berbeda. SAP B1 tetap sumber resmi akuntansi, HPP dan nilai persediaan.</p>
    </div>
    <div class="tbl"><table>
      <thead><tr><th>Lapisan</th><th>Teknologi</th><th>Peran</th></tr></thead>
      <tbody>
        <tr><td>Backend</td><td class="mono">Laravel 13 · PHP 8.3+</td><td>API lokal, sync API, portal, SAP bridge</td></tr>
        <tr><td>Database</td><td class="mono">MySQL 8.4 InnoDB</td><td>DECIMAL untuk uang dan berat, UUID transaksi, ledger append-only</td></tr>
        <tr><td>Realtime LAN</td><td class="mono">Laravel Reverb</td><td>Order ke KDS, status meja dan pembayaran; polling cadangan</td></tr>
        <tr><td>Antarmuka</td><td class="mono">Blade + JavaScript lokal</td><td>Rasentra POS, Mobile Terminal, Inventory, Kitchen, kiosk dan Core; tanpa CDN agar tetap jalan saat internet putus</td></tr>
        <tr><td>Proses latar</td><td class="mono">Queue + Scheduler</td><td>Outbox, cetak, aktivasi promo, posting SAP, backup</td></tr>
        <tr><td>Integrasi</td><td class="mono">SAP Service Layer · XML MSI/MSR</td><td>A/R Invoice, Incoming Payment, transfer, produksi, opname</td></tr>
        <tr><td>Rasentra Lokal (server outlet)</td><td class="mono">4 core · 16 GB · SSD 512 GB · UPS</td><td>Profil awal pilot, final mengikuti uji beban</td></tr>
      </tbody>
    </table></div>
    <div class="outlets" aria-label="Daftar outlet">
      <span><b>JKT</b>Pangkalan Jati</span><span><b>JKT</b>Pasar Minggu</span><span><b>JKT</b>Cibubur</span><span><b>JKT</b>Rawa Sari</span><span><b>JKT</b>Kalideres</span><span><b>JKT</b>Karang Tengah</span>
      <span><b>BKS</b>Bintara</span><span><b>BKS</b>Bekasi Timur</span><span><b>BKS</b>Bekasi Utara</span><span><b>BGR</b>Bogor</span><span><b>CKR</b>Cikarang</span><span><b>TNG</b>Pamulang</span>
      <span><b>DPK</b>Cinere</span><span><b>DPK</b>Depok GDC</span><span><b>LPG</b>Antasari</span><span><b>LPG</b>Diponegoro</span><span><b>LPG</b>Citraland</span><span><b>LPG</b>Kemiling</span>
    </div>
  </div>
</section>
</main>

<footer>
  <div class="wrap">
    <span>Rasentra untuk Indoguna Group · berdasarkan spesifikasi v2.0, 29 Oktober 2026</span>
    <span>Rasentra POS · Mobile Terminal · Inventory · Kitchen · Lokal · Central (Sinkronisasi, Posting SAP, Core)</span>
  </div>
</footer>

<script>
document.querySelectorAll('details.sub .drop a').forEach(function(a){
  a.addEventListener('click',function(){ var d=a.closest('details'); if(d) d.open=false; });
});
document.addEventListener('click',function(e){
  var d=document.getElementById('menu-alur');
  if(d && d.open && !d.contains(e.target)) d.open=false;
});
</script>

@endverbatim
<script type="module">
import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11.4.1/dist/mermaid.esm.min.mjs';
mermaid.initialize({ startOnLoad: true, theme: 'neutral' });
</script>
</body>
</html>
