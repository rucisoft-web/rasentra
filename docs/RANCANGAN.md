# Rancangan Perangkat Lunak Rasentra (Laravel 13 + MySQL)

Oct 29, 2026 · @Jaya Martha

> **Catatan:** spesifikasi terbaru (v2.0, nama aplikasi Rasentra POS, Mobile Terminal, Kitchen, Inventory, Lokal, Central dan Core) ada di [Spesifikasi Lengkap Rasentra v2.0](https://claude.ai/artifact/MidWQUchfyxN2rsywyT8aw). Dokumen di bawah ini adalah rancangan awal dan masih memakai nama lama (server outlet, Rasentra Mobile).
>
> **Pemakai sekarang: Indoguna Group.** Rasentra dipakai CV Solo Kulina Utama (instance SAP `sap_solo`, outlet Solo: Meatshop & Grocery dan restoran R&B Solo) dan CV Lombok Kulina Utama (instance SAP `sap_lombok`, outlet Lombok: Meatshop dan restoran El Primo, zona waktu WITA). SAP B1 berjalan di SQL Server dan diakses lewat Service Layer. Rujukan ke CV Pasar Tani dan 20 outlet di bawah adalah konteks rancangan awal.

Rancangan ini menerjemahkan Spesifikasi POS CV Pasar Tani v1.3 menjadi arsitektur, skema data, kontrak API dan rencana uji untuk Laravel 13 dan MySQL 8.4, dengan satu server lokal per outlet dan SAP B1 sebagai pembukuan resmi.

Nama program: **Rasentra**. Aplikasinya: **Rasentra POS** (kasir retail dan resto), **Rasentra Mobile Terminal** (tablet pelayan untuk order di meja), Rasentra Mobile (handheld cek inventory: penerimaan barang, stok opname, cek saldo per lokasi dan Pack ID), **Rasentra Kitchen** (KDS dan expeditor) dan **Rasentra Central** (portal pusat, sinkronisasi, SAP bridge). Di kode, nama ini menjadi folder `resources/js/apps/{pos,mobile,kitchen,central}`.

## 1. Ringkasan dan keputusan arsitektur

Sistem dibangun sebagai satu aplikasi Laravel 13 (monolit modular) yang dipasang dengan dua profil: `central` di pusat dan `outlet` di setiap server outlet. Outlet melayani kasir, meja, KDS, promo dan stok secara mandiri lewat LAN; pusat mengendalikan master, promo, resep dan meneruskan transaksi ke SAP B1.

| Keputusan | Ketetapan rancangan | Alasan dari spesifikasi |
| --- | --- | --- |
| Basis kode | Satu repositori, profil via `APP_PROFILE=central\|outlet`; modul yang tidak relevan tidak diregistrasi | Bab 4: satu basis kode dengan profil instalasi |
| Database | MySQL 8.4 LTS, InnoDB, `utf8mb4`, satu database per instalasi | Bab 4, Bab 24 |
| Sumber kebenaran | Pusat memiliki master; outlet memiliki transaksi lokal; SAP memiliki akuntansi, HPP dan nilai persediaan | Bab 1, Bab 17 |
| Sinkronisasi | Event bisnis via HTTPS API (outbox/inbox), at-least-once + idempoten; bukan replikasi MySQL | Bab 17 |
| Realtime LAN | Laravel Reverb (WebSocket) lokal + polling cadangan | Bab 4, Bab 9 |
| Angka | `DECIMAL(18,6)` untuk kuantitas/berat, `DECIMAL(18,2)` untuk uang (presisi final ditetapkan Finance); tidak ada float | Bab 7, Bab 24 |
| Identitas | Setiap entitas transaksi memakai UUID v7 (urut waktu, ramah indeks) + nomor dokumen manusia per outlet | Bab 24 |
| Penghapusan | Tidak ada hard delete pada transaksi; master memakai status nonaktif dan versi | Bab 5, Bab 24 |
| SAP | Hanya pusat yang memegang kredensial; Service Layer sebagai jalur utama, adapter XML MSI/MSR dipertahankan selama migrasi | Bab 18, Bab 22 |

Batas sistem: POS tidak membangun buku besar. Pengadaan, utang pemasok dan distribusi resmi tetap di SAP; POS hanya menyediakan permintaan, konfirmasi penerimaan, estimasi biaya resep dan laporan operasional.

## 2. Topologi dan komponen

Alur data berjalan satu arah per jenis: transaksi naik dari outlet ke pusat lalu ke SAP, sedangkan master, harga, promo, resep dan konfigurasi turun dari pusat ke outlet sebagai versi. Kapasitas 20 outlet, 18 teridentifikasi (Jakarta 6, Bekasi 3, Bogor 1, Cikarang 1, Tangerang 1, Depok 2, Lampung 4).

```
[Kasir] [Handheld] [Tablet pelayan] [KDS] [Kiosk/QR tamu*]
      \        |            |          |        /
       +---- LAN outlet (HTTPS lokal + WebSocket Reverb) ----+
                              |
          SERVER OUTLET (mini PC, UPS) - APP_PROFILE=outlet
          Nginx + PHP-FPM 8.4 | MySQL 8.4 | Reverb | Queue worker
          Scheduler | Print agent | Adapter timbangan/scanner
                              |  internet (boleh putus)
                    outbox -> POST /sync/events
                    GET /sync/changes <- cursor
                              |
          SERVER PUSAT - APP_PROFILE=central
          Portal admin | DB konsolidasi | Sync API | Monitoring
          Webhook pembayaran | Promotion Designer | SAP Bridge
                              |
          SAP B1 Service Layer  /  adapter XML MSI-MSR (legacy)

* Jaringan tamu disegmentasi, hanya ke endpoint QR ordering.
```

| Komponen | Lokasi | Tanggung jawab | Implementasi Laravel |
| --- | --- | --- | --- |
| Web POS (kasir, pelayan, KDS, handheld) | Outlet | UI sentuh, scan, keranjang, meja, opname | Inertia + Vue 3, aset dibundel lokal (Vite build), tanpa CDN |
| API lokal | Outlet | `/orders`, `/payments`, `/packs/resolve`, `/receipts`, `/counts`, `/production` | Controller + Form Request + Action class, Sanctum token perangkat |
| Realtime | Outlet | Push order ke KDS, status meja, status pembayaran | Laravel Reverb + Echo; fallback polling 3 detik |
| Queue worker | Outlet & pusat | Kirim outbox, cetak, terapkan versi, posting SAP | `queue:work` database/Redis di bawah Supervisor/systemd |
| Scheduler | Outlet & pusat | Heartbeat, tarik delta, aktivasi promo, backup, retensi | `schedule:work` sebagai layanan |
| Print agent | Outlet | Cetak struk dan tiket dapur ESC/POS | Proses kecil (PHP CLI `print:agent`) memakai antrean `print_jobs` |
| Adapter perangkat | Outlet | Timbangan, scanner wedge/kamera, laci kas | Driver per model; barcode timbang hanya bila format disahkan |
| Sync API | Pusat | Terima event, sajikan delta, ack versi | `/api/v1/sync/*`, inbox dengan unique `(source_id, event_id)` |
| SAP Bridge | Pusat | Mapping, posting, retry, rekonsiliasi | Modul `Integration\Sap` + job per dokumen dengan lock per sumber |
| Adapter XML | Pusat | Impor/ekspor NMEXML MSI dan MSR | Parser XMLReader aman (tanpa DTD/XXE) + staging |
| Webhook gateway | Pusat | QRIS/EDC/delivery, verifikasi signature, teruskan ke outlet | Endpoint terpisah dari UI, rate limit, anti-replay |

Profil awal server outlet untuk pilot: CPU 4 inti, RAM 16 GB, SSD 512 GB, LAN gigabit, UPS. Spesifikasi final mengikuti uji beban.

## 3. Stack teknologi dan paket

Semua versi dipin di `composer.lock` dan `package-lock.json`; paket instalasi outlet dibangun di CI sehingga outlet tidak mengunduh dependensi saat melayani transaksi.

| Lapisan | Pilihan | Catatan |
| --- | --- | --- |
| Runtime | PHP 8.4 (minimum Laravel 13: 8.3), ekstensi `bcmath`, `intl`, `pdo_mysql`, `xmlreader`, `sodium` | `bcmath` / `brick/math` untuk aritmetika desimal |
| Framework | Laravel 13 | Queue, Scheduler, Reverb, Sanctum, Fortify |
| Database | MySQL 8.4 LTS InnoDB, `READ COMMITTED`, binlog ROW aktif | Binlog untuk point-in-time recovery |
| Cache/antrean | Redis 7 (opsional) atau driver `database` | Outlet kecil cukup `database`; pusat disarankan Redis + Horizon |
| Frontend | Vue 3 + Inertia.js + Tailwind, PWA shell | Optimasi layar sentuh, mode gelap untuk KDS |
| Realtime | Laravel Reverb + Laravel Echo | Server WebSocket lokal per outlet |
| Autentikasi | Fortify (login + MFA TOTP untuk pusat), Sanctum token perangkat, PIN kasir | PIN hanya mempercepat pergantian petugas, otorisasi tetap di server |
| Otorisasi | Policy + Gate Laravel, tabel `roles/permissions` sendiri atau `spatie/laravel-permission` | Dibatasi scope outlet |
| Uang & berat | `brick/money`, `brick/math` | Tidak memakai float PHP |
| Audit | Tabel `audit_events` append-only + observer model | Nilai sebelum/sesudah, pelaku, alasan, correlation\_id |
| XML | `XMLReader` streaming, `LIBXML_NONET`, tanpa DTD | Batas ukuran dan jumlah elemen |
| SAP | HTTP client Laravel ke Service Layer (sesi B1SESSION) | Alternatif DI API via layanan terpisah bila objek tidak tersedia |
| Pengujian | Pest, Laravel Dusk/Playwright untuk UI, k6 untuk beban | Skenario U01 sampai U38 sebagai suite |
| Operasional | Nginx, Supervisor/systemd, `mysqldump`/XtraBackup + binlog, Prometheus exporter atau Laravel Pulse | Dashboard pusat menarik heartbeat |

## 4. Struktur modul dan folder kode

Kode dibagi per domain bisnis di `app/Domain`. Setiap domain berisi Model, Action (satu kasus penggunaan per kelas, dibungkus `DB::transaction`), Event, Policy dan Data Transfer Object. Controller hanya memvalidasi, memanggil Action dan mengembalikan resource.

```
app/
  Domain/
    Organization/   BusinessUnit, Region, Outlet, Location, Warehouse, Device, User
    Catalog/        Product, Uom, PriceList, PriceVersion, Menu, Modifier, SapMapping
    Pack/           Pack, PackEvent, Batch, PackingRun, Repack  (Pack ID & berat aktual)
    Sales/          Order, OrderLine, TaxLine, DiscountAllocation, Receipt, Return
    Restaurant/     Table, Visit, KitchenTicket, TicketItem, StationAck, Course
    CookingService/ CookingJob, CookingFeeRule, ServiceEntitlement
    Recipe/         RecipeVersion, RecipeLine, ProductionBatch, Yield, Waste, ConsumptionEvent
    Promotion/      Promotion, PromotionVersion, Target, Voucher, Engine/ (Evaluator, Allocator)
    Loyalty/        Customer, LoyaltyLedger, PointRule
    Inventory/      StockMovement, StockBalance, Reservation, Transfer, GoodsReceipt
    StockCount/     CountSession, CountRound, CountLine, Adjustment
    Payment/        Payment, Refund, Settlement, ProviderAdapters/ (Qris, Edc, Manual)
    Cash/           Shift, CashMovement, ShiftClosing
    Sync/           OutboxEvent, InboxEvent, PublicationVersion, Cursor, Heartbeat
    Integration/
      Sap/          SapDocument, Mappers/, Posters/, Reconciler
      Xml/          ImportBatch, SourceDocument, MsiParser, MsrParser, Exporter
    Audit/          AuditEvent, AnomalyRule, Alert
  Http/
    Controllers/Api/Local/   (profil outlet)
    Controllers/Api/Sync/    (profil central)
    Controllers/Portal/      (admin pusat, Inertia)
    Controllers/Webhooks/    (provider pembayaran & delivery)
  Providers/ProfileServiceProvider.php   (registrasi modul per APP_PROFILE)
resources/js/
  apps/pos (Rasentra POS)  apps/terminal (Mobile Terminal)  apps/mobile (Rasentra Mobile)  apps/kitchen (Rasentra Kitchen)  apps/kiosk  apps/central (Rasentra Central)
database/migrations/{shared,outlet,central}
tests/{Unit,Feature,Acceptance/U01..U38}
```

Aturan lintas modul: modul berkomunikasi lewat Action dan Domain Event, bukan query langsung ke tabel modul lain. Setiap Action mutasi menerima `idempotency_key`, `device_id` dan `actor_id`, lalu menulis dokumen, pergerakan stok, audit dan outbox dalam satu transaksi MySQL.

## 5. Skema database MySQL

Skema yang sama dipakai di outlet dan pusat; di pusat setiap tabel transaksi diisi dari semua outlet dan dibedakan oleh `outlet_id`. Primary key transaksi `BINARY(16)` UUID v7; master memakai `BIGINT` auto increment ditambah `code` unik. Waktu disimpan UTC (`DATETIME(6)`), tanggal bisnis outlet disimpan terpisah (`business_date DATE`).

| Kelompok | Tabel | Kunci integritas utama |
| --- | --- | --- |
| Organisasi & master | `business_units`, `regions`, `outlet_groups`, `outlets`, `locations`, `warehouses`, `devices`, `users`, `roles`, `products`, `uoms`, `price_versions`, `price_items`, `menus`, `modifiers`, `sap_mappings` | `outlets.code` unik; `sap_mappings (entity_type, entity_id, company_db)` unik |
| Kemasan | `packs`, `pack_events`, `batches`, `packing_runs`, `repack_links` | `packs.pack_code` unik global, tidak dipakai ulang |
| Penjualan | `orders`, `order_lines`, `order_line_modifiers`, `tax_lines`, `discount_allocations`, `receipts`, `returns`, `return_lines` | `orders (outlet_id, doc_no)` unik; `order_lines.pack_id` unik untuk baris aktif |
| Restoran | `tables`, `visits`, `kitchen_tickets`, `ticket_items`, `station_acknowledgements` | `visits.visit_token` unik; `ticket_items (id, revision)` |
| Cooking fee | `cooking_fee_rules`, `cooking_jobs`, `cooking_job_parts`, `service_entitlements` | `service_entitlements (receipt_id, pack_id)` unik |
| Resep & produksi | `recipes`, `recipe_versions`, `recipe_lines`, `production_batches`, `production_inputs`, `production_outputs`, `consumption_events`, `wastes` | Larangan siklus divalidasi saat approve versi |
| Promosi & pelanggan | `promotions`, `promotion_versions`, `promotion_targets`, `promotion_target_snapshots`, `vouchers`, `voucher_redemptions`, `customers`, `loyalty_ledger` | `voucher_redemptions.voucher_id` unik untuk voucher sekali pakai |
| Inventory | `stock_movements` (append-only), `stock_balances` (proyeksi), `reservations`, `transfers`, `transfer_lines`, `goods_receipts`, `goods_receipt_lines`, `replenishment_requests` | `stock_movements (source_type, source_id, line_no)` unik |
| Opname | `count_sessions`, `count_rounds`, `count_lines`, `count_adjustments` | `count_lines (round_id, pack_id)` unik |
| Uang | `shifts`, `cash_movements`, `shift_closings`, `payments`, `payment_events`, `refunds`, `settlements`, `settlement_lines` | `payments (provider, provider_ref)` unik |
| Integrasi | `outbox_events`, `inbox_events`, `publication_versions`, `outlet_version_acks`, `sap_documents`, `sap_posting_attempts`, `xml_import_batches`, `source_documents`, `source_document_lines`, `source_document_maps`, `integration_exceptions` | `inbox_events (source_id, event_id)` unik; `source_documents (system, branch_code, doc_type, source_no)` unik |
| Audit | `audit_events`, `approvals`, `anomaly_alerts` | Append-only, tanpa izin `DELETE` bagi user aplikasi |

### DDL kunci

```sql
CREATE TABLE packs (
  id              BINARY(16) PRIMARY KEY,
  pack_code       VARCHAR(32)  NOT NULL UNIQUE,          -- Pack ID pada label
  product_id      BIGINT UNSIGNED NOT NULL,
  batch_id        BIGINT UNSIGNED NULL,
  net_weight      DECIMAL(18,6) NOT NULL,                -- berat aktual, mis. 1.500000
  uom_code        VARCHAR(10)  NOT NULL DEFAULT 'KG',
  weigh_source    VARCHAR(40)  NOT NULL,                 -- id timbangan / packing_run
  packed_at       DATETIME(6)  NOT NULL,
  expires_at      DATE         NULL,
  label_price     DECIMAL(18,2) NULL,                    -- bila harga label mengikat
  location_type   ENUM('central','transit','outlet','return') NOT NULL,
  outlet_id       BIGINT UNSIGNED NULL,
  status          ENUM('available','allocated','shipped','received','reserved',
                       'sold','hold','damaged','repacked') NOT NULL,
  lock_version    INT UNSIGNED NOT NULL DEFAULT 0,       -- optimistic lock
  sold_order_line_id BINARY(16) NULL UNIQUE,             -- cegah jual dua kali
  created_at DATETIME(6), updated_at DATETIME(6),
  KEY idx_pack_outlet_status (outlet_id, status)
) ENGINE=InnoDB;

CREATE TABLE order_lines (
  id              BINARY(16) PRIMARY KEY,
  order_id        BINARY(16) NOT NULL,
  line_no         SMALLINT UNSIGNED NOT NULL,
  line_type       ENUM('retail_product','restaurant_menu','cooking_service','add_on') NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  pack_id         BINARY(16) NULL,
  cooking_job_id  BINARY(16) NULL,
  recipe_version_id BIGINT UNSIGNED NULL,
  quantity        DECIMAL(18,6) NOT NULL,
  uom_code        VARCHAR(10) NOT NULL,
  base_unit_price DECIMAL(18,2) NOT NULL,
  price_version_id BIGINT UNSIGNED NOT NULL,
  price_override_reason VARCHAR(200) NULL,
  gross_amount    DECIMAL(18,2) NOT NULL,
  discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  net_amount      DECIMAL(18,2) NOT NULL,
  tax_code        VARCHAR(20) NULL,
  status          ENUM('draft','confirmed','voided','returned_partial','returned') NOT NULL,
  revision        INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_order_line (order_id, line_no),
  KEY idx_line_pack (pack_id)        -- penjaga utama: packs.sold_order_line_id UNIQUE
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
  id              BINARY(16) PRIMARY KEY,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  location_id     BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  batch_id        BIGINT UNSIGNED NULL,
  pack_id         BINARY(16) NULL,
  movement_type   VARCHAR(30) NOT NULL,  -- sale, return, receipt, transfer_out, transfer_in,
                                         -- production_issue, production_receipt, consumption,
                                         -- waste, count_adjust, reservation...
  quantity        DECIMAL(18,6) NOT NULL, -- bertanda (+ masuk, - keluar)
  uom_code        VARCHAR(10) NOT NULL,
  stock_state     ENUM('available','reserved','quarantine','in_transit') NOT NULL,
  source_type     VARCHAR(40) NOT NULL,
  source_id       BINARY(16)  NOT NULL,
  source_line_no  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  occurred_at     DATETIME(6) NOT NULL,
  business_date   DATE NOT NULL,
  UNIQUE KEY uq_move_source (source_type, source_id, source_line_no, movement_type),
  KEY idx_move_balance (outlet_id, location_id, product_id, business_date)
) ENGINE=InnoDB;

CREATE TABLE outbox_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id        BINARY(16) NOT NULL UNIQUE,
  source_id       VARCHAR(40) NOT NULL,   -- id instalasi outlet
  outlet_id       BIGINT UNSIGNED NOT NULL,
  entity_type     VARCHAR(40) NOT NULL,
  entity_id       BINARY(16)  NOT NULL,
  entity_version  INT UNSIGNED NOT NULL,
  event_type      VARCHAR(60) NOT NULL,
  schema_version  SMALLINT UNSIGNED NOT NULL,
  occurred_at     DATETIME(6) NOT NULL,
  business_date   DATE NOT NULL,
  correlation_id  BINARY(16) NOT NULL,
  payload         JSON NOT NULL,
  checksum        CHAR(64) NOT NULL,
  status          ENUM('pending','sending','acked','failed','parked') NOT NULL DEFAULT 'pending',
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(6) NULL,
  last_error      TEXT NULL,
  ack_ref         VARCHAR(64) NULL,
  KEY idx_outbox_dispatch (status, next_attempt_at)
) ENGINE=InnoDB;

CREATE TABLE sap_documents (
  id              BINARY(16) PRIMARY KEY,
  company_db      VARCHAR(60) NOT NULL,
  source_type     VARCHAR(40) NOT NULL,   -- receipt, payment, return, production, count...
  source_id       BINARY(16)  NOT NULL,
  sap_object      VARCHAR(40) NOT NULL,   -- oInvoices, oIncomingPayments, ...
  status          ENUM('pending','validated','waiting_dependency','posting','posted',
                       'partially_posted','failed','needs_review') NOT NULL,
  doc_entry       INT NULL, doc_num INT NULL,
  posted_amount   DECIMAL(18,2) NULL,
  request_payload JSON NULL, response_payload JSON NULL,
  posting_date    DATE NULL,
  UNIQUE KEY uq_sap_source (company_db, source_type, source_id, sap_object)
) ENGINE=InnoDB;
```

Penjaga jual ganda: `uq_active_pack` tidak dipakai karena baris void akan bentrok; sistem memakai kolom `sold_order_line_id` pada `packs` (unik) sebagai penjaga utama, ditambah `SELECT ... FOR UPDATE` pada baris pack. Saldo stok dibaca dari `stock_balances` yang diperbarui dalam transaksi yang sama dengan `stock_movements`, dan dapat dibangun ulang dari ledger.

## 6. Status dan transisi

Status order, item dapur, pembayaran, kemasan, sinkronisasi dan posting SAP disimpan di kolom terpisah, sehingga misalnya order dapat "lunas" sementara posting SAP masih "menunggu". Transisi dijalankan oleh kelas `StateMachine` per entitas yang menolak lompatan tidak sah dan mencatat audit.

| Entitas | Status | Transisi penting dan efek stok |
| --- | --- | --- |
| Order | `draft` → `held` ↔ `draft` → `confirmed` → `paid` → `closed`; `voided` | Draft tidak memotong bahan. Confirmed ke dapur membuat reservasi. Paid/finalisasi membuat pergerakan stok penjualan sekali |
| Ticket item (KDS) | `queued` → `acknowledged` → `in_preparation` → `ready` → `served`; `cancel_requested` → `cancelled` | `in_preparation` membuat `consumption_events` sesuai versi resep. Batal setelah mulai masak menjadi waste, bukan pengembalian stok |
| Payment | `created` → `pending` → `succeeded` / `failed` / `expired` / `uncertain`; `refunded_partial` / `refunded` | `uncertain` wajib query status ke provider sebelum bayar ulang |
| Pack | `available` → `allocated` → `shipped` → `received` → `reserved` → `sold`; `hold`, `damaged`, `repacked` | Retur daging: `quarantine` lalu `return_to_stock` atau `waste` oleh petugas |
| Cooking job | `requested` → `pack_handed_over` → `cooking` → `served`; `cancelled_before_cook`, `cancelled_after_cook`, `remade` | Batal sebelum masak melepas reservasi pack; setelah masak tidak mengembalikan pack |
| Outbox event | `pending` → `sending` → `acked`; `failed` (retry backoff + jitter) → `parked` | Parked masuk antrean pengecualian |
| SAP document | `pending` → `validated` → `posting` → `posted`; `waiting_dependency`, `partially_posted`, `failed`, `needs_review` | Timeout menjadi `needs_review` sampai referensi dicek di SAP |
| Promotion version | `draft` → `simulated` → `approved` → `published` → `received` (per outlet) → `active` → `expired` / `superseded` | Versi published bersifat immutable |
| Count session | `planned` → `frozen` → `counting` → `recount` → `review` → `approved` → `posted_sap` | Petugas hitung tidak dapat menyetujui hasilnya sendiri |
| Shift | `open` → `closing` → `closed`; `reopened` (dengan approval) | Reopen tidak menghapus posting yang sudah terjadi |

## 7. Kontrak API dan sinkronisasi

Semua API berversi (`/api/v1`), memakai JSON, HTTPS dan token perangkat yang dibatasi satu outlet. Setiap mutasi wajib membawa header `Idempotency-Key`, `X-Device-Id` dan, bila relevan, `If-Match: <entity_version>`.

### API lokal (profil outlet)

| Metode & path | Fungsi | Catatan |
| --- | --- | --- |
| `POST /orders` / `PATCH /orders/{id}` | Buat dan ubah keranjang/pesanan | Revisi menghasilkan delta untuk KDS |
| `POST /orders/{id}/confirm` | Kirim ke dapur, buat reservasi | Hanya item yang belum di-ack |
| `POST /orders/{id}/finalize` | Finalisasi penjualan, potong stok, nomor struk | Satu transaksi DB + outbox |
| `GET /packs/resolve?code=` | Resolusi Pack ID atau barcode SKU | p95 ≤ 1 detik |
| `POST /payments` / `GET /payments/{id}` | Buat pembayaran, cek status | Status `uncertain` dibedakan |
| `POST /returns` | Retur mengacu struk asal | Keputusan kondisi barang terpisah dari refund |
| `POST /kds/items/{id}/ack` / `/status` | Ack dan ubah status station | Idempoten per `(item_id, revision, station)` |
| `POST /cooking-jobs` | Buat pekerjaan cooking fee dari Pack ID | Cek hak layanan |
| `POST /receipts` | Penerimaan barang (goods receipt) dari pengiriman | Parsial diizinkan |
| `POST /counts/{id}/lines` | Input hitung handheld | Saldo disembunyikan pada blind count |
| `POST /production` | Catat produksi batch, yield, waste | Neraca berat divalidasi |
| `POST /shifts` / `/shifts/{id}/close` | Buka/tutup shift, cash in/out | Selisih di atas ambang perlu approval |

Konsol server outlet (`/lokal`, kepala outlet): status koneksi ke Central dan antrean outbox, publikasi yang berlaku, tutup hari dengan pratinjau, pairing (token server outlet disimpan terenkripsi) dan snapshot, backup harian. `GET /lokal/health` untuk pemantauan, `GET /pos/api/sync-status` untuk indikator offline di POS.

### API sinkronisasi (profil central)

| Metode & path | Fungsi |
| --- | --- |
| `POST /sync/events` | Outlet mengirim batch event (maks. 500); respons per item: `accepted`, `duplicate` (dengan `ack_ref` sama), `deferred_dependency`, `rejected` |
| `GET /sync/changes?cursor=&types=` | Outlet menarik delta versi master/harga/promo/resep/konfigurasi; cursor monoton milik pusat |
| `POST /sync/ack` | Outlet mengonfirmasi `applied` atau `failed` per publikasi beserta cursor terakhir yang diterapkan |
| `GET /sync/snapshot` | Bootstrap Rasentra Lokal baru: outlet, perusahaan, produk, lokasi, meja, petugas (hash PIN), harga, promo, resep dan cooking fee aktif. Khusus token server outlet |
| `POST /sync/heartbeat` | Versi aplikasi, antrean outbox (pending/gagal/parked, umur tertua), jam perangkat, cursor yang diterapkan, shift yang masih buka |

Implementasi (Rasentra Central): publikasi berisi baris utuh dan induk yang dibutuhkan foreign key (`_parents`), sehingga Lokal menerapkannya idempoten per id dalam satu transaksi. Bila satu publikasi gagal, cursor Lokal berhenti tepat sebelumnya dan error-nya tampil di halaman Rasentra Central. Tutup hari (`business_day.closed`, otomatis pukul 04.00) membawa jumlah struk, nilai bersih per kanal dan metode bayar, daftar nomor struk dan selisih kas shift; Central mencocokkannya dengan `hq_orders` menjadi `matched`, `waiting` (struk belum sampai, diperiksa ulang tiap struk masuk) atau `mismatch`.

### Alur outbox ke inbox

1. Action lokal menulis dokumen, `stock_movements`, `audit_events` dan `outbox_events` dalam satu `DB::transaction`.
2. Job `DispatchOutbox` (terjadwal tiap 5 detik, `ShouldBeUnique`) mengambil event `pending` berurutan per entitas.
3. Pusat menyimpan event ke `inbox_events` dengan unique `(source_id, event_id)`, memproses ke tabel konsolidasi, lalu membalas `ack_ref`. Bila event sudah ada, pusat membalas `ack_ref` yang sama.
4. Gagal: retry dengan backoff eksponensial + jitter (5 detik sampai 15 menit) agar 20 outlet tidak serentak; setelah N kali menjadi `parked` dan muncul di dashboard pengecualian.
5. Event yang dependensinya belum tiba (misalnya pembayaran sebelum order) disimpan `deferred_dependency` dan diproses ulang otomatis.

Konflik: master dikuasai pusat, transaksi dikuasai outlet. Perubahan transaksi memakai `entity_version`; tidak ada last-write-wins untuk uang atau stok. Kapasitas outbox direncanakan minimal 7 hari volume puncak dengan alarm disk.

## 8. Logika kunci

### 8.1 Penjualan Pack ID (daging timbang)

Pack ID dijual tepat sekali: Action mengunci baris pack, memeriksa status dan outlet, lalu menulis penjualan dan `sold_order_line_id` dalam satu transaksi. Kasir kedua yang memindai pack sama menerima HTTP 409 dengan pesan jelas (U02).

```php
final class AddPackToOrder
{
    public function handle(Order $order, string $packCode, Actor $actor): OrderLine
    {
        return DB::transaction(function () use ($order, $packCode, $actor) {
            $pack = Pack::where('pack_code', $packCode)->lockForUpdate()->firstOrFail();

            match (true) {
                $pack->outlet_id !== $order->outlet_id => throw PackRejected::otherOutlet($pack),
                $pack->status === PackStatus::Hold,
                $pack->expires_at?->isPast()          => $this->requireApproval($pack, $actor),
                $pack->status !== PackStatus::Received => throw PackRejected::notAvailable($pack),
                default => null,
            };

            if ($order->lines()->where('pack_id', $pack->id)->active()->exists()) {
                throw PackRejected::alreadyInCart($pack);   // scan ulang tidak menggandakan
            }

            $price = $this->pricing->unitPrice($pack->product, $order); // versi harga aktif
            $gross = Decimal::of($pack->net_weight)->multipliedBy($price)->toScale(2, $this->rounding);

            $pack->update(['status' => PackStatus::Reserved]);
            return $order->lines()->create([... 'quantity' => $pack->net_weight, 'gross_amount' => $gross]);
        });
    }
}
```

Contoh U01: 1,500 kg + 1,750 kg × Rp200.000/kg = Rp300.000 + Rp350.000 = Rp650.000; stok berkurang 3,250 kg; dua Pack ID `sold`. Pembulatan diterapkan pada uang, bukan pada berat. Repack membuat pack baru dengan `repack_links` ke sumber dan neraca berat; berat historis tidak ditimpa.

### 8.2 Promotion Engine (berjalan lokal)

Engine murni (tanpa I/O) dengan input snapshot keranjang dan versi promo aktif; output deterministik berupa daftar manfaat dan alokasi per baris.

1. Saring promo berdasarkan target outlet (dari snapshot versi publikasi), kanal, hari/jam lokal, pelanggan/tier dan masa berlaku.
2. Hitung kelayakan memakai basis yang dikonfigurasi (sebelum/sesudah diskon lain, modifier termasuk atau tidak); syarat berat memakai total kg desimal per SKU atau gabungan SKU.
3. Terapkan promo item terlebih dahulu, lalu keranjang, mengikuti `priority`; konflik diselesaikan dengan mode `exclusive`, `stackable` atau `best_benefit`; pemecah seri = prioritas, lalu `promotion_version_id` terkecil.
4. Alokasikan diskon paket/keranjang ke baris secara proporsional nilai bruto dengan sisa pembulatan ke baris terbesar; simpan ke `discount_allocations` beserta `funded_by` (internal, pemasok, bank, platform).
5. Barang gratis tetap menjadi baris dengan harga nol dan tetap memotong stok.

Promo metode pembayaran diselesaikan sebelum otorisasi pembayaran. Kuota lokal dicek di outlet; voucher global dan penukaran poin diblokir saat pusat tidak terjangkau pada tahap awal, sementara pengumpulan poin menjadi `pending`.

### 8.3 Resep dan konsumsi

- Saat item berstatus `in_preparation`, sistem membentuk `consumption_events` = komposisi `recipe_version` × porsi, disesuaikan modifier (tambah, ganti, hilangkan). Versi resep disimpan di baris, sehingga perubahan resep tidak menghitung ulang historis.
- Subresep bertipe `stocked` dipotong sebagai item hasil (misalnya saus siap pakai); subresep `virtual` diuraikan ke bahan asal. Satu subresep tidak boleh keduanya. Validasi graf DFS menolak siklus saat versi disetujui.
- Produksi batch: `production_inputs` (keluar) dan `production_outputs` (masuk) memakai berat aktual; contoh 10 kg menjadi 8 kg steak, 1,5 kg trimming, 0,5 kg susut. Selisih neraca di atas toleransi wajib alasan.
- Complimentary, makan karyawan, tasting, waste dan remake memakai `consumption_reason` berbeda; harga nol tidak menghapus konsumsi.

### 8.4 Cooking fee (pilih daging retail lalu dimasak)

1. Kasir/pelayan memindai Pack ID, memilih layanan `masak di restoran` per baris. Pack berstatus `reserved` untuk order ini.
2. Sistem membuat baris `retail_product` (daging) dan baris `cooking_service` terpisah, plus `add_on` untuk tambahan di luar paket. Tarif diambil dari `cooking_fee_rules` (per kg berat mentah, per pack, per porsi atau bertingkat, minimum fee, metode, outlet, kanal, periode) dan versi aturannya disimpan.
3. `cooking_jobs` menyimpan Pack ID, berat dimasak, porsi, metode, kematangan, saus, alergi. KDS menampilkan data itu; petugas dapur mengonfirmasi serah terima pack.
4. Finalisasi memotong stok daging sekali. Resep jasa hanya memotong bahan tambahan (butter, bumbu, saus); daging dicatat sebagai referensi bahan milik pelanggan.
5. Bila daging sudah dibayar di kasir retail, restoran membuat `service_entitlement` dari struk + Pack ID asal dan hanya menagih jasa/tambahan. Entitlement yang sudah dipakai tidak bisa dipakai ulang tanpa otorisasi (U30).
6. Satu pack untuk beberapa porsi membuat child `cooking_job_parts`; sebagian dimasak sebagian dibawa pulang ditimbang pada basis berat mentah dan totalnya harus cocok dengan berat sumber.

Contoh: daging 1,500 kg × Rp200.000 = Rp300.000; cooking fee 1,500 kg × Rp40.000 = Rp60.000; kentang 2 × Rp25.000 = Rp50.000; subtotal Rp410.000 sebelum pajak dan service charge. Diskon daging tidak otomatis mendiskon jasa memasak.

### 8.5 Shift, kas dan retur

- Buka shift mencatat saldo awal; cash in/out dan buka laci tanpa penjualan wajib alasan.
- Tutup shift mencatat hitungan aktual per metode, selisih dan approval sesuai ambang; dapat dilakukan tanpa pusat.
- Refund uang dan penerimaan kembali barang adalah dua kejadian; daging kembali masuk `quarantine` sampai petugas memilih return to stock atau waste.
- Kegagalan printer tidak membatalkan transaksi; reprint memakai nomor yang sama dan tercatat di audit.

### 8.6 Rasentra Kitchen dan layar antrean

Rasentra Kitchen mengambil pola order management seperti [ESB OMS](https://www.esb.id/id/solusi/produk/oms) (kiosk, kitchen display, layar antrean yang saling terhubung) sebagai pembanding fungsi, dengan semua proses tetap berjalan di server lokal outlet.

| Fitur | Perilaku di Rasentra |
| --- | --- |
| Tiket digital per station | Menggantikan kertas; urutan tiket mengikuti waktu konfirmasi dan course; printer hanya cadangan dengan nomor tiket sama |
| Status otomatis lintas perangkat | Terima, Mulai, Siap, Disajikan di Kitchen langsung terlihat di Rasentra POS dan Mobile Terminal pelayan lewat Reverb, cadangan polling |
| Selesaikan dengan sentuh atau scan | Tombol besar per tiket, atau pindai barcode pada tiket/stiker packing untuk menandai Siap/Diambil |
| Timer dan analisis waktu saji | Waktu tunggu per item berwarna (hijau, kuning, merah sesuai target menu); laporan rata-rata dan p90 waktu terima, masak dan saji per station, menu dan jam |
| Expeditor | Melihat kelengkapan antarstation per order sebelum disajikan |
| Bahasa | Antarmuka Kitchen dapat dipilih Indonesia atau Inggris per perangkat untuk chef asing |
| Layar antrean (pickup) | Menampilkan nomor order Sedang Disiapkan dan Siap Diambil secara realtime, ditambah materi promo outlet aktif |
| Kiosk dan QR | Pesan dan ubah pesanan sendiri; rekomendasi tambahan berbasis aturan (paket, add-on), bayar QRIS/EDC atau di kasir; order masuk dapur setelah lunas atau disetujui pelayan |

Rekomendasi "AI upselling" tidak menjadi ketergantungan operasional lokal; tahap awal memakai aturan pasangan menu yang diatur pusat.

## 9. Integrasi SAP B1 dan adapter XML MSI/MSR

SAP Bridge di pusat mengubah data kanonik POS menjadi dokumen SAP per sumber, dengan satu baris `sap_documents` per objek SAP, sehingga invoice dan pembayaran dapat berhasil atau gagal sendiri-sendiri. Adapter XML dan adapter Service Layer tidak boleh memposting kejadian bisnis yang sama bersamaan: setiap outlet/jenis dokumen memiliki flag `posting_channel = xml|service_layer`.

| Sumber POS | Objek SAP (disahkan Finance) | Poster |
| --- | --- | --- |
| Penjualan (struk/rekap) | A/R Invoice (`Invoices`) | `InvoicePoster` |
| Pembayaran | Incoming Payment (`IncomingPayments`) mengacu invoice | `IncomingPaymentPoster` |
| Retur barang | Return / A/R Credit Memo | `CreditMemoPoster` |
| Refund | Outgoing payment / koreksi sesuai desain | `RefundPoster` |
| Produksi batch | Production Order, Issue/Receipt from Production | `ProductionPoster` |
| Konsumsi langsung | Sales BOM atau Goods Issue terpisah (pilih satu jalur per tipe menu) | `ConsumptionPoster` |
| Distribusi | Inventory Transfer (Request) via gudang transit | `TransferPoster` |
| Opname | Inventory Counting + Inventory Posting | `CountPoster` |

### Pola posting yang aman

1. Validasi mapping (ItemCode, satuan, gudang, branch, BP, tax code, dimensi, akun perantara). Mapping kosong menjadi `integration_exceptions`, bukan posting.
2. Ambil lock per sumber (`Cache::lock("sap:{source_id}")` + baris `sap_documents FOR UPDATE`).
3. Sebelum POST, cari dokumen di SAP lewat referensi sumber (misalnya `NumAtCard` / UDF `U_POS_SRC`) agar crash setelah commit SAP tidak menghasilkan dokumen kedua.
4. POST ke Service Layer; simpan `DocEntry`, `DocNum`, objek, company DB dan nilai yang diposting.
5. Timeout = `needs_review`; job pemulihan memeriksa SAP sebelum retry. Invoice berhasil tetapi pembayaran gagal = `partially_posted`; retry hanya pembayaran.
6. Transaksi yang tiba setelah periode SAP dikunci masuk antrean Finance; tanggal bisnis asli tidak diubah.

### Adapter XML NMEXML (MSI dan MSR)

| Tahap | Implementasi |
| --- | --- |
| Terima file | Simpan file asli immutable + SHA-256 di `xml_import_batches`; nama/hash file tidak dipakai sebagai kunci dokumen |
| Parse | `XMLReader` streaming, `LIBXML_NONET`, tanpa DTD/entity, batas ukuran dan jumlah elemen; bedakan field hilang, kosong dan nol; kode tetap string (nol depan dipertahankan) |
| Staging | `source_documents` + `source_document_lines`, unique `(system, branch_code, doc_type, source_no)` |
| Mapping | `BranchCode`, `CUSTOMERID`, `WAREHOUSEID`, `ITEMNO`, `ITEMUNIT`, `BANKACCOUNT`, `ARACCOUNT`, `DEPTID` dipetakan lewat `source_document_maps`; satuan kosong wajib punya mapping sebelum posting |
| Kalkulasi | Baris = `QUANTITY × UNITPRICE × (1 − ITEMDISCPC/100)`; dibandingkan dengan `INVOICEAMOUNT`; selisih (contoh Rp0,40 pada M26000010702) dicatat sebagai kasus pembulatan sesuai aturan Finance |
| Urutan | Posting invoice lalu pembayaran; MSR yang datang lebih dulu disimpan `waiting_dependency` |
| Hasil | Status per dokumen + ringkasan batch; satu dokumen gagal tidak membuat seluruh file sukses atau diulang |

Metadata internal (line\_type, Pack ID, recipe\_version, cooking\_job, alokasi diskon, kanal) tidak dimasukkan ke `ITEMRESERVED1-10` secara sepihak; disimpan di model internal dan dikirim hanya lewat sidecar/ekstensi yang disepakati. Uji acuan: kedua sampel 28 Oktober 2026 harus menghasilkan 49 invoice, 182 baris (60 pecahan), 49 pembayaran, total masing-masing Rp18.811.355.

## 10. Hak akses per peran

Otorisasi diperiksa di server lewat Policy Laravel dan selalu dibatasi scope outlet pengguna. Tindakan sensitif memisahkan pembuat dan penyetuju; setiap override menyimpan penyetuju, alasan dan nominal.

| Kemampuan | Admin pusat | Merchandising | Purchasing/gudang | Kepala outlet | Kasir/pelayan | Chef/dapur | Petugas opname | Finance/auditor |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Kelola outlet, perangkat, pengguna | Ya | - | - | Lihat outletnya | - | - | - | Lihat |
| Harga dan promo (susun) | - | Ya | - | - | - | - | - | Lihat |
| Promo (setujui/publikasi) | Ya | Tidak boleh menyetujui buatan sendiri | - | - | - | - | - | Lihat |
| Penjualan, pembayaran, struk | - | - | - | Ya | Ya | - | - | Lihat |
| Void, refund, override harga, buka laci | - | - | - | Setujui sesuai limit | Ajukan | - | - | Audit |
| Meja, order resto, KDS | - | - | - | Ya | Ya | Ya (KDS) | - | - |
| Resep, produksi, yield, waste | - | - | - | Lihat | - | Ya (resep disahkan chef kepala) | - | Lihat |
| Permintaan & penerimaan barang | - | - | Proses | Ajukan/terima | - | Ajukan | - | Lihat |
| Stok opname | Buat sesi | - | - | Buat sesi, setujui | - | - | Hitung (blind) | Setujui koreksi |
| Rekonsiliasi SAP, koreksi posting | - | - | - | - | - | - | - | Ya |

PIN kasir hanya untuk pergantian petugas di perangkat yang sudah terautentikasi. Akun yang dinonaktifkan saat outlet offline baru efektif setelah delta diterima; mitigasinya kepala outlet dapat mengunci akun secara lokal.

## 11. Keamanan, nonfungsional, monitoring dan backup

Target awal di bawah harus dibuktikan pada pilot dengan profil beban tertulis; angka ini bukan jaminan.

| Aspek | Target / persyaratan | Cara memenuhi |
| --- | --- | --- |
| Respons kasir | Resolusi barcode/Pack ID lokal p95 ≤ 1 detik | Indeks `packs.pack_code`, cache produk/harga aktif di memori, query tanpa join berat |
| Kirim dapur | Item tampil di KDS p95 ≤ 2 detik | Event Reverb setelah commit (`afterCommit`), polling cadangan |
| Internet putus | Fungsi inti berjalan minimal satu shift | Semua aset, autentikasi, promo dan harga tersedia lokal |
| Integritas | Nol kehilangan/duplikasi pada retry | Outbox dalam transaksi, unique constraint, idempotency key |
| Antrean | ≥ 7 hari volume puncak | Alarm disk 70%/85%, drain bertahap setelah koneksi pulih |
| Sinkronisasi | Transaksi sampai pusat ≤ 60 detik di jaringan sehat | Dispatch tiap 5 detik, batch 500 |
| Pemulihan | RPO ≤ 15 menit, RTO ≤ 2 jam (usulan) | Backup terjadwal ke media terpisah + binlog, mini PC cadangan |

**Keamanan:** HTTPS lokal dengan sertifikat CA internal; MFA untuk akses pusat dan remote; token perangkat dapat dicabut dan terikat outlet; secret di `.env` terlindungi, tidak masuk log; jaringan kasir, tamu dan administrasi dipisah; port MySQL hanya ke localhost; handheld tidak pernah langsung ke database; webhook diverifikasi HMAC + timestamp + nonce; tidak menyimpan data kartu.

**Pengendalian anomali:** aturan awal untuk refund, void, override harga, complimentary, reprint, buka laci, selisih kas dan stok negatif; setiap alarm menautkan transaksi pendukung.

**Monitoring:** web/PHP, MySQL, queue worker, scheduler, Reverb, print agent, disk, umur backup, heartbeat, umur event outbox tertua. Pusat membedakan "outlet tidak terhubung" dari "aplikasi outlet mati" berdasarkan heartbeat terakhir.

**Backup & pemulihan:** dump terenkripsi tiap 15 menit (incremental binlog) ke disk/NAS terpisah, offsite saat internet tersedia. Restore ke server cadangan: pulihkan DB, konfigurasi, identitas outlet; nonaktifkan instance lama; cocokkan high-water mark outbox dan dokumen SAP sebelum mengirim ulang.

**Pembaruan aplikasi:** paket rilis bertanda, staging, pilot satu outlet, migrasi kompatibel mundur dalam jendela versi, tanpa unduh dependensi di outlet. Runbook: internet putus, server mati, printer gagal, pembayaran tidak pasti, Pack ID tidak dikenal, SAP menolak dokumen, disk hampir penuh.

## 12. Strategi pengujian

Setiap skenario UAT menjadi satu test otomatis di `tests/Acceptance` (Pest) yang memeriksa struk, ledger stok, outbox dan dokumen SAP tiruan; uji gangguan memakai pemutusan jaringan dan penghentian proses sungguhan pada lingkungan uji.

| Kelompok | Skenario | Fokus bukti otomatis |
| --- | --- | --- |
| Daging timbang & kasir | U01 sampai U04 | Total Rp650.000 dan 3,250 kg; konflik 409 pada jual bersamaan (dua proses paralel); scan ulang; blokir pack salah outlet/hold |
| Promo | U05, U06, U17, U31 | Promo Lampung hanya di 4 outlet; syarat berat desimal; refund sesuai alokasi; promo dihentikan saat offline |
| Restoran & resep | U07 sampai U12 | Split bill dan delta KDS; konsumsi 10 × resep; produksi saus; batal sebelum/sesudah masak; neraca yield |
| Cooking fee | U25 sampai U30 | Daging keluar sekali; tagihan resto hanya jasa; child job; batal setelah masak; entitlement tidak dipakai ulang |
| Ketahanan & sinkronisasi | U13 sampai U15, U18, U23, U24 | Satu shift tanpa internet; event berulang tanpa duplikasi; timeout setelah commit; webhook ganda/terbalik; restore server cadangan; beban 20 outlet (k6) |
| SAP & periode | U16, U19 | Posting sebagian, retry pembayaran saja; periode terkunci masuk pengecualian |
| Inventory | U20 sampai U22 | Penerimaan parsial dan transit; Pack ID tidak terhitung ganda; antrean diperhitungkan sebelum koreksi |
| XML MSI/MSR | U32 sampai U38 | 49/182/49 dan Rp18.811.355; 20 baris diskon; selisih Rp0,40; file ulang beda nama; MSR mendahului MSI; nol depan; XML berbahaya (XXE) ditolak |

Kriteria go-live: tidak ada cacat kritis terbuka pada uang, stok, identitas kemasan, salah outlet atau otorisasi; semua selisih POS-SAP siklus pilot terjelaskan.

## 13. Tahapan implementasi dan keputusan terbuka

Pembangunan mengikuti enam tahap; tiap tahap ditutup oleh gerbang penerimaan dari spesifikasi Bab 29.

1. **Fondasi** – skeleton Laravel 13, profil central/outlet, master organisasi & produk, autentikasi perangkat, outbox/inbox, audit, monitoring.
2. **Pilot retail** – Pack ID, penerimaan barang, kasir, harga, promo engine, pembayaran, shift, posting SAP invoice & pembayaran, adapter XML.
3. **Pilot restoran** – meja, visit token, order, KDS + expeditor, resep, produksi, yield, waste, cooking fee.
4. **Kontrol inventory** – handheld opname, transfer/transit, recount, koreksi SAP.
5. **Rollout** – gelombang outlet (mulai satu outlet Jakarta, lalu kelompok Lampung), pelatihan, opening stock.
6. **Pengayaan** – delivery agregator, loyalty lintas outlet, analitik, AI prioritas audit.

Keputusan yang masih harus disahkan sebelum produksi:

- [ ] Finance: company database, posting per struk atau rekap, pajak & service charge, titik dan metode pembulatan, akun perantara & settlement, satu jalur konsumsi per tipe menu.
- [ ] Operasional: harga label mengikat atau harga kasir, kebijakan Pack ID tidak dikenal, ambang override, selisih kas dan recount.
- [ ] Chef: resep, satuan, yield, modifier, waktu pencatatan konsumsi.
- [ ] Pemasaran: kombinasi promo, kuota, aturan loyalty saat offline.
- [ ] TI: model timbangan/scanner/printer, pemilik sistem packing pusat, sertifikat lokal, versi MySQL, target RPO/RTO.
- [ ] Integrasi: kode gudang SAP tiap outlet, mapping kode legacy, alur NMEXML dan acknowledgement SAP; dua outlet yang belum teridentifikasi.
