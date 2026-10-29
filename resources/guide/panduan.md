# Panduan Operasional Rasentra per Peran

Panduan ini menjelaskan pekerjaan harian setiap peran di Rasentra, langkah demi langkah. Gambar layar diambil dari server demo dengan data contoh outlet Solo; angka dan nama di layar Anda akan berbeda.

## Arsitektur sistem

Rasentra terdiri dari aplikasi outlet yang berjalan di jaringan lokal outlet, server outlet **Rasentra Lokal**, dan server kantor pusat **Rasentra Central** yang memposting penjualan ke SAP Business One milik masing-masing CV.

![Gambar A · Arsitektur sistem Rasentra: aplikasi outlet, Rasentra Lokal, Rasentra Central dan SAP Business One](/assets/guide/arsitektur-sistem.jpg)

| Komponen | Di mana | Fungsi |
| --- | --- | --- |
| Rasentra POS | PC kasir outlet | Penjualan retail dan restoran, pembayaran, retur, shift |
| Rasentra Mobile Terminal | Tablet pelayan | Peta meja, pesanan per course, minta bayar |
| Rasentra Kitchen | Layar dan printer dapur | Tiket per station, expeditor, layar antrean, analisis waktu saji |
| Rasentra Inventory | Handheld gudang | Terima kiriman, opname, karantina, cek stok |
| Rasentra Lokal | Server di outlet | Database outlet, antrean kirim ke pusat, publikasi dari pusat, backup, tutup hari |
| Rasentra Central | Server kantor pusat | Master data (Rasentra Core), sinkronisasi, posting SAP, rekonsiliasi, laporan, audit |
| SAP Business One | Server SAP tiap CV | Pembukuan: Invoices dan IncomingPayments dari penjualan outlet |

Jika internet outlet putus, aplikasi outlet tetap berjalan karena hanya bergantung pada Rasentra Lokal di LAN. Transaksi menunggu di antrean kirim dan terkirim otomatis begitu koneksi kembali.

## 0. Peran dan aplikasinya

Setiap petugas outlet masuk di satu layar login yang sama (`/pos/login`): sentuh nama, ketik PIN, lalu sistem membuka aplikasi sesuai perannya. Staf kantor pusat masuk ke Rasentra Central dengan email dan kata sandi.

| Peran | Aplikasi | Perangkat | Cara masuk | Akun demo Solo |
| --- | --- | --- | --- | --- |
| Kasir meatshop & grocery | Rasentra POS | PC kasir layar sentuh + scanner + printer 80 mm | Nama + PIN | Rina, 1111 |
| Kasir restoran | Rasentra POS | PC kasir layar sentuh + printer 80 mm | Nama + PIN | Tono, 1212 |
| Pelayan | Rasentra Mobile Terminal | Tablet | Nama + PIN | Lusi 3333, Agus 3434 |
| Chef, cook, bar | Rasentra Kitchen | Layar dapur per station | Nama + PIN | Chef Bambang 2222, Sri 2323, Dimas 2424 |
| Petugas gudang | Rasentra Inventory | Handheld scanner | Nama + PIN | Wawan 4444, Yuni 5555 |
| Kepala outlet | Rasentra POS + konsol Rasentra Lokal | PC kasir / PC kantor outlet | Nama + PIN | Hendra 9999 (limit Rp5.000.000), Maya 8888 (limit Rp1.000.000) |
| Admin kantor pusat | Rasentra Central | PC kantor | Email + kata sandi | admin@rasentra.test |
| Merchandising | Rasentra Central | PC kantor | Email + kata sandi | merch@rasentra.test |
| Keuangan | Rasentra Central | PC kantor | Email + kata sandi | finance@rasentra.test |

![Gambar 0.1 · Layar login outlet: daftar nama petugas di kiri, keypad PIN di kanan.](/assets/guide/0-1.jpg)

![Gambar 0.2 · Layar login Rasentra Central: email dan kata sandi.](/assets/guide/0-2.jpg)

**Aturan umum untuk semua petugas outlet**

- PIN bersifat pribadi. Jangan memakai PIN orang lain; setiap aksi tercatat atas nama pemilik PIN.
- Aksi berisiko (retur, override harga, void item yang sudah di dapur, jual pack ditahan, lepas atau buang barang karantina) selalu meminta PIN kepala outlet.
- Bila internet putus, aplikasi outlet tetap jalan lewat server Rasentra Lokal. Status **Offline · N antre** di POS berarti transaksi tersimpan dan akan terkirim otomatis.

## 1. Kasir meatshop & grocery (Rasentra POS)

Kasir retail menjual daging per Pack ID, barang ber-barcode dan barang curah, menerima pembayaran, dan menutup shift dengan hitungan kas.

### 1.1 Buka shift

1. Masuk dengan nama dan PIN.
2. Tekan **Shift** → **Buka shift**, ketik modal awal laci kas, lalu simpan.
3. Pastikan status di kanan atas **Central** atau **LAN** (bukan merah). Status **Offline · N antre** tetap aman untuk berjualan.

![Gambar 1.1 · Rasentra POS: lembar Buka shift dengan isian modal awal.](/assets/guide/1-1.jpg)

### 1.2 Menjual

1. Pilih kanal di panel pesanan: **Retail** (bawaan), Pickup atau Delivery.
2. Scan label Pack ID atau barcode barang. Scanner mengetik lalu Enter; bisa juga ketik kode di kolom pindai atau sentuh kartu produk.
3. Berat, harga dan promo terhitung otomatis. Satu Pack ID hanya bisa dijual sekali; scan ulang pack yang sama ditolak.
4. Untuk mengubah jumlah, harga atau menghapus baris, sentuh barisnya. Override harga meminta PIN kepala outlet dan alasan.
5. Pack yang hampir kedaluwarsa atau ditahan QC meminta PIN kepala outlet sebelum bisa dijual.

![Gambar 1.2 · Rasentra POS: keranjang berisi dua pack sirloin dengan baris promo borong 10%.](/assets/guide/1-2.jpg)

### 1.3 Pembayaran

1. Tekan **Bayar**.
2. Pilih metode: Tunai, EDC, QRIS atau campuran. Untuk tunai, ketik uang diterima; kembalian tampil otomatis.
3. Tekan **Selesaikan**. Struk 80 mm langsung tercetak.

![Gambar 1.3 · Rasentra POS: lembar Pembayaran. Pilih metode, ketik jumlah, lalu Terima pembayaran; ulangi untuk pembayaran campuran.](/assets/guide/1-3.jpg)

![Gambar 1.4 · Contoh struk 80 mm dengan baris promo dan Pack ID.](/assets/guide/1-4.jpg)

### 1.4 Tahan transaksi

Bila pelanggan masih memilih barang, tekan **Tahan**. Transaksi muncul di **Transaksi ditahan** dan bisa dilanjutkan kapan saja dalam shift yang sama.

### 1.5 Pickup dan delivery

1. Pilih kanal **Pickup** atau **Delivery**.
2. Isi nama dan telepon pemesan; delivery juga wajib alamat. Waktu ambil atau kirim dan catatan boleh diisi.
3. Lanjutkan seperti penjualan biasa. Data pemesan tercetak di struk.

![Gambar 1.5 · Rasentra POS: lembar data pemesan delivery.](/assets/guide/1-5.jpg)

### 1.6 Riwayat, cetak ulang dan retur

1. Tekan **Riwayat**, cari nomor struk, nama atau telepon pemesan.
2. **Cetak ulang**: struk berikutnya bertanda **SALINAN ke-N** dan tercatat di audit log.
3. **Retur barang** (paling lama 7 hari sejak transaksi): pilih pack atau barang, alasan dan cara pengembalian uang, lalu kepala outlet memasukkan PIN.
4. Bukti retur tercetak untuk ditandatangani pelanggan. Pack daging retur masuk karantina, bukan langsung ke stok jual.

![Gambar 1.6 · Rasentra POS: daftar Riwayat struk hari ini.](/assets/guide/1-6.jpg)

![Gambar 1.7 · Rasentra POS: lembar Retur barang dengan pilihan alasan dan metode refund.](/assets/guide/1-7.jpg)

### 1.7 Kas masuk/keluar dan tutup shift

1. Uang keluar dari laci (misalnya beli es batu) dicatat di **Shift** → **Kas keluar** dengan keterangan.
2. Laporan sementara bisa dicetak kapan saja dari menu Shift.
3. Di akhir shift, tekan **Tutup shift**, hitung uang di laci per pecahan, lalu simpan.
4. Laporan tutup shift tercetak: penjualan per kanal, pembayaran, retur, kas masuk/keluar, tunai seharusnya, hitungan dan selisih. Tanda tangani bersama kepala outlet.

![Gambar 1.8 · Rasentra POS: lembar Tutup shift dengan hitungan kas dan selisih.](/assets/guide/1-8.jpg)

![Gambar 1.9 · Contoh laporan tutup shift 80 mm.](/assets/guide/1-9.jpg)

## 2. Kasir restoran (Rasentra POS)

Kasir restoran menerima pembayaran meja yang dilayani pelayan, melayani takeaway dan delivery, dan mengirim pesanan langsung ke dapur. Buka shift, pembayaran, retur dan tutup shift sama dengan bagian 1.

### 2.1 Membayar tagihan meja

1. Tekan **Meja**. Daftar meja terisi tampil; meja yang **Minta bayar** ada paling atas.
2. Pilih meja. Pesanan meja masuk ke shift kasir ini dan tampil di panel pesanan.
3. Periksa isi tagihan bersama tamu, lalu tekan **Bayar** dan selesaikan seperti biasa.
4. Setelah lunas, kunjungan ditutup dan meja kembali kosong di tablet pelayan.

![Gambar 2.1 · Rasentra POS: lembar Meja dengan meja minta bayar di urutan atas.](/assets/guide/2-1.jpg)

### 2.2 Pesanan langsung di kasir (takeaway, dine-in tanpa pelayan)

1. Pilih kanal **Dine-in** atau **Takeaway**, lalu sentuh menu.
2. Tekan **Kirim dapur**. Setiap station menerima tiketnya sendiri dan tiket tercetak di printer dapur.
3. Bila pelanggan menambah porsi, tekan Kirim dapur lagi: dapur hanya menerima tambahannya, bertanda TAMBAHAN.
4. Nomor takeaway tampil di layar antrean dapur sampai pesanan diambil.

![Gambar 2.2 · Rasentra POS: pesanan dine-in di kasir dengan tombol Kirim dapur.](/assets/guide/2-2.jpg)

### 2.3 Masak di resto (cooking fee)

Pelanggan memilih daging atau ikan di etalase meatshop, lalu meminta dimasak di restoran.

1. Pilih kanal Dine-in, scan Pack ID-nya.
2. Sentuh baris pack, pilih **Masak di resto**, lalu metode masak (misalnya Bakar, Goreng, Saus Padang).
3. Jasa masak muncul sebagai baris terpisah sesuai tarif per kg atau per porsi.
4. Tekan **Kirim dapur**.

Bila daging sudah dibayar di kasir meatshop, minta pelayan memakai **Masak daging tamu** di tablet (bagian 3.6). Hak masak dari satu struk hanya bisa dipakai sekali.

![Gambar 2.3 · Rasentra POS: pilihan metode Masak di resto dan baris jasa bakar.](/assets/guide/2-3.jpg)

### 2.4 Void item yang sudah di dapur

1. Sentuh baris, pilih hapus.
2. Item yang belum dimasak langsung batal. Item yang sudah dimasak meminta alasan dan PIN kepala outlet, lalu menjadi **Minta batal** di layar station sampai dapur mengonfirmasi.

![Gambar 2.4 · Rasentra POS: persetujuan pembatalan item yang sedang dimasak, dengan alasan dan PIN kepala outlet.](/assets/guide/2-4.jpg)

## 3. Pelayan (Rasentra Mobile Terminal)

Pelayan mencatat pesanan di meja dengan tablet, mengirimnya ke dapur per gelombang, mengantar hidangan, lalu meminta kasir menagih. Pelayan tidak menerima uang.

### 3.1 Membaca peta meja

| Tampilan meja | Arti |
| --- | --- |
| Putih | Kosong |
| Hijau | Terisi: jumlah tamu, lama duduk, total, nama pelayan |
| Kuning | Minta bayar, menunggu kasir |
| Penanda biru "N siap" | Hidangan siap diantar dari dapur |
| Penanda merah | Ada item yang belum dikirim ke dapur |

![Gambar 3.1 · Mobile Terminal: peta meja per area (Indoor, Lesehan, VIP, Teras).](/assets/guide/3-1.jpg)

### 3.2 Membuka meja

1. Sentuh meja kosong, isi jumlah tamu, tekan **Buka**.
2. Setiap pembukaan meja membuat kunjungan baru. Tablet yang masih membuka meja lama tidak bisa lagi menambah pesanan ke tamu berikutnya.

### 3.3 Mencatat pesanan

1. Cari menu atau pilih kategori, lalu sentuh menu.
2. Pilih modifier. Kematangan steak wajib dipilih; level pedas, extra butter, gula dan es sesuai kategori.
3. Atur jumlah, catatan cepat atau bebas, dan urutan saji: Langsung, Pembuka, Utama atau Penutup.
4. Tekan **Tambah**. Baris baru berstatus **Belum dikirim**.

![Gambar 3.2 · Mobile Terminal: dialog menu steak dengan pilihan kematangan dan urutan saji.](/assets/guide/3-2.jpg)

![Gambar 3.3 · Mobile Terminal: panel pesanan dengan status per item.](/assets/guide/3-3.jpg)

### 3.4 Mengirim ke dapur per gelombang

1. **Kirim dapur** mengirim semua item yang belum dikirim.
2. **Kirim Pembuka** mengirim pembuka dulu; kirim Utama saat tamu siap.
3. Status item berganti otomatis: Antre, Dimasak, Siap diantar, Disajikan.

### 3.5 Mengantar hidangan

Saat dapur selesai, meja bertanda biru. Ambil hidangan di pass, lalu tekan **Antar N hidangan siap** agar status berubah menjadi Disajikan.

![Gambar 3.4 · Mobile Terminal: tombol Antar hidangan siap di panel pesanan.](/assets/guide/3-4.jpg)

### 3.6 Masak daging tamu

Untuk daging atau ikan yang dibeli tamu di kasir meatshop: tekan **Masak daging tamu**, ketik nomor struk dan Pack ID, pilih metode masak, kematangan dan saus. Cooking fee masuk ke tagihan meja.

![Gambar 3.5 · Mobile Terminal: formulir Masak daging tamu.](/assets/guide/3-5.jpg)

### 3.7 Membatalkan item

- Item **Belum dikirim** bisa langsung dihapus.
- Item yang sudah di dapur butuh alasan dan PIN kepala outlet, lalu menjadi Minta batal di layar station.

### 3.8 Pindah meja, ubah jumlah tamu, tutup meja

- **Pindah meja** memindahkan seluruh pesanan ke meja kosong lain.
- **Tamu** mengubah jumlah tamu.
- Bila tamu pergi sebelum memesan, tutup meja dari tablet. Meja yang sudah ada pesanannya hanya bisa ditutup lewat pembayaran di kasir.

### 3.9 Minta bayar

Tekan **Minta bayar**. Meja berubah kuning dan muncul paling atas di daftar Meja kasir. Antar tamu ke kasir atau bawa tagihannya; kasir menyelesaikan pembayaran (bagian 2.1).

![Gambar 3.6 · Mobile Terminal: meja berstatus Minta bayar di peta meja.](/assets/guide/3-6.jpg)

## 4. Chef, cook dan bar (Rasentra Kitchen)

Setiap station melihat tiketnya sendiri, memasak sesuai urutan waktu, dan memajukan status sampai siap diantar. Expeditor memastikan satu meja keluar lengkap.

### 4.1 Memilih layar perangkat

1. Masuk dengan nama dan PIN, lalu buka **Rasentra Kitchen**.
2. Pilih layar untuk perangkat ini: station (Grill, Hot kitchen, Cold kitchen, Bar, Pastry, Packing), **Expeditor**, **Layar antrean**, **Produksi** atau **Analisis waktu saji**. Pilihan disimpan di perangkat.

![Gambar 4.1 · Rasentra Kitchen: pilihan layar untuk perangkat ini.](/assets/guide/4-1.jpg)

### 4.2 Mengerjakan tiket

1. Tiket baru membunyikan nada pendek dan tampil dengan label meja atau nomor pesanan, modifier dan catatan.
2. Tekan **Mulai masak**. Bahan sesuai resep menu (lihat 8.4) dipotong dari stok saat ini, termasuk saus hasil produksi.
3. Tekan **Siap** saat hidangan selesai. Pelayan melihat penanda siap di tabletnya.
4. Alternatif cepat: scan barcode di tiket cetak untuk memajukan tiket.
5. Salah tekan Siap? Sentuh item lalu kembalikan ke Dimasak.

| Warna timer | Arti |
| --- | --- |
| Hijau | Masih dalam target |
| Kuning | Sudah 75% dari target waktu station |
| Merah | Lewat target, dahulukan |

![Gambar 4.2 · Rasentra Kitchen: layar station Grill dengan tiket hijau, kuning dan merah.](/assets/guide/4-2.jpg)

![Gambar 4.3 · Contoh tiket dapur 80 mm dengan barcode untuk bump.](/assets/guide/4-3.jpg)

### 4.3 Minta batal dari kasir atau pelayan

1. Item yang sudah dimasak lalu dibatalkan tampil merah **Minta batal** beserta alasannya.
2. Hentikan masakan, lalu tekan **Konfirmasi batal**.
3. Bahan yang sudah terpakai otomatis tercatat sebagai waste. Jangan menghapus atau menyembunyikan item tanpa konfirmasi.

![Gambar 4.4 · Rasentra Kitchen: tiket dengan item Minta batal (merah) beserta alasannya.](/assets/guide/4-4.jpg)

### 4.4 Expeditor

Expeditor melihat pesanan per meja dari semua station. Tombol **Lengkap, sajikan** baru aktif setelah semua station menandai Siap; sebelum itu tampil **Menunggu station lain**.

![Gambar 4.5 · Rasentra Kitchen: layar Expeditor per meja.](/assets/guide/4-5.jpg)

### 4.5 Layar antrean pelanggan

Layar antrean (`/kitchen/queue`) dipasang menghadap pelanggan. Isinya nomor takeaway, pickup dan delivery yang sedang disiapkan dan yang siap diambil. Nomor yang sudah diambil hilang setelah 3 menit.

![Gambar 4.6 · Layar antrean: kolom Disiapkan dan Siap diambil.](/assets/guide/4-6.jpg)

### 4.6 Analisis waktu saji (chef kepala)

Lihat rata-rata, median, P90, waktu antre dan waktu masak per station, per menu dan per jam, beserta jumlah yang terlambat. Pakai untuk briefing harian dan mengatur jumlah cook per jam sibuk.

![Gambar 4.7 · Rasentra Kitchen: analisis waktu saji per station.](/assets/guide/4-7.jpg)

### 4.7 Produksi saus, prep dan potong & porsi

Bahan setengah jadi dibuat di layar **Produksi** (`/kitchen/production`). Hasilnya masuk ke stok outlet. Setelah itu, menu yang memakainya memotong hasil produksi tersebut, bukan bahan asalnya. Setiap produksi menjadi dokumen bernomor `SLO-PR…` yang dikirim ke Rasentra Central lalu ke SAP. Di SAP, bahan keluar lewat Goods Issue dan hasil masuk lewat Goods Receipt.

**Dari resep** (saus, kaldu, bumbu berstok):

1. Pilih resep, misalnya **Saus Lada Hitam (produksi)**. Hanya resep berjenis *prep berstok* yang sudah disetujui kantor pusat yang tampil.
2. Pilih jumlah resep (×1, ×2, …) atau ketik target hasil. Kolom **Standar** menghitung bahan sesuai resep.
3. Bila pemakaian aktual berbeda, koreksi kolom **Dipakai**. Angka yang dikoreksi berwarna kuning. Kolom **Stok** merah berarti stok bahan di sistem kurang.
4. Timbang hasilnya, lalu isi **Hasil timbang aktual**. Susut dihitung otomatis dari target dikurangi hasil.
5. Bila susut di atas 2%, pilih alasannya (Susut masak, Tumpah, Gosong / gagal, dan lainnya), lalu tekan **Simpan produksi**.

![Gambar 4.8 · Rasentra Kitchen: produksi dua resep Saus Lada Hitam dengan pemakaian butter dikoreksi, hasil 3,82 kg dan susut 4,5%.](/assets/guide/4-8.jpg)

**Potong & porsi** (daging atau ikan utuh menjadi porsi siap masak):

1. Pilih bahan yang dipotong, misalnya **Sirloin Utuh**, lalu isi beratnya.
2. Isi setiap hasil beserta beratnya: porsi steak, trimming, tulang. Tekan **+ Tambah hasil** untuk baris berikutnya.
3. Susut = bahan masuk dikurangi semua hasil. Hasil tidak boleh melebihi bahan masuk. Susut di atas 2% wajib diberi alasan.
4. Tekan **Simpan potong & porsi**.

![Gambar 4.9 · Rasentra Kitchen: potong sirloin utuh 5,4 kg menjadi porsi 4,6 kg dan trimming 0,62 kg.](/assets/guide/4-9.jpg)

Panel kanan menampilkan stok hasil prep di outlet dan semua produksi hari ini: nomor dokumen, petugas, bahan, hasil dan susut. Kepala outlet memakai panel ini untuk mencocokkan hasil timbang dengan saus yang tersedia sebelum servis.

## 5. Petugas gudang (Rasentra Inventory)

Petugas gudang memakai handheld dengan satu kolom scan untuk empat tab: **Terima**, **Opname**, **Karantina** dan **Cek stok**. Setiap keputusan stok menjadi dokumen yang dikirim ke kantor pusat dan SAP.

![Gambar 5.1 · Rasentra Inventory: tab utama di handheld.](/assets/guide/5-1.jpg)

### 5.1 Penerimaan kiriman gudang pusat

1. Buka tab **Terima**. Daftar **Masih dalam perjalanan** menampilkan pack yang dikirim ke outlet ini.
2. Scan label setiap pack yang datang dan periksa fisik: suhu, vakum, label, tanggal kedaluwarsa.
3. Pack baik: tekan **Terima**. Pack masuk stok jual.
4. Pack rusak (misalnya vakum bocor): tekan **Tolak**, isi alasan. Pack masuk lokasi karantina, bukan stok jual.

![Gambar 5.2 · Rasentra Inventory: penerimaan dengan daftar kiriman dalam perjalanan.](/assets/guide/5-2.jpg)

![Gambar 5.3 · Rasentra Inventory: hasil Tolak. Pack masuk karantina dan alasan tercatat.](/assets/guide/5-3.jpg)

### 5.2 Stok opname

1. Buka tab **Opname**. Pilih sesi yang ditugaskan, atau kepala outlet membuat **sesi opname baru**.
2. Scan QR lokasi rak atau chiller lebih dulu (misalnya `RAK`, `ESF`, `FRZ`), baru barangnya.
3. Pack ID dihitung sekali per sesi; scan ulang tidak menambah jumlah.
4. Barang curah atau kemasan terbuka wajib ditimbang ulang; jangan memakai berat label lama.
5. Saldo sistem tidak ditampilkan saat menghitung (blind count).
6. Kepala outlet menekan **Review selisih**. Selisih di atas ambang wajib dihitung ulang oleh petugas lain sebelum **Setujui dan posting penyesuaian**.
7. Bila Wi-Fi hilang, aplikasi menahan input dengan pesan jelas. Lanjutkan setelah tersambung.

![Gambar 5.4 · Rasentra Inventory: sesi opname dengan lokasi aktif dan daftar barang terhitung.](/assets/guide/5-4.jpg)

![Gambar 5.5 · Rasentra Inventory: Review selisih per produk.](/assets/guide/5-5.jpg)

### 5.3 Karantina dan hampir kedaluwarsa

Tab **Karantina** berisi pack yang ditolak saat terima, retur pelanggan dan barang rusak, serta daftar **Hampir kedaluwarsa** (2 hari ke depan).

1. Periksa barangnya.
2. Layak jual: **Lepas ke stok**. Tidak layak: **Buang** (waste) dengan alasan.
3. Pack hampir kedaluwarsa bisa **Tahan**; kasir lalu butuh PIN kepala outlet untuk menjualnya.
4. Lepas dan buang butuh persetujuan: masukkan **PIN kepala outlet** di layar, atau kepala outlet memutuskan langsung dari akunnya.

![Gambar 5.6 · Rasentra Inventory: tab Karantina dengan tombol Lepas ke stok dan Buang.](/assets/guide/5-6.jpg)

### 5.4 Cek stok

Scan barang atau pack untuk melihat saldo per lokasi dan sepuluh pergerakan terakhir. Dari layar ini juga bisa langsung tahan, lepas atau buang.

![Gambar 5.7 · Rasentra Inventory: Cek stok Pack ID dengan status, berat, kedaluwarsa dan tombol Tahan atau Buang.](/assets/guide/5-7.jpg)

| Dokumen | Kapan terbentuk | Di SAP |
| --- | --- | --- |
| `SLO-W…` | Buang (waste), batal masak setelah dimasak | Goods Issue |
| `SLO-RL…` | Lepas karantina ke stok | Tidak ada dokumen |
| `SLO-OP…` | Selisih opname disetujui | Goods Issue (kurang) atau Goods Receipt (lebih) |

## 6. Kepala outlet (persetujuan, Rasentra Lokal, tutup hari)

Kepala outlet menyetujui aksi berisiko dengan PIN, mengawasi server outlet, dan menutup hari bisnis. Asisten kepala bisa menyetujui sampai limitnya sendiri (demo: Maya Rp1.000.000, Hendra Rp5.000.000).

### 6.1 Persetujuan dengan PIN

Kepala outlet dipanggil ke kasir, tablet atau handheld untuk mengetik PIN pada aksi berikut. Periksa alasannya sebelum mengetik PIN; setiap persetujuan tercatat di audit log atas nama kepala outlet.

| Aksi | Dari aplikasi | Yang diperiksa |
| --- | --- | --- |
| Retur penjualan | POS | Struk asal, kondisi barang, maksimal 7 hari |
| Override harga | POS | Alasan, nilai dalam limit approval |
| Jual pack ditahan atau hampir kedaluwarsa | POS | Kondisi fisik pack |
| Void item yang sudah di dapur | POS, Mobile Terminal | Alasan batal, apakah sudah dimasak |
| Lepas atau buang barang karantina | Inventory | Kondisi barang, alasan buang |
| Selisih opname | Inventory | Hitung ulang oleh petugas lain bila di atas ambang |

![Gambar 6.1 · Dialog persetujuan: pilih kepala outlet dan ketik PIN.](/assets/guide/6-1.jpg)

### 6.2 Konsol Rasentra Lokal

Buka **Server outlet** dari Rasentra POS, atau alamat `/lokal`.

- **Status server**: koneksi ke Central, antrean kirim, cursor publikasi, penjualan hari ini, shift yang buka, ruang disk, database dan backup.
- **Antrean kirim**: event yang belum diterima Central beserta error terakhir. Tekan **Kirim sekarang**, atau antrekan ulang event yang berhenti dicoba.
- **Harga, promo & resep**: versi harga, promo aktif dan mendatang, cooking fee, dan riwayat tarik publikasi. Pakai untuk memastikan harga baru dari kantor pusat sudah masuk sebelum toko buka.
- **Backup**: berjalan otomatis pukul 02.00; tombol backup tersedia untuk backup manual.

![Gambar 6.2 · Rasentra Lokal: halaman Status server.](/assets/guide/6-2.jpg)

![Gambar 6.3 · Rasentra Lokal: Antrean kirim dengan event gagal dan tombol kirim ulang.](/assets/guide/6-3.jpg)

### 6.3 Tutup hari

1. Pastikan semua kasir sudah tutup shift. Konsol memberi peringatan bila masih ada shift yang buka.
2. Buka **Tutup hari**, periksa pratinjau: jumlah struk, penjualan, pembayaran dan selisih kas per shift.
3. Tekan kirim. Ringkasan dikirim ke Central untuk direkonsiliasi dengan transaksi yang diterima.
4. Bila lupa, server menutup hari kemarin otomatis pukul 04.00.

![Gambar 6.4 · Rasentra Lokal: pratinjau Tutup hari per shift.](/assets/guide/6-4.jpg)

### 6.4 Checklist harian kepala outlet

- [ ] Sebelum buka: status server hijau, publikasi harga dan promo terbaru sudah diterapkan.
- [ ] Selama operasional: antrean kirim tidak menumpuk; tangani karantina dan pack hampir kedaluwarsa.
- [ ] Setelah tutup: semua shift ditutup, laporan shift ditandatangani, tutup hari terkirim.

## 7. Admin kantor pusat (Rasentra Central)

Admin pusat memegang semua menu: memantau sinkronisasi outlet dan posting SAP, mengelola master, serta menyetujui (checker) harga dan promo yang disusun merchandising. Masuk di `/core` dengan email dan kata sandi.

![Gambar 7.1 · Rasentra Central: Dashboard grup dengan ringkasan per perusahaan.](/assets/guide/7-1.jpg)

### 7.1 Pemeriksaan pagi

1. Buka **Status Central**: kondisi tiap Rasentra Lokal, publikasi yang belum diterapkan, koneksi tiap instance SAP dan job terjadwal.
2. Server outlet yang lama tidak heartbeat, versinya tertinggal, atau tertinggal publikasi: hubungi kepala outlet.
3. Buka **Dokumen SAP**: tangani yang **perlu review**, **gagal** atau **parsial** (bagian 7.2).

![Gambar 7.2 · Rasentra Central: Status Central (modul Sinkronisasi, Posting SAP, Core).](/assets/guide/7-2.jpg)

### 7.2 Modul Posting SAP

- **Dokumen SAP**: per instance (`sap_solo`, `sap_lombok`). Buka dokumen untuk melihat payload dan error, lalu **posting ulang** setelah penyebabnya diperbaiki.
- **Perlu review** muncul saat SAP timeout. Cek dulu di SAP apakah dokumen sudah terbentuk (field `U_POS_SRC`) sebelum posting ulang, agar tidak dobel.
- **Mapping SAP**: item, gudang, akun kas/bank, akun penyesuaian stok. Struk tertahan karena item belum dimapping akan terposting otomatis setelah mapping ditambahkan.
- **Cek koneksi** tiap instance tersedia di Status Central; cek otomatis berjalan tiap 15 menit.

![Gambar 7.3 · Rasentra Central: daftar Dokumen SAP dengan status perlu review dan gagal.](/assets/guide/7-3.jpg)

![Gambar 7.4 · Rasentra Central: Mapping SAP per instance, dengan formulir tambah atau ubah mapping.](/assets/guide/7-4.jpg)

### 7.3 Modul Sinkronisasi

- **Sinkronisasi outlet**: antrean dan event per outlet.
- **Perangkat & token**: buat token `outlet_server` untuk memasang server outlet baru. Token tampil sekali; salin dan serahkan ke teknisi. Cabut token perangkat yang hilang.
- **Impor XML NMEXML** (MSI/MSR) untuk data dari sistem lama.

![Gambar 7.5 · Rasentra Central: Perangkat & token dengan token baru yang tampil sekali.](/assets/guide/7-5.jpg)

### 7.4 Master data (Rasentra Core)

- **Perusahaan & outlet**: database SAP per perusahaan, nama usaha retail dan restoran, zona waktu outlet, lokasi beserta QR rak.
- **Pengguna**: petugas outlet (peran, PIN, limit approval) dan staf pusat. Nonaktifkan pengguna yang keluar; jangan menghapus agar riwayat tetap utuh.
- Perubahan master otomatis diterbitkan ke semua server outlet dalam 30 detik.

![Gambar 7.6 · Rasentra Central: formulir pengguna outlet dengan PIN dan limit approval.](/assets/guide/7-6.jpg)

### 7.5 Menyetujui harga dan promo (checker)

Versi harga, promo dan resep yang disusun merchandising harus diterbitkan oleh orang lain.

1. Buka draft di **Versi harga** atau **Promotion Designer**.
2. Periksa perubahan dan hasil simulasi keranjang contoh.
3. Tekan **Terbitkan**. Outlet menerima publikasi pada tarikan berikutnya.

![Gambar 7.7 · Rasentra Central: draft versi harga siap diterbitkan.](/assets/guide/7-7.jpg)

## 8. Merchandising (Rasentra Central)

Merchandising menyusun produk, harga, promo dan resep. Setiap perubahan harga, promo dan resep dibuat sebagai draft, lalu diterbitkan oleh orang lain (maker-checker).

### 8.1 Produk

1. Buka **Produk** → tambah atau ubah produk: kode, nama, kategori, jenis (retail, menu, bahan), satuan, station dapur untuk menu.
2. Produk baru otomatis terkirim ke semua server outlet; harganya mengikuti versi harga.

![Gambar 8.1 · Rasentra Central: formulir produk.](/assets/guide/8-1.jpg)

### 8.2 Versi harga

1. Buka **Versi harga** → buat draft dengan tanggal berlaku.
2. Ubah harga per produk dan per kanal (retail, dine-in).
3. Kirim untuk diterbitkan. Admin atau checker lain menerbitkannya; Anda tidak bisa menerbitkan draft sendiri.

![Gambar 8.2 · Rasentra Central: draft versi harga dengan kolom harga lama dan baru.](/assets/guide/8-2.jpg)

### 8.3 Promotion Designer

1. Buka **Promotion Designer** → buat promo: jenis (diskon persen, potongan, beli X gratis Y, harga spesial, borong bertingkat), produk atau kategori, kanal, periode, batas maksimal potongan.
2. Tentukan cakupan: semua outlet, wilayah, atau outlet tertentu, dan penanggung biaya (perusahaan atau supplier).
3. Tekan **Simulasikan** pada keranjang contoh untuk memastikan potongannya benar.
4. Draft diterbitkan oleh orang lain. Untuk menghentikan promo yang sedang berjalan, gunakan **Hentikan**; untuk mengubahnya, buat versi baru.

![Gambar 8.3 · Rasentra Central: Promotion Designer dengan aturan borong bertingkat.](/assets/guide/8-3.jpg)

![Gambar 8.4 · Rasentra Central: hasil simulasi keranjang sebelum dan sesudah promo.](/assets/guide/8-4.jpg)

### 8.4 Resep (bill of material) dan cooking fee

Setiap menu restoran punya resep: daftar bahan dan takaran untuk hasil tertentu, misalnya per 1 porsi atau per 2 kg saus. Resep dikelola di **Rasentra Central → Resep**.

| Jenis resep | Contoh | Cara bahan dipotong |
| --- | --- | --- |
| Menu | Sirloin Steak 200 g, Selat Solo, Es Teh Manis | Saat dapur menekan **Mulai masak**, sesuai jumlah porsi dan modifier |
| Prep berstok | Saus Lada Hitam (produksi) | Dibuat lewat layar **Produksi** (4.7). Menu memotong sausnya sebagai barang jadi |
| Prep virtual | Saus Padang | Tidak distok. Saat menu dimasak, bahan asalnya (cabai, santan, minyak) langsung dipotong |
| Jasa masak | Jasa Masak Steak (Cooking Fee) | Hanya bahan tambahan (butter, saus). Daging milik pelanggan tidak dipotong lagi |

![Gambar 8.7 · Rasentra Central: daftar resep dengan jenis, versi aktif dan hasil per resep.](/assets/guide/8-7.jpg)

Contoh resep Sirloin Steak 200 g per porsi: Bahan Sirloin Portion 0,200 kg, Kentang 0,150 kg, Sayuran Campur 0,080 kg, Saus Lada Hitam (produksi) 0,050 kg dan Butter 0,010 kg. Kolom **Hanya bila modifier** membuat bahan hanya terpotong bila tamu memilih modifier itu, misalnya *Extra butter* pada Wagyu.

![Gambar 8.8 · Rasentra Central: resep Sirloin Steak 200 g dengan saus hasil produksi sebagai bahan.](/assets/guide/8-8.jpg)

**Mengubah resep:**

1. Buka resep, lalu tekan **Buat draft versi baru**. Versi aktif tetap berlaku selama draft disusun.
2. Ubah bahan, takaran, hasil per resep dan cakupan outlet, lalu simpan draft.
3. Rekan pusat lain (admin pusat atau merchandising lain) memeriksa lalu menekan **Setujui & kirim ke outlet**. Penyunting terakhir draft tidak bisa menyetujui draftnya sendiri.
4. Versi baru dikirim ke semua outlet lewat publikasi. Versi lama berstatus retired. Pemakaian bahan yang sudah tercatat tetap memakai versi lama, sehingga histori tidak berubah.

Sistem menolak resep yang saling memakai, misalnya saus A memakai saus B yang memakai saus A.

- **Cooking fee**: tarif jasa masak per metode (per kg atau per porsi, bisa bertingkat). Ubah tarif dengan membuat aturan pengganti, bukan menimpa yang lama.

![Gambar 8.5 · Rasentra Central: resep Saus Lada Hitam (prep berstok) dengan hasil 2 kg per resep.](/assets/guide/8-5.jpg)

Hasil produksi, susut dan waste dapur per outlet terlihat di **Laporan → Opname & waste**.

![Gambar 8.9 · Rasentra Central: dokumen produksi dapur (bahan dan hasil) di laporan Opname & waste.](/assets/guide/8-9.jpg)

### 8.5 Laporan untuk merchandising

Pantau **Laporan penjualan** per produk dan **Laporan promo** per penanggung biaya untuk menilai promo yang berjalan.

![Gambar 8.6 · Rasentra Central: laporan promo per penanggung biaya.](/assets/guide/8-6.jpg)

## 9. Keuangan (Rasentra Central)

Keuangan memastikan setiap hari bisnis setiap outlet cocok antara kasir, Central dan SAP. Menu yang tersedia: Dashboard, Sinkronisasi, Rekonsiliasi harian, Dokumen SAP, laporan dan audit log.

### 9.1 Rekonsiliasi harian

1. Buka **Rekonsiliasi harian**. Setiap outlet per tanggal bisnis berstatus **Cocok**, **Menunggu struk** atau **Selisih**.
2. **Menunggu struk**: ringkasan tutup hari menyebut struk yang belum diterima Central. Biasanya server outlet sempat offline; cek antrean kirim outlet itu bersama kepala outlet. Rekonsiliasi diulang otomatis tiap jam.
3. **Selisih**: bandingkan jumlah struk, penjualan, pembayaran dan retur antara tutup hari dan data yang diterima. Catat temuan dan tindak lanjuti dengan kepala outlet.

![Gambar 9.1 · Rasentra Central: Rekonsiliasi harian dengan status Cocok dan Menunggu struk.](/assets/guide/9-1.jpg)

### 9.2 Dokumen SAP

| Transaksi Rasentra | Dokumen SAP |
| --- | --- |
| Penjualan | A/R Invoice + Incoming Payment |
| Retur | A/R Credit Memo (merujuk invoice asal) + Outgoing Payment |
| Waste, opname kurang | Goods Issue |
| Opname lebih | Goods Receipt |

1. Saring dokumen **gagal** dan **parsial**. Baca pesan error dari SAP (misalnya akun kas belum dimapping, periode terkunci).
2. Perbaiki penyebabnya di SAP atau minta admin menambah mapping, lalu **posting ulang**.
3. Retur yang invoice asalnya belum terposting berstatus menunggu dan diposting otomatis setelah invoice-nya masuk.

![Gambar 9.2 · Rasentra Central: detail dokumen SAP dengan pesan error dan tombol posting ulang.](/assets/guide/9-2.jpg)

### 9.3 Laporan

- **Penjualan** per perusahaan dan outlet, sudah dikurangi retur; unduh CSV untuk pengolahan lanjut.
- **Promo** per penanggung biaya, sebagai dasar klaim ke supplier.
- **Opname dan waste**: dokumen penyesuaian stok per outlet.
- **Audit log**: siapa melakukan apa dan kapan, termasuk cetak ulang struk dan persetujuan PIN.

![Gambar 9.3 · Rasentra Central: laporan penjualan per outlet dengan tombol unduh CSV.](/assets/guide/9-3.jpg)

![Gambar 9.4 · Rasentra Central: audit log dengan filter aksi.](/assets/guide/9-4.jpg)

### 9.4 Checklist keuangan

- [ ] Harian: semua outlet kemarin berstatus Cocok; dokumen SAP gagal ditangani.
- [ ] Mingguan: klaim promo supplier dari laporan promo; tinjau waste per outlet.
- [ ] Bulanan: tidak ada dokumen SAP gagal atau perlu review sebelum tutup buku.
