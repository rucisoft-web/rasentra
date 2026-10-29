# Rasentra

Platform POS untuk CV Pasar Tani (Toko Tani Indoguna): meatshop, restoran dan gudang pusat dalam satu codebase Laravel 13 + MySQL, dengan SAP Business One sebagai pembukuan resmi.

| Aplikasi | Perangkat | Alamat |
| --- | --- | --- |
| Rasentra POS | Layar sentuh kasir retail dan resto | `/pos` |
| Rasentra Kitchen | Layar dapur per station, expeditor, layar antrean pickup | `/kitchen` |
| Rasentra Mobile | Handheld inventory: penerimaan, stok opname, cek stok | `/mobile` |
| Rasentra Mobile Terminal | Tablet pelayan (meja, order, kirim dapur) | API `/pos/api` (UI tahap berikutnya) |
| Rasentra Central | Server pusat: master, promo, sinkronisasi, jembatan SAP | profil `central` |

Rancangan lengkap ada di [docs/RANCANGAN.md](docs/RANCANGAN.md). Landing page dengan penjelasan fitur dan diagram alur kerja retail/resto ada di `/`.

## Arsitektur singkat

- **Satu codebase, dua profil.** `RASENTRA_PROFILE=outlet` menjalankan server lokal di tiap outlet (18 outlet, kapasitas 20). `RASENTRA_PROFILE=central` menjalankan Rasentra Central.
- **Offline-first.** Semua transaksi inti berjalan di server outlet. Data dikirim ke pusat lewat outbox/inbox yang idempoten (unik per `source_id` + `event_id`), dengan backoff eksponensial.
- **Pack ID.** Setiap kemasan daging punya berat aktual (DECIMAL 18,6). `packs.sold_order_line_id` UNIQUE plus `lockForUpdate` menjamin satu pack hanya terjual sekali, walau dua kasir memindai bersamaan.
- **Uang tanpa float.** Semua hitungan memakai `brick/math` lewat `App\Support\Decimal`.
- **SAP B1.** `SapPostingService` mengecek `U_POS_SRC` sebelum posting, menandai `needs_review` saat timeout, dan hanya mengulang pembayaran pada posting parsial. Adapter XML NMEXML (MSI/MSR) aman dari XXE.

## Menjalankan di lokal

```bash
composer install
cp .env.example .env
php artisan key:generate
# Untuk coba cepat tanpa MySQL: DB_CONNECTION=sqlite lalu
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Untuk produksi gunakan MySQL 8.4 (`DB_CONNECTION=mysql`), Redis untuk antrean, dan Laravel Reverb untuk siaran layar dapur. Scheduler (`php artisan schedule:work`) mengirim outbox tiap 5 detik di profil outlet dan memposting ke SAP di profil central.

## Akun demo outlet Pangkalan Jati (PJT)

Login di `/pos/login`: sentuh nama, ketik PIN. Setiap peran diarahkan ke aplikasinya.

| Petugas | Peran | PIN | Masuk ke |
| --- | --- | --- | --- |
| Sari | Kasir | 1111 | Rasentra POS |
| Budi | Kepala outlet (limit approval Rp5.000.000) | 9999 | Rasentra POS |
| Rudi | Pelayan | 3333 | Rasentra POS |
| Chef Andi | Dapur | 2222 | Rasentra Kitchen |
| Dewi, Eko | Gudang | 4444, 5555 | Rasentra Mobile |

Data contoh: Sirloin Rp200.000/kg, pack `PK-000184` (1,500 kg), `PK-000191` (1,750 kg), `PK-000203` (0,985 kg), pack ditahan `PK-000999`, kiriman dalam perjalanan `PK-000510` dan `PK-000511`, promo Jakarta 10% untuk pembelian daging ≥ 2 kg, cooking fee Rp40.000/kg (minimal Rp30.000).

## Coba alurnya

1. **Kasir retail.** Login Sari, buka shift, scan `PK-000184` dan `PK-000191` di kolom pindai (scanner barcode mengetik lalu Enter). Promo 10% otomatis, lalu Bayar → Tunai → Selesaikan.
2. **Dine-in ke dapur.** Pilih Dine-in, sentuh Sirloin Steak dan Es Teh, tekan Kirim dapur. Login Chef Andi, buka station Grill: sentuh Mulai masak lalu Siap. Expeditor melihat kelengkapan semua station per pesanan.
3. **Takeaway dan layar antrean.** Pesanan takeaway mendapat nomor antrean. Buka `/kitchen/queue` di layar pelanggan; nomor pindah ke "Silakan ambil" saat semua item Siap.
4. **Penerimaan barang.** Login Dewi di handheld, tab Terima, scan `PK-000510`. Pack rusak: pilih Tolak, isi alasan, stok masuk karantina.
5. **Stok opname.** Dewi membuat sesi (blind count), scan QR lokasi `ETL`, lalu scan Pack ID atau barcode + jumlah. Budi menekan Review: selisih di atas ambang wajib dihitung ulang oleh petugas lain (Eko) sebelum disetujui dan diposting.

## Pengujian

```bash
php artisan test
```

38 uji mencakup skenario UAT spesifikasi: Pack ID ganda dan konkurensi, pack outlet lain/ditahan/kedaluwarsa, promo bertumpuk, recipe explosion, cooking fee dan hak layanan sekali pakai, sinkronisasi idempoten, posting SAP saat timeout, impor XML MSI/MSR, shift dan selisih kas, alur HTTP POS, papan dapur dan antrean, penerimaan dan opname.

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
app/Http/Controllers/  PosController, KitchenController, MobileController, SyncController
public/assets/         pos, kitchen, mobile (JS/CSS lokal tanpa CDN agar jalan saat internet putus)
```
