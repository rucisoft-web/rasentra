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

## Tahap berikutnya

2. **WhatsApp**: inbox bersama tim CS, template pesan, broadcast ke segmen (laju kirim dibatasi, antrean `database` + cron), opt-out otomatis kata "STOP", webhook status terkirim/dibaca.
3. **Workflow & komunitas**: pemicu (pesan masuk berkata kunci, member baru, ulang tahun, tidak belanja N hari) → syarat → aksi (kirim template, beri tag, serahkan ke CS, tunggu); komunitas per outlet/minat.
4. **AI (Claude API)**: knowledge base (FAQ, katalog, promo aktif dari Promotion Designer) dengan pencarian teks MySQL, asisten chat yang menyarankan/mengirim balasan dengan alih ke CS, dan skor lead dari percakapan.
