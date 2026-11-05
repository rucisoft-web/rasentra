# Langkah-langkah Implementasi

Rasentra dipasang bertahap: kantor pusat lebih dulu, lalu satu outlet pilot, lalu outlet lain bergelombang. Setiap tahap punya hasil yang bisa diperiksa sebelum lanjut, sehingga outlet tidak pernah berhenti berjualan saat pindah sistem.

## 1. Persiapan kantor pusat (minggu 1–2)

### 1.1 Server Rasentra Central

- Siapkan server Linux atau hosting cPanel dengan PHP 8.3+, MySQL 8.4 dan HTTPS (lihat `docs/INSTALL-CPANEL.md`).
- Set `RASENTRA_PROFILE=central`, jalankan `php artisan migrate --force` dan `php artisan db:seed --force`. Seeder produksi hanya mengisi master dasar: peran & izin, metode bayar, tier member, template shift dan pengaturan umum.
- Aktifkan scheduler (`* * * * * php artisan schedule:run`) untuk posting pembukuan dan pembersihan antrean.

### 1.2 Struktur organisasi dan pembukuan

- Di **Rasentra Core → Perusahaan & outlet**, daftarkan setiap CV beserta pembukuannya: SAP Business One (kode database SAP, kredensial Service Layer di `.env` Central) atau Accurate Online (API Token dan Signature Secret).
- Tambahkan wilayah di **Struktur organisasi**, lalu outlet: kode, zona waktu, usaha meatshop/grocery dan/atau resto.
- Buat akun kantor pusat (admin, merchandising, keuangan, CRM) dan periksa matriks di **Peran & izin**.

### 1.3 Master data

- Impor atau isi produk, satuan, station dapur dan barcode. Daging per Pack ID memakai berat 3 desimal.
- Terbitkan versi harga per outlet atau per grup, resep menu dan tarif cooking fee.
- Isi **Mapping SAP** per kode database CV: item, gudang outlet, pelanggan umum, akun kas/bank per metode bayar. Satu tabel mapping dipakai untuk SAP dan Accurate.
- Atur **Pengaturan umum** di level grup (struk, poin member, self ordering, ongkir, toleransi terlambat) dan **Metode pembayaran** per outlet.

Hasil tahap 1: Rasentra Central bisa dibuka, master lengkap, dan **Status Central → Cek koneksi** ke SAP/Accurate berhasil.

## 2. Pemasangan Rasentra Lokal di outlet pilot (minggu 3)

### 2.1 Perangkat

| Perangkat | Jumlah tipikal | Catatan |
| --- | --- | --- |
| Server Rasentra Lokal | 1 per outlet | 4 core, 16 GB RAM, SSD 512 GB, UPS |
| PC kasir layar sentuh | 1–3 | Scanner, timbangan, laci kas, printer 80 mm |
| Tablet pelayan | 2–6 | Untuk outlet resto |
| Layar dapur | 1 per station | Grill, hot, bar, dll. |
| Handheld inventory | 1–2 | Scanner barcode/QR |
| Router & Wi-Fi | 1 | Jaringan tamu dipisah untuk QR meja |

### 2.2 Instalasi

- Pasang aplikasi yang sama dengan `RASENTRA_PROFILE=lokal` dan `RASENTRA_OUTLET_CODE` sesuai outlet.
- Di Rasentra Core → **Perangkat & token**, buat token server outlet; buka `/lokal/setup` di server outlet dan tempel token. Snapshot master (harga, promo, resep, meja, pengguna, metode bayar, tier, template shift, pengaturan, member) diunduh otomatis.
- Daftarkan perangkat kasir, tablet dan layar dapur; cetak QR meja dari **Meja & QR self order**.

Hasil tahap 2: konsol `/lokal` menampilkan **Terhubung**, antrean kirim kosong, dan publikasi terbaru sudah diterapkan.

## 3. Pelatihan per peran (minggu 3)

Ikuti [Panduan Pengguna](/panduan) per peran. Urutan latihan yang disarankan:

1. Kasir: buka shift, jual Pack ID dan barcode, member & poin, pembayaran campuran, retur, tutup shift.
2. Pelayan: buka meja, catat pesanan, kirim dapur, terima pesanan mandiri QR, minta bayar.
3. Dapur: layar station, bump, expeditor, produksi batch.
4. Gudang: penerimaan, opname blind count, karantina.
5. Kepala outlet: persetujuan PIN, delivery order, roster & absensi, tutup hari.
6. Pusat: dashboard, ringkasan grup, rekonsiliasi, dokumen SAP/Accurate.

Mode demo (`RASENTRA_DEMO=true`) bisa dipakai untuk latihan tanpa mengganggu data asli.

## 4. Uji coba paralel di outlet pilot (minggu 4–5)

- Jalankan Rasentra berdampingan dengan POS lama (Rene POS/Raptor) selama 3–7 hari, atau mulai langsung bila outlet baru.
- Setiap malam: bandingkan **Tutup hari** outlet dengan **Rekonsiliasi harian** Central dan dokumen di SAP/Accurate.
- Uji kondisi putus internet: cabut uplink 1–2 jam, pastikan kasir tetap jualan dan antrean terkirim setelah koneksi kembali.
- Catat kendala di Audit log dan perbaiki mapping yang muncul di **Masalah integrasi**.

Kriteria lulus pilot: 3 hari berturut-turut rekonsiliasi Cocok, tidak ada dokumen pembukuan gagal, waktu scan dan kirim dapur sesuai target.

## 5. Migrasi data pelanggan dan member (paralel dengan tahap 4)

- Impor pelanggan dari POS lama atau Accurate lewat **Rasentra CRM → Impor**; nomor HP dinormalkan ke format 62.
- Tandai pelanggan yang menjadi member dan atur tier awal. Saldo poin lama bisa dimasukkan lewat **Penyesuaian poin** dengan alasan "saldo awal migrasi".

## 6. Rollout bergelombang (minggu 6 dan seterusnya)

- Pasang 2–4 outlet per gelombang dengan langkah tahap 2–4 yang sama. Outlet yang berbagi CV memakai mapping pembukuan yang sudah teruji.
- Untuk CV yang membukukan di Accurate Online, aktifkan pilihan Accurate di Perusahaan & outlet sebelum outlet pertama CV itu dipasang.
- Matikan POS lama di outlet setelah satu minggu tanpa selisih.

## 7. Go-live dan evaluasi berkala

- Harian: kepala outlet menjalankan checklist harian; keuangan memeriksa rekonsiliasi dan dokumen gagal.
- Mingguan: pusat meninjau **Ringkasan grup** (penjualan per outlet, porsi member, delivery tepat waktu, keterlambatan staf) dan laporan promo.
- Bulanan: tinjau matriks izin, tier member dan template shift; pastikan tidak ada dokumen pembukuan tertunda sebelum tutup buku.
