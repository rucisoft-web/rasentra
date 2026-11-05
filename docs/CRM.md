# Rasentra CRM

Modul Rasentra Central untuk database pelanggan lintas outlet, WhatsApp dan AI, terintegrasi dengan Accurate Online. Konsol di `/core/crm`, peran `hq_crm` (CRM & customer service pusat) atau admin pusat.

## Tahap 1 (sudah ada)

| Fitur | Implementasi |
| --- | --- |
| Impor member & HP per outlet | `MemberImporter` membaca `.xlsx`/`.csv` (tanpa pustaka tambahan, aman XXE), mengenali judul kolom umum, menormalkan nomor (`Phone::normalize`) dan menggabung duplikat lintas outlet. Ringkasan per impor di `crm_imports`. |
| Customer database | `crm_customers` (kunci: `phone` 62…), keanggotaan per outlet `crm_customer_outlets`, tag `crm_tags`, riwayat belanja `crm_purchases` (sumber `pos` dari event `order.finalized` lewat `SalesProjector`, sumber `accurate` dari faktur Accurate), kode eksternal `crm_external_ids`. Semua lewat `CustomerDirectory`. |
| Customer segmentation | `crm_segments.rules` (JSON) dievaluasi `SegmentQuery`: outlet, tag, tahap siklus hidup, aktif ≤ N hari, tidak belanja > N hari, minimal transaksi/nilai, ulang tahun, kota, status WhatsApp. |
| Accurate Online | `HttpAccurateClient` (API Token + HMAC signature, host dari `api-token.do`), `AccurateSync` menarik pelanggan dan faktur penjualan per perusahaan, jadwal tiap jam (`rasentra:crm-accurate-sync`). Token dan secret dienkripsi (`encrypted` cast). |

Catatan verifikasi: format endpoint Accurate (`customer/list.json`, `sales-invoice/list.json`, header `X-Api-Timestamp`/`X-Api-Signature`) mengikuti dokumentasi API Token Accurate dan perlu diuji sekali dengan token asli dari database Accurate Indoguna sebelum dipakai produksi.

## Perusahaan & cabang

`crm_branches` memisahkan cabang CRM dari tabel `outlets`: outlet Rasentra dicerminkan otomatis (`Branch::forOutlet`), sedangkan CV grup yang masih memakai Accurate Online + Rene POS/Raptor (Makassar, Balikpapan, Sarana Indoguna Lestari, Jogja, Semarang, Bandung) ditambahkan di menu Perusahaan & cabang tanpa ikut ke POS, sinkronisasi, harga, promo atau posting SAP.

## Tahap 2: WhatsApp Business Cloud API (sudah ada)

| Fitur | Implementasi |
| --- | --- |
| Nomor & template | `crm_wa_channels` (Phone number ID, WABA ID, token System User terenkripsi, batas kirim/menit), template disinkron dari Graph API (`/{waba}/message_templates`). |
| Webhook | `GET/POST /api/crm/whatsapp/webhook`; verifikasi `WHATSAPP_VERIFY_TOKEN`, tanda tangan `X-Hub-Signature-256` dengan `WHATSAPP_APP_SECRET`. Pesan masuk membuat/menemukan pelanggan, idempoten per `wamid`; status kiriman tidak pernah mundur. |
| Instant messaging | Inbox bersama: filter terbuka/milik saya/belum dibaca, tugaskan CS, tutup. Balasan teks hanya dalam jendela 24 jam; di luar itu hanya template. |
| Community management | `crm_communities` + anggota dari segmen/cabang/nomor, atau bergabung sendiri dengan kata kunci WhatsApp (sekaligus opt-in). STOP/BERHENTI = opt-out, START/YA = opt-in. |
| Kirim iklan / mass messaging | `crm_campaigns`: template MARKETING dengan header gambar/video/PDF (unggah atau URL), parameter `{name}`, `{first_name}`, `{outlet}`, penerima komunitas dan/atau segmen, hanya yang opt-in, dijadwalkan atau segera, dicicil tiap menit (`rasentra:crm-campaigns`), opt-out dicek ulang sebelum kirim, laporan terkirim/sampai/dibaca/gagal. |

Media yang diunggah disimpan di disk `public` (`php artisan storage:link` di server Central) karena Meta mengambil media dari URL publik.

## Tahap berikutnya

3. **Workflow & komunitas**: pemicu (pesan masuk berkata kunci, member baru, ulang tahun, tidak belanja N hari) → syarat → aksi (kirim template, beri tag, serahkan ke CS, tunggu); komunitas per outlet/minat.
4. **AI (Claude API)**: knowledge base (FAQ, katalog, promo aktif dari Promotion Designer) dengan pencarian teks MySQL, asisten chat yang menyarankan/mengirim balasan dengan alih ke CS, dan skor lead dari percakapan.
