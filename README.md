# Rasentra

Platform POS untuk Indoguna Group: meatshop, grocery dan restoran dalam satu codebase Laravel 13 + MySQL, dengan SAP Business One (versi SQL Server) sebagai pembukuan resmi tiap perusahaan.

| Perusahaan | Instance SAP B1 | Outlet | Usaha di outlet | Zona waktu |
| --- | --- | --- | --- | --- |
| CV Solo Kulina Utama | `sap_solo` | `SLO` Solo | Meatshop & Grocery (retail), R&B Solo (restoran) | WIB |
| CV Lombok Kulina Utama | `sap_lombok` | `LBK` Lombok | Meatshop (retail), El Primo (restoran) | WITA |

| Aplikasi | Untuk | Alamat / profil |
| --- | --- | --- |
| Rasentra POS | Layar sentuh kasir retail dan resto | `/pos` |
| Rasentra Mobile Terminal | Tablet pelayan: peta meja, pesanan dengan modifier, kirim dapur per gelombang, antar hidangan, minta bayar | `/mobile` |
| Rasentra Kitchen | Layar dapur per station, expeditor, layar antrean pickup | `/kitchen` |
| Rasentra Inventory | Handheld: penerimaan barang, stok opname, karantina dan waste, cek stok | `/inventory` |
| Rasentra Lokal | Server lokal di setiap outlet untuk semua perangkat di atas; tetap jalan saat internet putus | profil `lokal` |
| Rasentra Central | Pusat POS di kantor pusat, terdiri dari tiga modul: **Sinkronisasi** (terima transaksi semua outlet, sebar harga/promo/resep/master), **Posting SAP** (ke instance SAP B1 tiap CV) dan **Rasentra Core** (admin: master, harga, Promotion Designer, resep, laporan) | profil `central`, konsol `/core` |

Spesifikasi lengkap v2.0 ada di [Spesifikasi Lengkap Rasentra v2.0](https://claude.ai/artifact/MidWQUchfyxN2rsywyT8aw); rancangan teknis awal di [docs/RANCANGAN.md](docs/RANCANGAN.md). Landing page dengan penjelasan fitur dan diagram alur kerja retail/resto ada di `/`.

## Arsitektur singkat

- **Satu codebase, dua profil.** `RASENTRA_PROFILE=lokal` menjalankan Rasentra Lokal di tiap outlet (Solo dan Lombok). `RASENTRA_PROFILE=central` menjalankan Rasentra Central di kantor pusat Indoguna Group, dengan tiga modul: Sinkronisasi, Posting SAP dan Rasentra Core.
- **Multi-perusahaan.** Setiap CV (`business_units`) menyimpan nama database SAP-nya (`sap_company_db`). Penjualan outlet diposting ke instance milik CV pemiliknya, dengan mapping item, gudang dan akun sendiri. Tanggal bisnis mengikuti zona waktu outlet, jadi Lombok berganti hari pada pukul 00.00 WITA.
- **Offline-first.** Semua transaksi inti berjalan di Rasentra Lokal. Data dikirim ke Rasentra Central lewat outbox/inbox yang idempoten (unik per `source_id` + `event_id`), dengan backoff eksponensial.
- **Pack ID.** Setiap kemasan daging atau ikan punya berat aktual (DECIMAL 18,6). `packs.sold_order_line_id` UNIQUE plus `lockForUpdate` menjamin satu pack hanya terjual sekali, walau dua kasir memindai bersamaan.
- **Uang tanpa float.** Semua hitungan memakai `brick/math` lewat `App\Support\Decimal`.
- **SAP B1 (SQL Server).** Integrasi lewat Service Layer, yang tersedia untuk SAP B1 versi SQL Server sejak 10.0 FP 2008 (server Windows). `SapClientFactory` memilih koneksi per instance. `SapPostingService` mengecek `U_POS_SRC` sebelum posting, menandai `needs_review` saat timeout, dan hanya mengulang pembayaran pada posting parsial. Adapter XML NMEXML (MSI/MSR) aman dari XXE.

## Menjalankan di lokal

```bash
composer install
cp .env.example .env          # RASENTRA_OUTLET_CODE=SLO: layar login menampilkan petugas Solo (LBK untuk Lombok)
php artisan key:generate
# Untuk coba cepat tanpa MySQL: set DB_CONNECTION=sqlite lalu
touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan serve
```

Data demo memutar satu hari operasional relatif terhadap jam saat seeder dijalankan, jadi timer dapur dan antrean terlihat "hidup". Jalankan ulang `migrate:fresh --seed` untuk menyegarkan. Untuk produksi gunakan MySQL 8.4 dan Redis untuk antrean. Scheduler (`php artisan schedule:work`) mengirim outbox tiap 5 detik di profil lokal dan memposting ke SAP di profil central.

Koneksi SAP diatur di `.env` server Central. `SAP_SL_URL`, `SAP_USERNAME` dan `SAP_PASSWORD` adalah nilai bawaan. Nilai `SAP_SOLO_*` dan `SAP_LOMBOK_*` menimpanya bila kedua instance berada di server berbeda atau memakai pengguna berbeda. Nama database (`sap_solo`, `sap_lombok`) diatur per perusahaan di Rasentra Central → Rasentra Core → Perusahaan & outlet. Selama URL belum diisi, posting memakai SAP tiruan.

Untuk hosting cPanel (zip siap-upload berisi `vendor`, instalasi lewat Terminal) lihat [docs/INSTALL-CPANEL.md](docs/INSTALL-CPANEL.md).

Mode demo untuk tamu: set `RASENTRA_DEMO=true`, jalankan `php artisan rasentra:demo-reset --force`, lalu landing page menampilkan tombol **Coba sebagai tamu** menuju `/demo`. Di sana setiap aplikasi bisa dibuka dengan satu klik memakai akun data contoh. Tamu tidak bisa mengubah pengguna, outlet atau pemasangan server, dan data contoh dibangun ulang setiap hari pukul `RASENTRA_DEMO_RESET_AT`.

## Demo utama: outlet Solo (Meatshop & Grocery dan R&B Solo)

Login di `/pos/login`: sentuh nama, ketik PIN. Setiap peran langsung diarahkan ke aplikasinya.

| Petugas | Peran | PIN | Masuk ke |
| --- | --- | --- | --- |
| Rina | Kasir retail | 1111 | Rasentra POS |
| Tono | Kasir resto | 1212 | Rasentra POS |
| Hendra | Kepala outlet, limit approval Rp5.000.000 | 9999 | Rasentra POS |
| Maya | Asisten kepala, limit Rp1.000.000 | 8888 | Rasentra POS |
| Lusi, Agus | Pelayan | 3333, 3434 | Rasentra Mobile Terminal |
| Chef Bambang, Sri, Dimas | Grill, hot kitchen, bar | 2222, 2323, 2424 | Rasentra Kitchen |
| Wawan, Yuni | Gudang | 4444, 5555 | Rasentra Inventory |

**Isi data:**

- **Retail.**
  - Daging sapi, kambing dan unggas per Pack ID: sirloin, tenderloin, ribeye, wagyu MB5, iga, buntut, ayam kampung.
  - Ikan laut segar per Pack ID: kakap merah, kerapu macan, bawal, salmon, tuna, udang vaname, cumi, kepiting bakau, kerang hijau.
  - Seafood beku, olahan, bumbu dan saus, sembako (beras Rojolele Delanggu), oleh-oleh Solo (abon sapi, serundeng, intip), dan minuman kemasan.
- **Restoran R&B Solo.** 30 menu dengan resep dan station dapur:
  - Steak dan bakaran: Sirloin hingga Wagyu, iga bakar madu, sate kambing, kakap bakar Jimbaran.
  - Seafood dan khas Solo: Selat Solo, tengkleng, sop buntut, nasi liwet.
  - Minuman (wedang uwuh) dan dessert (serabi Notosuman).
  - Ada 11 meja di area Indoor, Teras, Lesehan dan VIP.
- **Promo aktif.**
  - Borong daging sapi: hemat 10% untuk ≥ 2 kg, 15% untuk ≥ 4 kg.
  - Seafood segar diskon 12% di retail.
  - Sosis beli 2 gratis 1.
  - Harga spesial kentang.
  - Steak dine-in hemat 10%, maksimal Rp40.000.
  - Belanja retail ≥ Rp1 juta dipotong Rp50.000.
- **Cooking fee** (pelanggan memilih daging atau ikan di etalase, lalu dimasak di resto):
  - Steak: Rp45.000/kg.
  - Bakar seafood: tarif bertingkat per kg.
  - Goreng: Rp35.000/kg.
  - Saus Padang: Rp30.000/porsi.
- **Hari berjalan.**
  - Belasan struk retail dengan tunai, EDC, QRIS dan pembayaran campuran.
  - Override harga dan penjualan pack kedaluwarsa dengan PIN kepala outlet.
  - Satu transaksi ditahan.
  - Pesanan resto yang sudah selesai, sehingga analisis waktu saji terisi.
  - Meja yang sedang dimasak (ada yang terlambat), termasuk meja VIP dengan cooking fee kakap bakar dan udang saus padang.
  - Tiga takeaway di layar antrean.
  - Produksi saus lada hitam, kiriman gudang pusat yang belum diterima, dan sesi opname yang sedang berjalan.

**Pack untuk dicoba:**

| Pack ID | Isi | Catatan |
| --- | --- | --- |
| `PK-100004`, `PK-100005` | Sirloin Lokal 2,040 kg dan 1,120 kg | Total > 2 kg: promo borong 10% |
| `PK-100031`, `PK-100045` | Kakap merah 0,960 kg, udang vaname 1,000 kg | Promo seafood 12%; bisa minta "Masak di resto" |
| `PK-100015` | Wagyu Striploin MB5 0,610 kg | Harga Rp780.000/kg |
| `PK-109001` | Sirloin ditahan QC | Butuh PIN kepala outlet (Hendra 9999) |
| `PK-120001` – `PK-120004` | Kiriman dalam perjalanan | Terima lewat Rasentra Inventory |
| `PK-120005` | Udang ditolak saat terima (vakum bocor) | Di karantina: lepas ke stok atau buang |
| `8992301001009` | Barcode Sosis Bratwurst | Scan 3 kali: beli 2 gratis 1 |

**Outlet Lombok (Meatshop dan El Primo).** Set `RASENTRA_OUTLET_CODE=LBK`. Katalog dan resep sama dengan Solo, sedangkan harga (Solo + 5%), promo, meja El Primo, cooking fee dan petugas milik Lombok sendiri: Baiq Nurul kasir Meatshop 1111, Lalu Ahmad kasir El Primo 1212, Made kepala outlet 9999, Wayan pelayan 3333, Chef Rizal 2222, Hamdi gudang 4444.

## Rasentra POS (kasir)

Layar sentuh untuk kasir retail dan resto. Selain scan Pack ID/barcode, promo otomatis, pembayaran campuran, tahan pesanan, cooking fee dan kirim dapur, kasir punya:

- **Struk 80 mm.** Setelah transaksi selesai, struk langsung tercetak ke printer thermal (halaman cetak `/pos/receipt/{order}`, ukuran kertas 80 mm). Cetakan berikutnya bertanda **SALINAN ke-N** dan tercatat di audit log (`receipt.reprinted`). Footer struk diatur lewat `RASENTRA_RECEIPT_FOOTER`.
- **Riwayat.** Tombol Riwayat menampilkan struk hari ini, dengan pencarian nomor struk, nama atau telepon pemesan. Dari detail struk kasir bisa cetak ulang atau memproses retur.
- **Retur penjualan.** Selalu butuh PIN kepala outlet dan alasan, paling lama `RASENTRA_RETURN_DAYS` hari (bawaan 7) sejak transaksi. Nilai dihitung pro-rata dari harga setelah promo. Daging dengan Pack ID diretur utuh dan masuk lokasi karantina (status pack `quarantine`); barang retail bisa kembali ke stok atau ke karantina bila rusak; menu dan jasa masak hanya pengembalian uang. Uang kembali tunai dari laci kasir (mengurangi saldo kas shift), atau lewat metode bayar asal sebatas nominalnya. Nomor retur `SLO-R261029-0001`, bukti retur tercetak untuk ditandatangani pelanggan.
- **Pickup dan delivery.** Kanal ini tersedia juga untuk meatshop. Nama dan telepon pemesan wajib, delivery juga wajib alamat; waktu ambil/kirim dan catatan opsional. Datanya tercetak di struk.
- **Laporan shift.** Laporan sementara bisa dicetak kapan saja dari menu Shift. Saat tutup shift, laporan akhir (penjualan per kanal, pembayaran, retur dan refund, kas masuk/keluar, tunai seharusnya, hitungan, selisih) langsung tercetak untuk ditandatangani kasir dan kepala outlet.

Retur dikirim ke Rasentra Central sebagai event `order.returned`. Di Central retur menjadi dokumen bernilai negatif pada tanggal bisnis retur, sehingga laporan Core menampilkan penjualan bersih setelah retur, sedangkan jumlah struk tetap hanya menghitung penjualan. Tutup hari ikut mengirim jumlah dan nomor retur untuk rekonsiliasi. Di SAP, retur diposting sebagai A/R Credit Memo yang merujuk baris invoice struk asal, lalu Outgoing Payment ke pelanggan. Bila invoice asal belum terposting, retur berstatus "menunggu dokumen lain" dan diposting otomatis pada putaran berikutnya.

## Rasentra Mobile Terminal (tablet pelayan)

Pelayan masuk dengan PIN di `/pos/login` dan langsung diarahkan ke `/mobile`. Layar bekerja lanskap (menu dan pesanan berdampingan) maupun potret (menu dan pesanan bergantian).

- **Peta meja per area.** Kosong, terisi (jumlah tamu, lama duduk, total, pelayan) atau minta bayar. Penanda biru "N siap" muncul saat dapur selesai, dan merah bila masih ada item yang belum dikirim.
- **Kunjungan bertoken.** Membuka meja membuat kunjungan dengan token baru. Semua permintaan tablet memakai token itu, jadi setelah meja dibayar dan dibuka lagi, pesanan dari tablet yang masih membuka meja lama ditolak dan tidak pernah masuk ke tamu berikutnya.
- **Catat menu.** Modifier per kategori (kematangan steak wajib dipilih, extra butter, level pedas, gula dan es minuman; diatur di `rasentra.menu_modifiers`), jumlah, catatan cepat atau bebas, dan urutan saji: langsung, pembuka, utama atau penutup.
- **Kirim dapur per gelombang.** Kirim semua, atau hanya Pembuka dulu lalu Utama saat tamu siap. Station hanya menerima tambahan baru, dan tiket dapur berlabel nomor meja.
- **Status per item.** Belum dikirim, antre, dimasak, siap diantar, disajikan, minta batal. Tombol "Antar N hidangan siap" menandai hidangan disajikan.
- **Batal.** Item yang belum dikirim bisa langsung dihapus. Item yang sudah di dapur butuh alasan dan PIN kepala outlet, lalu menjadi Minta batal di layar station.
- **Masak daging tamu.** Daging atau ikan yang dibeli di kasir meatshop dimasak di resto dengan nomor struk dan Pack ID; cooking fee masuk tagihan meja dan hak layanannya hanya bisa dipakai sekali.
- **Pindah meja, ubah jumlah tamu, tutup meja** bila tamu batal sebelum memesan.
- **Minta bayar.** Meja berubah kuning. Pembayaran tetap di kasir: di Rasentra POS tombol **Meja** menampilkan meja terisi (yang minta bayar paling atas); kasir memilihnya, pesanan masuk ke shift kasir itu, lalu dibayar seperti biasa. Setelah lunas, kunjungan ditutup dan meja kembali kosong.

## Rasentra Inventory (handheld gudang)

Satu kolom scan untuk empat tab: Terima, Opname, Karantina dan Cek stok.

- **Karantina.** Berisi pack yang ditolak saat terima, retur pelanggan, dan barang rusak. Setelah diperiksa, barang dilepas kembali ke stok jual atau dibuang (waste).
- **Hampir kedaluwarsa.** Pack yang kedaluwarsa dalam `RASENTRA_EXPIRY_WARNING_DAYS` hari (bawaan 2) ditampilkan. Petugas bisa menahannya, sehingga kasir butuh PIN kepala outlet untuk menjualnya, atau langsung membuangnya.
- **Waste dari stok jual.** Pack dibuang utuh sesuai beratnya, SKU dibuang dengan jumlah, dari stok jual atau karantina, dan tidak boleh melebihi saldo.
- **Persetujuan.** Melepas atau membuang barang butuh kepala outlet. Kepala outlet bisa memutuskan langsung, sedangkan petugas gudang memasukkan PIN kepala outlet di layar.
- **Cek stok.** Menampilkan saldo per lokasi dan sepuluh pergerakan terakhir. Dari layar ini juga bisa langsung tahan, lepas atau buang.

Setiap keputusan menjadi dokumen penyesuaian stok: `SLO-W…` untuk waste, `SLO-RL…` untuk lepas karantina, dan `SLO-OP…` untuk selisih opname yang disetujui (batal masak setelah dimasak juga tercatat sebagai waste). Dokumen ini dikirim ke Rasentra Central sebagai event `stock.adjusted` dan tampil di laporan Opname & waste di Core.

Di SAP, barang yang keluar dari stok (waste, opname kurang) diposting sebagai Goods Issue (`InventoryGenExits`), dan opname lebih sebagai Goods Receipt (`InventoryGenEntries`). Akun lawannya diatur lewat mapping SAP jenis "Penyesuaian stok → Akun", dengan kunci `waste` atau `count`. Lepas karantina tidak membuat dokumen SAP.

## Rasentra Kitchen (layar dapur)

Buka `/kitchen` lalu pilih layar untuk perangkat ini: station (grill, hot, cold, bar, pastry, packing), Expeditor, Layar antrean, atau Analisis waktu saji.

- **Hanya delta.** Station menerima baris baru saja. Bila kasir menambah porsi pada baris yang sudah dikirim, dapur hanya menerima tambahannya. Ubah harga tidak mengirim ulang apa pun. Tiket cetak susulan ditandai TAMBAHAN.
- **Status item.** Baru, Diterima (otomatis saat tampil di layar), Dimasak, Siap, Disajikan, Minta batal, Batal. Bahan resep dipotong sekali saat Mulai masak.
- **Minta batal.** Bila kasir mem-void baris yang sudah dikirim, item yang belum dimasak langsung batal. Item yang sudah dimasak menjadi Minta batal beserta alasannya, dan station harus menekan Konfirmasi batal. Bahan yang sudah terpakai dicatat sebagai dokumen waste dan diposting ke SAP sebagai Goods Issue.
- **Tiket cetak.** Setiap Kirim dapur mencetak satu tiket per station di printer 80 mm, lengkap dengan barcode kode bump (Code 128). Scan barcode di layar station memajukan tiket. Matikan dengan `RASENTRA_KITCHEN_PRINT=false`.
- **Timer.** Hijau, kuning mulai 75% target, merah setelah lewat target. Target per station diatur di `config/rasentra.php`.
- **Pembaruan.** Layar memeriksa penanda perubahan tiap 1,5 detik dan hanya memuat ulang papan bila ada perubahan, jadi perubahan tampil di layar lain dalam kurang dari 2 detik. Tiket baru membunyikan nada pendek.
- **Expeditor dan antrean.** Expeditor mengelompokkan item per meja atau pesanan dan baru bisa menyajikan setelah semua station Siap. Layar antrean menampilkan nomor takeaway, pickup dan delivery; nomor yang sudah diambil hilang setelah 3 menit.
- **Analisis waktu saji.** Rata-rata, median, P90, waktu antre dan waktu masak per station, per menu dan per jam (zona waktu outlet), beserta jumlah yang terlambat.

## Rasentra Lokal (server outlet)

Rasentra Lokal adalah instalasi `RASENTRA_PROFILE=lokal` di setiap outlet. Server ini melayani Rasentra POS, Mobile Terminal, Kitchen dan Inventory lewat LAN, tetap berjalan saat internet putus, dan menyinkronkan data dengan Rasentra Central.

Konsol server ada di `/lokal`, khusus kepala outlet (masuk dengan PIN di layar login; di demo Solo: Hendra, PIN `9999`). Kepala outlet juga melihat tombol **Server outlet** di Rasentra POS.

- **Status server.** Koneksi ke Central, antrean kirim, cursor publikasi, penjualan hari ini, shift yang buka, ruang disk, ukuran database dan backup.
- **Antrean kirim.** Event yang belum diterima Central beserta error terakhir. Kepala outlet bisa kirim sekarang atau mengantrekan ulang event yang berhenti dicoba.
- **Harga, promo & resep.** Versi harga, promo aktif dan mendatang, cooking fee, serta riwayat tarik publikasi.
- **Tutup hari.** Pratinjau struk, penjualan, pembayaran dan selisih kas per shift, lalu kirim ringkasannya ke Central. Ada peringatan bila masih ada shift yang buka.
- **Pemasangan.** Tempel alamat Central dan token `outlet_server` dari Rasentra Central (Sinkronisasi → Perangkat & token). Server memeriksa token itu, mengambil kode outlet dan identitas server darinya, lalu menyimpannya terenkripsi. Setelah itu tekan **Ambil snapshot**. Selama server belum punya petugas, halaman ini terbuka tanpa login, jadi lakukan pemasangan segera setelah instalasi.

Rasentra POS menampilkan status **Central · N antre** atau **Offline · N antre**, sehingga kasir tahu transaksinya aman walau internet putus. `GET /lokal/health` mengembalikan status JSON tanpa data sensitif untuk alat pemantau. Backup harian (`rasentra:backup`) berjalan pukul 02.00 dan menyimpan 14 berkas terakhir di `storage/app/backups`.

## Rasentra Central (kantor pusat)

Rasentra Central adalah instalasi `RASENTRA_PROFILE=central` di kantor pusat Indoguna Group. Central terdiri dari tiga modul:

| Modul | Tugas | Kode |
| --- | --- | --- |
| Sinkronisasi | Menerima transaksi semua Rasentra Lokal tanpa data ganda, menyebarkan harga, promo, resep dan master ke outlet, snapshot untuk outlet baru, heartbeat, tutup hari dan rekonsiliasi | API `/api/v1/sync`, `app/Domain/Sync` |
| Posting SAP | Memposting penjualan, retur dan penyesuaian stok ke instance SAP B1 milik tiap CV (`sap_solo`, `sap_lombok`), cek koneksi per instance, mapping, posting ulang | `app/Domain/Integration/Sap` |
| Rasentra Core | Admin kantor pusat: master perusahaan, outlet, produk dan pengguna, versi harga, Promotion Designer, resep, cooking fee, laporan dan audit | `app/Http/Controllers/Core`, `app/Domain/Core` |

Ketiga modul dibuka dari satu konsol web di `/core` (alamat `/central` juga mengarah ke sana). Menu samping dikelompokkan per modul. Halaman **Status Central** merangkum ketiganya: kondisi tiap Rasentra Lokal, publikasi yang belum diterapkan, koneksi tiap instance SAP dan job terjadwal.

Login di `/core/login` dengan email dan kata sandi `password`:

| Pengguna | Peran | Email | Akses |
| --- | --- | --- | --- |
| Rahman Hidayat | Admin pusat | `admin@rasentra.test` | Semua menu |
| Laras Wulandari | Merchandising | `merch@rasentra.test` | Dashboard, produk, harga, promo, resep, laporan |
| Fajar Nugroho | Keuangan | `finance@rasentra.test` | Dashboard, monitoring sinkronisasi dan SAP, laporan, audit |

### Modul Sinkronisasi

Menu: Sinkronisasi outlet (antrean dan event per outlet), Rekonsiliasi harian (tutup hari cocok, menunggu struk, atau selisih), Perangkat & token (token `outlet_server` untuk pairing, tampil sekali dan bisa dicabut), Impor XML NMEXML (MSI/MSR).

API sinkronisasi memakai token perangkat (server outlet):

| Endpoint | Fungsi |
| --- | --- |
| `POST events` | Terima event outbox dari Lokal secara idempoten (`order.finalized`, `order.returned`, `stock.adjusted`, `business_day.closed`). |
| `GET changes?cursor=` | Publikasi sejak cursor: harga, promo, resep, cooking fee, serta master produk, outlet, lokasi, meja dan petugas. |
| `GET snapshot` | Data lengkap untuk Rasentra Lokal baru, khusus server outlet karena berisi hash PIN petugas. |
| `POST ack` | Lokal melaporkan publikasi yang diterapkan atau gagal beserta cursor terakhir. |
| `POST heartbeat` | Antrean outbox, versi aplikasi, jam perangkat, cursor yang diterapkan dan shift yang masih buka. |

Perubahan master di Rasentra Core (produk, pengguna outlet, outlet, lokasi) otomatis diterbitkan ke cursor publikasi. Akun kantor pusat tidak pernah dikirim ke outlet.

**Di server Rasentra Lokal** (pairing bisa lewat `/lokal/setup` atau `.env`: `RASENTRA_CENTRAL_URL`, `RASENTRA_CENTRAL_TOKEN`, `RASENTRA_OUTLET_CODE`, `RASENTRA_SOURCE_ID`):

```bash
php artisan migrate
php artisan rasentra:bootstrap-lokal   # snapshot awal dari Central
php artisan schedule:work              # outbox 5 detik, tarik publikasi 30 detik, heartbeat 1 menit, backup 02.00, tutup hari 04.00
```

Publikasi diterapkan idempoten per baris. Bila satu publikasi gagal (misalnya produk rujukannya belum ada), cursor berhenti tepat sebelumnya dan Central menampilkan error-nya di halaman Status Central.

### Modul Posting SAP

Menu: Dokumen SAP per instance (perlu review, gagal, parsial, posting ulang), Masalah integrasi, dan Mapping SAP per instance (item, gudang, akun kas/bank, akun penyesuaian stok).

- Penjualan menjadi A/R Invoice lalu Incoming Payment, retur menjadi A/R Credit Memo yang merujuk invoice asal lalu Outgoing Payment, dan penyesuaian stok menjadi Goods Issue atau Goods Receipt.
- Setiap dokumen diposting ke instance milik CV pemilik outlet. Sebelum posting, `U_POS_SRC` dicek agar satu transaksi tidak pernah terposting dua kali; timeout ditandai perlu review.
- Posting berjalan tiap menit (`rasentra:post-sap`) dan koneksi tiap instance dicek tiap 15 menit (`rasentra:sap-check`).

### Modul Rasentra Core

- **Dashboard.** Ringkasan per perusahaan (penjualan dan status SAP), penjualan 7 hari, kesehatan sinkronisasi tiap outlet, dan pekerjaan yang menunggu tindakan.
- **Master.** Perusahaan dan database SAP-nya, outlet (nama usaha retail dan restoran, zona waktu) dan lokasi (QR rak), pengguna outlet (PIN, limit approval) dan pusat.
- **Katalog.**
  - Produk.
  - Versi harga: draft, ubah harga, lalu terbitkan oleh orang lain (maker-checker).
  - Promotion Designer: susun aturan, simulasikan pada keranjang contoh, lalu terbitkan oleh orang lain ke semua outlet, wilayah atau outlet tertentu.
  - Resep berversi dan aturan cooking fee berversi.
- **Laporan.** Penjualan per perusahaan dan outlet (unduh CSV), promo per penanggung biaya, waktu saji dapur, opname dan waste, serta audit log.

**Data demo pusat:**

- Riwayat penjualan 7 hari dari Solo dan Lombok, dikirim lewat inbox seperti event sinkronisasi asli. Dua event Solo masih antre (satu gagal kirim).
- Posting ke SAP tiruan per instance. Di `sap_solo` ada satu dokumen perlu review, satu pembayaran gagal, satu invoice gagal, dan tiga struk tertahan karena Kepiting Bakau belum dimapping. `sap_lombok` memakai akun kas/bank sendiri dan terposting semua.
- Laras sudah menyiapkan promo kambing (draft), promo salmon yang ditanggung supplier (sudah disimulasikan), dan versi harga November. Masuk sebagai Rahman untuk menyetujui dan menerbitkannya.

- Server Lokal Solo melaporkan satu event antre dan satu gagal kirim; server Lombok masih versi 0.9.8 dan tertinggal dua publikasi. Tutup hari 6 hari terakhir sudah cocok, kecuali Lombok tanggal kemarin lusa yang masih menunggu satu struk sore.

Laporan penjualan dibaca dari proyeksi `hq_orders` yang diisi otomatis saat inbox menerima `order.finalized`. Jalankan `php artisan rasentra:rebuild-sales` untuk membangun ulang proyeksi.

### Menjalankan Central

**Di server Rasentra Central** cukup `php artisan schedule:work`: posting SAP tiap menit, cek koneksi SAP tiap 15 menit, rekonsiliasi ulang tiap jam.

Perintah lain: `rasentra:pull`, `rasentra:heartbeat`, `rasentra:close-day {tanggal?}`, `rasentra:backup` (Lokal); `rasentra:sap-check`, `rasentra:reconcile {tanggal?}`, `rasentra:post-sap` (Central).

## Coba alurnya

1. **Kasir retail.** Login Rina, lalu scan `PK-100004` dan `PK-100005` di kolom pindai (scanner barcode mengetik lalu Enter). Promo borong terhitung otomatis. Lanjutkan Bayar → Tunai → Selesaikan.
2. **Pilih ikan, dimasak di resto.** Pilih kanal Dine-in, scan `PK-100031`, sentuh barisnya, lalu pilih Masak di resto → Bakar. Jasa bakar muncul sebagai baris terpisah. Tekan Kirim dapur.
3. **Pelayan.** Login Lusi (3333) di tablet. Sentuh meja kosong, isi jumlah tamu, pilih Ribeye (kematangan wajib, set Utama) dan minuman (set Pembuka). Tekan Kirim Pembuka, lalu Kirim dapur. Saat dapur selesai, meja bertanda biru dan tombol Antar muncul. Tekan Minta bayar, lalu login Tono (1212) di kasir, tekan Meja dan bayar meja itu.
4. **Dapur.** Login Chef Bambang dan buka station Grill. Tiket berwarna hijau/kuning/merah sesuai target waktu. Sentuh Mulai masak lalu Siap, atau scan barcode tiket. Layar Expeditor menunggu sampai semua station lengkap. Layar Antrean ada di `/kitchen/queue`.
5. **Penerimaan barang.** Login Wawan di handheld, buka tab Terima, lalu scan `PK-120001`. Untuk pack rusak, pilih Tolak dan isi alasan; stoknya masuk karantina. Di tab Karantina, `PK-120005` bisa dilepas ke stok atau dibuang dengan PIN Hendra (9999), dan pack yang hampir kedaluwarsa bisa ditahan.
6. **Retur dan cetak ulang.** Tekan Riwayat, pilih struk tadi. Cetak ulang struk menghasilkan tanda SALINAN. Tekan Retur barang, pilih pack atau barang, alasan dan metode refund, lalu Hendra memasukkan PIN 9999. Bukti retur tercetak, pack masuk karantina.
7. **Delivery.** Pilih kanal Delivery, scan `PK-100006`, isi nama, telepon dan alamat pemesan, lalu bayar. Di menu Shift, tutup shift untuk mencetak laporan tutup shift.
8. **Stok opname.** Lanjutkan sesi yang sedang berjalan: scan QR lokasi (`RAK`, `ESF`, `FRZ`, ...), lalu scan barang. Hendra menekan Review. Selisih di atas ambang wajib dihitung ulang oleh petugas lain (Yuni) sebelum disetujui.

Uji otomatis memakai outlet terpisah `UJI` (DemoSeeder, di bawah CV Solo Kulina Utama) yang tidak ikut `migrate:fresh --seed`.

## Pengujian

```bash
php artisan test
```

Uji mencakup skenario UAT spesifikasi:
- Pack ID ganda dan konkurensi; pack outlet lain, ditahan atau kedaluwarsa.
- Promo bertumpuk dan recipe explosion.
- Cooking fee dan hak layanan sekali pakai.
- Sinkronisasi idempoten dan posting SAP saat timeout.
- Impor XML MSI/MSR.
- Shift dan selisih kas.
- Alur HTTP POS, papan dapur dan antrean.
- Mobile Terminal: meja hanya bisa dibuka sekali, modifier wajib dan tidak dikenal, kirim per gelombang, hapus bebas sebelum dikirim dan PIN setelah dikirim, antar hidangan siap, minta bayar sampai dibayar kasir, token lama ditolak, pindah meja dan tutup meja kosong.
- Dapur: kiriman delta saat porsi ditambah, void kasir menjadi Minta batal lalu waste, tiket cetak dengan barcode Code 128, penanda perubahan papan, dan analisis per zona waktu outlet.
- Retur penjualan: PIN kepala outlet, pack ke karantina, barang kembali ke stok, kas shift berkurang, batas sisa retur, refund per metode; retur sampai laporan Central, rekonsiliasi tutup hari dan A/R Credit Memo SAP (termasuk menunggu invoice asal); riwayat, cetak ulang SALINAN, laporan tutup shift, dan data pemesan pickup/delivery.
- Penerimaan barang dan opname.
- Karantina dan waste: lepas pack dengan PIN kepala outlet lalu jual lagi, waste dari stok jual dengan cek saldo, tahan pack hampir kedaluwarsa, dan dokumen penyesuaian sampai laporan Central serta Goods Issue/Receipt SAP.
- Seeder demo Indoguna Group dan seluruh halaman konsol Rasentra Central.
- Posting ke instance SAP milik tiap perusahaan, koneksi per instance, dan tanggal bisnis WITA untuk Lombok.
- Rasentra Core: hak akses per peran, maker-checker harga dan promo, proyeksi penjualan, dan token perangkat.
- Rasentra Lokal: akses kepala outlet, pairing dengan pemeriksaan token, pemasangan tanpa login hanya saat server kosong, antrean kirim, tutup hari dari layar, indikator di POS.
- Rasentra Central: heartbeat dan selisih jam, snapshot ke database Lokal terpisah lalu publikasi berikutnya, cursor yang tertahan saat publikasi gagal, tutup hari dan rekonsiliasi (struk tertunda lalu cocok), serta cek koneksi SAP per instance.

## Struktur kode

```
app/Domain/
  Sales/          OrderService, PaymentService, ReturnService, TableService (meja dan kunjungan Mobile Terminal), Approval
  Catalog/        PriceResolver
  Promotion/      PromotionEngine (murni, deterministik), ActivePromotions, PromotionPublisher
  Inventory/      StockLedger (append-only), ReceivingService, StockCountService
  Recipe/         RecipeExploder, RecipeService
  Kitchen/        KitchenService (delta ke station), KitchenBoard (KDS, expeditor, antrean, analisis)
  CookingService/ CookingService
  Cash/           ShiftService
  Sync/           Outbox, OutboxDispatcher, InboxReceiver, Publications (Central), ChangePuller,
                  PublicationApplier, HeartbeatSender, DayCloser (Lokal), DayReconciler (Central)
  Integration/    Sap/* (Service Layer per instance, SapHealth), Xml/* (NMEXML)
  Lokal/          LokalSettings (pairing), LokalStatus, Backup
  Core/           CoreAccess (peran pusat), SalesProjector, SalesReport
  Catalog/        PricePublisher (maker-checker versi harga)
  Promotion/      PromotionDesigner (simulasi, terbit, hentikan)
app/Http/Controllers/  PosController, MobileController, KitchenController, InventoryController, SyncController, Core/* (konsol Rasentra Central: modul Sinkronisasi, Posting SAP dan Rasentra Core), Lokal/* (konsol server outlet)
database/seeders/      OutletSeeder (dua CV), SoloSeeder (demo lengkap), LombokSeeder, CoreSeeder (data pusat), DemoSeeder (outlet UJI untuk uji)
public/assets/         pos, mobile, kitchen, inventory, core (JS/CSS lokal tanpa CDN agar jalan saat internet putus)
```
