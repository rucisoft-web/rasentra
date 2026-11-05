# Panduan migrasi POS ke Rasentra

Panduan ini untuk CV di Indoguna Group yang sekarang memakai **Rene POS** (CV Makasar Kulina, CV Balikpapan Kulina, CV Jogja Kulina Utama, CV Semarang Kulina Utama, CV Bandung Kulina Utama) atau **Raptor** (CV Sarana Indoguna Lestari), dan akan pindah ke **Rasentra POS**. Pembukuan resmi **tetap di Accurate Online**, tidak pindah ke SAP Business One.

## Gambaran besar

| Sebelum | Sesudah |
| --- | --- |
| Kasir memakai Rene POS / Raptor | Kasir memakai Rasentra POS, Kitchen, Mobile Terminal dan Inventory |
| Rene POS / Raptor mengirim (atau diinput ulang) penjualan ke Accurate | **Rasentra Central → Posting Accurate** (antrean posting yang sama dengan SAP) mengirim penjualan harian ke Accurate per CV |
| Master barang dan harga dikelola di POS lama dan Accurate | Barang tetap berinduk ke **kode barang Accurate**; harga, promo dan resep dikelola di Rasentra Core |
| Data member tersebar di POS lama | Member masuk **Rasentra CRM** (sudah bisa diimpor sekarang dari menu Impor member outlet) |

Yang tidak berubah: chart of accounts, pajak, pembelian, hutang/piutang, laporan keuangan dan tutup buku tetap dikerjakan tim akuntansi di Accurate Online.

> **Cara kerja posting.** Rasentra Central memposting penjualan lewat antrean posting yang sama untuk SAP B1 dan Accurate. CV yang diatur **Pembukuan = Accurate Online** di Rasentra Core → Perusahaan & outlet diposting ke Accurate memakai API Token dan signature secret yang diisi di Rasentra CRM → Accurate Online (satu koneksi untuk tarik data pelanggan dan untuk posting). Posting tetap harus lulus uji pada langkah 6 (uji paralel) sebelum go-live.

## Ringkasan langkah dan durasi

| # | Langkah | Penanggung jawab | Perkiraan waktu |
| --- | --- | --- | --- |
| 1 | Inventaris sistem lama & keputusan cut-over | Manajer CV, IT pusat | 1 minggu |
| 2 | Siapkan Accurate (akun, gudang, API Token) | Akuntansi CV | 2–3 hari |
| 3 | Siapkan master di Rasentra Core | Admin pusat, merchandising | 3–5 hari |
| 4 | Impor data dari POS lama | IT pusat, CRM | 2–3 hari |
| 5 | Pasang perangkat & Rasentra Lokal di outlet | IT pusat, kepala outlet | 1–2 hari per outlet |
| 6 | Pelatihan & uji paralel | Kepala outlet, kasir, akuntansi | 5–7 hari |
| 7 | Cut-over (go-live) | Semua | 1 malam |
| 8 | Pendampingan & rekonsiliasi pasca go-live | Akuntansi, IT pusat | 2 minggu |

Lakukan per CV, mulai dari satu outlet percontohan, baru outlet lain di CV yang sama.

## 1. Inventaris sistem lama

Kumpulkan sebelum mulai:

1. **Daftar outlet** per CV: alamat, jenis usaha (meatshop/retail, restoran, grosir), jam buka, zona waktu (WIB/WITA).
2. **Perangkat**: jumlah kasir, printer struk/dapur, timbangan label, laci kas, EDC, tablet pelayan, jaringan internet.
3. **Cara POS lama terhubung ke Accurate** saat ini: otomatis (konektor/add-on), ekspor-impor file, atau input manual. Catat dokumen yang terbentuk di Accurate (faktur penjualan, penerimaan penjualan, per transaksi atau rekap harian) dan akun yang dipakai. Integrasi ini **harus dimatikan** saat cut-over agar penjualan tidak tercatat dua kali.
4. **Data yang bisa diekspor** dari POS lama: barang & harga, kategori, modifier/menu, member & poin, promo aktif, user kasir, saldo deposit/voucher. Minta format Excel/CSV ke vendor bila menu ekspor tidak tersedia.
5. **Kontrak & lisensi** POS lama: tanggal habis, biaya, kewajiban pemberitahuan ke vendor, cara mengambil data historis setelah berhenti berlangganan.
6. Tentukan **tanggal cut-over**: sebaiknya awal periode (tanggal 1) atau hari sepi, tidak bersamaan dengan stok opname bulanan atau tutup buku.

## 2. Siapkan Accurate Online

Dikerjakan tim akuntansi CV di database Accurate masing-masing:

1. Buat **API Token** (Pengaturan → API Token) dengan izin baca/tulis barang, pelanggan, faktur penjualan, penerimaan penjualan, gudang. Simpan token dan signature secret; isi di Rasentra CRM → Accurate Online untuk CV tersebut.
2. Pastikan **gudang** Accurate per outlet ada (mis. `GDG-MKS-01`).
3. Siapkan **akun kas/bank per metode bayar**: tunai, debit/kredit per bank EDC, QRIS, transfer, e-wallet, voucher.
4. Siapkan **akun penjualan, diskon, pembulatan, service charge** dan pengaturan **PPN/PB1** sesuai praktik CV.
5. Buat **pelanggan umum** (mis. `UMUM-MKS`) untuk penjualan tunai tanpa member.
6. Bila memakai **departemen/proyek** di Accurate untuk laporan per outlet, buat satu per outlet.
7. Rapikan **kode barang**: setiap barang yang dijual di outlet harus punya nomor barang (itemNo) dan satuan yang benar di Accurate.

## 3. Siapkan master di Rasentra Core

1. **Perusahaan**: CV sudah ada di Rasentra CRM → Perusahaan & cabang. Di Rasentra Core → Perusahaan & outlet, set **Pembukuan = Accurate Online**, lalu isi API Token dan signature secret database Accurate-nya di Rasentra CRM → Accurate Online.
2. **Outlet & lokasi**: buat outlet di Rasentra Core → Perusahaan & outlet (kode, zona waktu, retail/restoran, cooking fee), lalu lokasi etalase, chiller, gudang, dapur dan karantina.
3. **Mapping Accurate** per CV di menu **Mapping SAP** (satu tabel untuk SAP dan Accurate): kode barang Rasentra ↔ itemNo Accurate, outlet ↔ gudang dan departemen, metode bayar ↔ akun kas/bank, pajak, pelanggan umum.
4. **Produk & harga**: tarik daftar barang dari Accurate sebagai induk, lengkapi barcode, PLU timbangan, kategori dan station dapur, lalu terbitkan **versi harga** per outlet (maker-checker).
5. **Menu restoran & resep**: buat menu, modifier dan resep (untuk pemotongan stok bahan), disetujui merchandising.
6. **Promo** yang masih berjalan di POS lama: buat ulang di Promotion Designer dengan periode yang sama.
7. **Pengguna**: buat akun kasir, pelayan, chef, petugas gudang dan kepala outlet dengan PIN; atur limit approval kepala outlet.

## 4. Impor data dari POS lama

| Data | Cara masuk ke Rasentra | Catatan |
| --- | --- | --- |
| Member & nomor HP | Rasentra CRM → Impor member outlet (Excel/CSV per cabang) | Nomor digabung otomatis bila member terdaftar di beberapa outlet |
| Poin/saldo member | Diimpor sebagai saldo awal membership per tanggal cut-over | Cocokkan total poin dengan laporan POS lama |
| Barang & barcode | Dari Accurate (induk), barcode/PLU dari ekspor POS lama | Barang tanpa itemNo Accurate tidak boleh dijual |
| Harga | Versi harga Rasentra, efektif tanggal cut-over | Cetak daftar harga untuk dicek kepala outlet |
| Stok awal | **Stok opname** di Rasentra Inventory pada malam cut-over | Jangan memindahkan saldo stok dari POS lama; hitung fisik |
| Voucher/deposit aktif | Daftar nomor & nilai, dicatat sebagai voucher Rasentra | Voucher lama tetap dihormati sampai habis berlaku |
| Riwayat penjualan | Tidak dimigrasi ke Rasentra; tetap di Accurate dan arsip POS lama | Riwayat belanja member bisa masuk CRM dari faktur Accurate |

## 5. Pasang perangkat dan Rasentra Lokal

1. Siapkan **server Rasentra Lokal** di outlet (PC kecil/mini server) agar kasir tetap jalan saat internet putus.
2. Pairing server ke Rasentra Central memakai token perangkat `outlet_server` (Rasentra Core → Perangkat & token).
3. Hubungkan kasir, printer struk (80 mm), printer/layar dapur, timbangan label dan tablet pelayan ke server lokal.
4. Uji cetak struk, label timbangan (pack ID dengan berat aktual) dan tiket dapur.
5. Pastikan **heartbeat** outlet tampil hijau di Rasentra Central → Status Central.

## 6. Pelatihan dan uji paralel

1. Latih kasir, pelayan, chef dan gudang memakai akun latihan (mode demo).
2. Jalankan **uji paralel 5–7 hari**: POS lama tetap resmi; transaksi yang sama diinput juga di Rasentra (outlet percontohan, atau sampel jam sibuk).
3. Setiap hari bandingkan: omzet, jumlah struk, per metode bayar, diskon/promo, pajak.
4. Uji **Posting Accurate** ke database Accurate uji (atau periode yang dikunci): faktur dan penerimaan terbentuk dengan akun, gudang, pajak dan departemen yang benar; posting ulang tidak membuat dokumen ganda.
5. Selesaikan semua selisih sebelum menyetujui go-live. Kriteria lulus: selisih omzet harian 0, semua metode bayar cocok, tidak ada barang tanpa mapping.

## 7. Cut-over (malam go-live)

1. Tutup shift terakhir di POS lama dan cetak laporan akhir hari.
2. Pastikan penjualan terakhir POS lama sudah masuk Accurate, lalu **matikan integrasi POS lama → Accurate** (atau hentikan impor file).
3. Lakukan **stok opname** di Rasentra Inventory; hasilnya menjadi stok awal.
4. Aktifkan versi harga dan promo Rasentra mulai tanggal go-live.
5. Ubah status POS lama menjadi **hanya-baca** (jangan dihapus; dibutuhkan untuk retur dan audit).
6. Pagi hari: buka shift di Rasentra POS, cek transaksi pertama sampai masuk Rasentra Central.

## 8. Setelah go-live

1. **Rekonsiliasi harian** di Rasentra Central (tutup hari) dan cocokkan dengan Accurate: omzet, kas/bank, pajak.
2. Pantau posting Accurate di **Status Central** dan **Antrean posting**; dokumen gagal diperbaiki mapping-nya lalu di-retry dari sana.
3. Retur atas penjualan lama (sebelum cut-over) diproses sesuai kebijakan: dicatat manual di Accurate sebagai retur, bukan di Rasentra.
4. Setelah 2 minggu tanpa selisih, hentikan langganan POS lama dan arsipkan ekspor datanya.

**Rencana mundur (rollback):** bila dalam 3 hari pertama ada masalah yang menghentikan penjualan, kembali ke POS lama (masih tersimpan, hanya-baca → aktif), aktifkan kembali integrasinya ke Accurate, dan posting manual transaksi Rasentra yang sudah terjadi.

## Catatan khusus Rene POS

Berlaku untuk CV Makasar Kulina, CV Balikpapan Kulina, CV Jogja Kulina Utama, CV Semarang Kulina Utama dan CV Bandung Kulina Utama.

1. Minta vendor/admin Rene POS mengekspor: daftar menu & barang (dengan kode), kategori, modifier/varian, member & poin, voucher aktif, user. Cocokkan kode menu Rene dengan itemNo Accurate yang sekarang dipakai integrasinya.
2. Untuk outlet restoran, petakan **meja, area, printer dapur per station** dan **modifier** Rene ke Rasentra Mobile Terminal dan Kitchen.
3. Catat cara Rene mengirim ke Accurate (per transaksi atau rekap harian, akun dan pelanggan yang dipakai) agar Posting Accurate Rasentra mengikuti pola yang sama dan laporan akuntansi tetap sebanding.
4. Lima CV memakai pola yang sama: siapkan satu **templat mapping** (metode bayar, pajak, akun) lalu salin per CV, agar migrasi CV kedua dan seterusnya lebih cepat.
5. Urutan yang disarankan: satu outlet percontohan di satu CV → seluruh outlet CV itu → CV berikutnya, jeda minimal satu minggu stabil di antaranya.

## Catatan khusus Raptor

Berlaku untuk CV Sarana Indoguna Lestari.

1. Minta ekspor data dari Raptor: barang & barcode, harga per level/grup pelanggan, pelanggan (termasuk pelanggan grosir/reseller dengan limit kredit bila ada), member & poin, user.
2. Bila Raptor dipakai untuk **penjualan grosir atau kredit** ke pelanggan tertentu, pastikan pelanggan tersebut ada di Accurate dengan nomor pelanggan yang sama, karena piutangnya tetap dikelola di Accurate. Penjualan kredit dari Rasentra harus terposting ke pelanggan Accurate yang benar, bukan pelanggan umum.
3. Harga bertingkat per grup pelanggan di Raptor dipetakan ke **versi harga per kanal/outlet** atau promo pelanggan di Rasentra; cek bersama merchandising sebelum uji paralel.
4. Cocokkan satuan (pcs, kg, karton) dan konversinya antara Raptor, Accurate dan Rasentra, terutama untuk barang timbang.
5. Karena hanya satu CV, uji paralel sebaiknya mencakup **seluruh jenis transaksi** (tunai, kartu, kredit pelanggan, retur) sebelum go-live.

## Daftar periksa go-live

- [ ] API Token Accurate CV terhubung dan uji koneksi berhasil
- [ ] Mapping barang, gudang, metode bayar, pajak dan pelanggan umum lengkap (tidak ada barang tanpa itemNo)
- [ ] Posting Accurate lulus uji: dokumen benar, tanpa duplikat
- [ ] Versi harga dan promo terbit untuk outlet, efektif tanggal go-live
- [ ] Pengguna dan PIN dibuat, limit approval kepala outlet diatur
- [ ] Rasentra Lokal terpasang, heartbeat hijau, printer/timbangan/dapur teruji
- [ ] Member diimpor ke Rasentra CRM; saldo poin cocok
- [ ] Uji paralel lulus tanpa selisih
- [ ] Integrasi POS lama → Accurate dimatikan pada malam cut-over
- [ ] Stok opname cut-over selesai
- [ ] POS lama hanya-baca, rencana rollback dipahami kepala outlet
