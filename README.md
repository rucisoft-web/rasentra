# Rasentra

Platform POS untuk CV Pasar Tani (Toko Tani Indoguna): meatshop, ikan laut, aneka makanan dan restoran dalam satu codebase Laravel 13 + MySQL, dengan SAP Business One sebagai pembukuan resmi.

| Aplikasi | Untuk | Alamat / profil |
| --- | --- | --- |
| Rasentra POS | Layar sentuh kasir retail dan resto | `/pos` |
| Rasentra Mobile Terminal | Tablet pelayan (meja, order, kirim dapur) | API `/pos/api` (UI tahap berikutnya) |
| Rasentra Kitchen | Layar dapur per station, expeditor, layar antrean pickup | `/kitchen` |
| Rasentra Inventory | Handheld: penerimaan barang, stok opname, cek stok | `/inventory` |
| Rasentra Lokal | Server lokal di setiap outlet untuk semua perangkat di atas; tetap jalan saat internet putus | profil `lokal` |
| Rasentra Central | Pusat POS di kantor pusat: menerima sinkronisasi semua outlet, menyebarkan harga/promo/resep, posting ke SAP | profil `central` |
| Rasentra Core | Aplikasi admin kantor pusat: master outlet/produk/pengguna, harga, Promotion Designer, monitoring, laporan | berjalan di instalasi `central` (UI tahap berikutnya) |

Rancangan teknis ada di [docs/RANCANGAN.md](docs/RANCANGAN.md). Landing page dengan penjelasan fitur dan diagram alur kerja retail/resto ada di `/`.

## Arsitektur singkat

- **Satu codebase, dua profil.** `RASENTRA_PROFILE=lokal` menjalankan Rasentra Lokal di tiap outlet (18 outlet + J&M Solo, kapasitas 20). `RASENTRA_PROFILE=central` menjalankan Rasentra Central beserta Rasentra Core di kantor pusat.
- **Offline-first.** Semua transaksi inti berjalan di Rasentra Lokal. Data dikirim ke Rasentra Central lewat outbox/inbox yang idempoten (unik per `source_id` + `event_id`), dengan backoff eksponensial.
- **Pack ID.** Setiap kemasan daging atau ikan punya berat aktual (DECIMAL 18,6). `packs.sold_order_line_id` UNIQUE plus `lockForUpdate` menjamin satu pack hanya terjual sekali, walau dua kasir memindai bersamaan.
- **Uang tanpa float.** Semua hitungan memakai `brick/math` lewat `App\Support\Decimal`.
- **SAP B1.** `SapPostingService` mengecek `U_POS_SRC` sebelum posting, menandai `needs_review` saat timeout, dan hanya mengulang pembayaran pada posting parsial. Adapter XML NMEXML (MSI/MSR) aman dari XXE.

## Menjalankan di lokal

```bash
composer install
cp .env.example .env          # RASENTRA_OUTLET_CODE=JMS: layar login menampilkan petugas J&M Solo
php artisan key:generate
# Untuk coba cepat tanpa MySQL: set DB_CONNECTION=sqlite lalu
touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan serve
```

Data demo memutar satu hari operasional relatif terhadap jam saat seeder dijalankan, jadi timer dapur dan antrean terlihat "hidup". Jalankan ulang `migrate:fresh --seed` untuk menyegarkan. Untuk produksi gunakan MySQL 8.4, Redis untuk antrean dan Laravel Reverb untuk layar dapur. Scheduler (`php artisan schedule:work`) mengirim outbox tiap 5 detik di profil lokal dan memposting ke SAP di profil central.

## Demo utama: J&M Solo (meatshop, ikan laut, aneka makanan dan resto)

Login di `/pos/login`: sentuh nama, ketik PIN. Setiap peran langsung diarahkan ke aplikasinya.

| Petugas | Peran | PIN | Masuk ke |
| --- | --- | --- | --- |
| Rina | Kasir retail | 1111 | Rasentra POS |
| Tono | Kasir resto | 1212 | Rasentra POS |
| Hendra | Kepala outlet, limit approval Rp5.000.000 | 9999 | Rasentra POS |
| Maya | Asisten kepala, limit Rp1.000.000 | 8888 | Rasentra POS |
| Lusi, Agus | Pelayan | 3333, 3434 | Rasentra POS |
| Chef Bambang, Sri, Dimas | Grill, hot kitchen, bar | 2222, 2323, 2424 | Rasentra Kitchen |
| Wawan, Yuni | Gudang | 4444, 5555 | Rasentra Inventory |

**Isi data:**

- **Retail.**
  - Daging sapi, kambing dan unggas per Pack ID: sirloin, tenderloin, ribeye, wagyu MB5, iga, buntut, ayam kampung.
  - Ikan laut segar per Pack ID: kakap merah, kerapu macan, bawal, salmon, tuna, udang vaname, cumi, kepiting bakau, kerang hijau.
  - Seafood beku, olahan, bumbu dan saus, sembako (beras Rojolele Delanggu), oleh-oleh Solo (abon sapi, serundeng, intip), dan minuman kemasan.
- **Resto J&M Solo.** 30 menu dengan resep dan station dapur:
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
| `PK-120001` – `PK-120005` | Kiriman dalam perjalanan | Terima lewat Rasentra Inventory |
| `8992301001009` | Barcode Sosis Bratwurst | Scan 3 kali: beli 2 gratis 1 |

## Coba alurnya

1. **Kasir retail.** Login Rina, lalu scan `PK-100004` dan `PK-100005` di kolom pindai (scanner barcode mengetik lalu Enter). Promo borong terhitung otomatis. Lanjutkan Bayar → Tunai → Selesaikan.
2. **Pilih ikan, dimasak di resto.** Pilih kanal Dine-in, scan `PK-100031`, sentuh barisnya, lalu pilih Masak di resto → Bakar. Jasa bakar muncul sebagai baris terpisah. Tekan Kirim dapur.
3. **Dapur.** Login Chef Bambang dan buka station Grill. Tiket berwarna hijau/kuning/merah sesuai target waktu. Sentuh Mulai masak lalu Siap, atau scan barcode tiket. Layar Expeditor menunggu sampai semua station lengkap. Layar Antrean ada di `/kitchen/queue`.
4. **Penerimaan barang.** Login Wawan di handheld, buka tab Terima, lalu scan `PK-120001`. Untuk pack rusak, pilih Tolak dan isi alasan; stoknya masuk karantina.
5. **Stok opname.** Lanjutkan sesi yang sedang berjalan: scan QR lokasi (`RAK`, `ESF`, `FRZ`, ...), lalu scan barang. Hendra menekan Review. Selisih di atas ambang wajib dihitung ulang oleh petugas lain (Yuni) sebelum disetujui.

Outlet Pangkalan Jati (PJT) juga punya data kecil yang dipakai oleh uji otomatis (kasir Sari 1111, kepala Budi 9999, chef Andi 2222, gudang Dewi 4444). Set `RASENTRA_OUTLET_CODE=PJT` untuk melihat petugasnya di layar login.

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
- Penerimaan barang dan opname.
- Seeder demo J&M Solo.

## Struktur kode

```
app/Domain/
  Sales/          OrderService, PaymentService, Approval
  Catalog/        PriceResolver
  Promotion/      PromotionEngine (murni, deterministik), ActivePromotions, PromotionPublisher
  Inventory/      StockLedger (append-only), ReceivingService, StockCountService
  Recipe/         RecipeExploder, RecipeService
  Kitchen/        KitchenService (delta ke station), KitchenBoard (KDS, expeditor, antrean, analisis)
  CookingService/ CookingService
  Cash/           ShiftService
  Sync/           Outbox, OutboxDispatcher, InboxReceiver
  Integration/    Sap/* (Service Layer), Xml/* (NMEXML)
app/Http/Controllers/  PosController, KitchenController, InventoryController, SyncController
database/seeders/      OutletSeeder, DemoSeeder (PJT, untuk uji), JmSoloSeeder (demo lengkap)
public/assets/         pos, kitchen, inventory (JS/CSS lokal tanpa CDN agar jalan saat internet putus)
```
