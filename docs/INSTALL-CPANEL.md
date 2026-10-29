# Instalasi Rasentra di cPanel

Panduan ini memasang Rasentra di hosting cPanel memakai **Terminal** cPanel. Paket zip rilis
(`rasentra-cpanel-*.zip`) sudah berisi folder `vendor`, jadi Composer tidak perlu dijalankan di server.

## Syarat

- PHP 8.3 atau lebih baru (cPanel → **MultiPHP Manager**), dengan ekstensi `pdo_mysql`, `mbstring`,
  `bcmath`, `intl`, `fileinfo`, `openssl`, `zip`.
- MySQL 8 / MariaDB 10.6 ke atas.
- Akses **Terminal** di cPanel (menu Advanced → Terminal).

## 1. Siapkan database

Di cPanel → **MySQL® Databases**:

1. Buat database, misalnya `cpaneluser_rasentra`.
2. Buat user, misalnya `cpaneluser_rasentra`, dengan kata sandi kuat.
3. Tambahkan user ke database dengan hak **ALL PRIVILEGES**.

## 2. Unggah dan ekstrak

Unggah `rasentra-cpanel-*.zip` ke folder home (`/home/cpaneluser`) lewat **File Manager**, lalu di Terminal:

```sh
cd ~
unzip -q rasentra-cpanel-*.zip      # menghasilkan folder ~/rasentra
cd ~/rasentra
php -v                              # pastikan 8.3+; jika bukan, pakai /opt/cpanel/ea-php83/root/usr/bin/php
```

## 3. Konfigurasi `.env`

```sh
cp .env.cpanel.example .env
nano .env
```

Isi minimal:

```ini
APP_URL=https://rasentra.domainanda.com
DB_DATABASE=cpaneluser_rasentra
DB_USERNAME=cpaneluser_rasentra
DB_PASSWORD=katasandi-database
```

`RASENTRA_PROFILE=central` untuk server kantor pusat (Rasentra Central + Core). Isi kredensial
SAP B1 (`SAP_SOLO_*`, `SAP_LOMBOK_*`) bila posting ke SAP akan diaktifkan.

## 4. Pasang

```sh
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force          # data induk: dua CV dan outlet
chmod -R 775 storage bootstrap/cache
php artisan storage:link              # abaikan jika hosting melarang symlink
php artisan optimize
```

### Server demo untuk tamu

Templat `.env.cpanel.example` menyalakan mode demo (`RASENTRA_DEMO=true`): landing page menampilkan
tombol **Coba sebagai tamu** yang membuka halaman `/demo`. Dari sana tamu masuk ke Central, POS,
Mobile Terminal, Kitchen, Inventory dan Lokal dengan akun contoh, tanpa kata sandi atau PIN.
Muat data contohnya sekali:

```sh
php artisan rasentra:demo-reset --force
```

Cron di langkah 6 membangun ulang data contoh setiap hari pukul `RASENTRA_DEMO_RESET_AT` (bawaan 03:00 WIB).
Tamu tidak bisa mengubah pengguna, outlet atau pemasangan server.

Untuk server produksi dengan data sungguhan, set `RASENTRA_DEMO=false` sebelum langkah 4 dan
lewati perintah di atas. **Jangan** jalankan `rasentra:demo-reset` di server produksi: perintah itu
menghapus seluruh database (perintah menolak berjalan bila mode demo mati).

Login manual tetap tersedia: Central `admin@rasentra.test` / `password`; POS outlet Solo pilih nama
lalu PIN (Hendra 9999, Tono 1212, Lusi 3333).

## 5. Arahkan domain ke folder `public`

Pilih salah satu:

- **Subdomain / addon domain (disarankan):** cPanel → **Domains** → buat atau edit domain, isi
  *Document Root* `rasentra/public`.
- **Domain utama (`public_html`):** di Terminal

  ```sh
  mv ~/public_html ~/public_html_lama
  ln -s ~/rasentra/public ~/public_html
  ```

Aktifkan SSL di cPanel → **SSL/TLS Status** (AutoSSL).

## 6. Jadwal (cron)

cPanel → **Cron Jobs** → *Once Per Minute* (`* * * * *`), perintah:

```sh
cd ~/rasentra && php artisan schedule:run >> /dev/null 2>&1
```

Di profil central jadwal ini memposting penjualan ke SAP, mengecek koneksi SAP dan menjalankan
rekonsiliasi tutup hari.

## Memperbarui versi

```sh
cd ~
cp rasentra/.env rasentra.env.bak
unzip -oq rasentra-cpanel-BARU.zip    # menimpa file aplikasi, .env tidak ikut tertimpa
cd ~/rasentra
php artisan migrate --force
php artisan optimize
```

## Masalah umum

| Gejala | Solusi |
| --- | --- |
| Halaman 500 | Lihat `storage/logs/laravel.log`; jalankan `chmod -R 775 storage bootstrap/cache` |
| `could not find driver` | Aktifkan ekstensi `pdo_mysql` di cPanel → Select PHP Version |
| Perubahan `.env` tidak terbaca | `php artisan optimize:clear && php artisan optimize` |
| Halaman tampil daftar file / 404 | *Document Root* belum mengarah ke `rasentra/public` |
