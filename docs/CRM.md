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

## Tahap 3: workflow otomatis (sudah ada)

Menu **Rasentra CRM → WhatsApp → Workflow otomatis** (`crm_workflows`, `crm_workflow_runs`). Satu workflow = satu pemicu + maksimal 8 langkah berurutan. Workflow baru tersimpan nonaktif; mematikan workflow membatalkan run yang sedang menunggu.

| Pemicu | Kapan |
|---|---|
| Kata kunci | Pesan WhatsApp masuk berisi salah satu kata (dipisah koma, cocok per kata). STOP/START dan kata kunci komunitas diproses lebih dulu. |
| Pelanggan baru | Tercatat dari chat WhatsApp, transaksi POS, atau input manual; bisa dibatasi per cabang. Impor file dan tarikan Accurate **tidak** memicu agar tidak ada kiriman massal tak sengaja. |
| Bergabung ke komunitas | Lewat kata kunci WhatsApp; bisa dibatasi satu komunitas. |
| Setelah belanja | Transaksi POS Rasentra atau faktur Accurate (CV Rene POS/Raptor) bertanggal kemarin/hari ini, dengan minimal nilai opsional. Riwayat lama yang ditarik ulang tidak memicu. |
| Ulang tahun / tidak belanja N hari | Dipindai sekali sehari mulai pukul 09.00 WIB (`rasentra:crm-workflows`, tiap menit). Pelanggan opt-out dilewati. |

Langkah: kirim template (MARKETING hanya ke opt-in, parameter `{name}`/`{first_name}`), kirim teks (hanya dalam jendela 24 jam), tunggu N jam, beri tag, masukkan ke komunitas, serahkan percakapan ke CS. Semua pemicu bisa dibatasi ke satu segmen, dan jeda ulang per pelanggan mencegah pesan berulang. Halaman detail menampilkan riwayat run dan catatan tiap langkah, serta tombol uji coba ke satu nomor.

## Tahap 4: AI dengan Claude API (sudah ada)

Memakai SDK resmi `anthropic-ai/sdk`, model `claude-opus-5-5` (ubah lewat `CRM_AI_MODEL`, tingkat `CRM_AI_EFFORT`). Fitur aktif bila `ANTHROPIC_API_KEY` diisi dan mode asisten AI dipilih per nomor WhatsApp (Mati / Saran / Otomatis). Permintaan memakai structured outputs (JSON Schema), prompt sistem yang di-cache, dan fallback server-side Anthropic (`fallbacks: "default"`) bila model menolak permintaan.

| Fitur | Cara kerja |
|---|---|
| Knowledge base AI | Menu **AI → Knowledge base AI** (`crm_kb_articles`): artikel per topik, bisa khusus cabang. Untuk setiap chat, artikel paling relevan dipilih (pencocokan kata, judul dan kata kunci berbobot) lalu disertakan ke prompt. Ada uji pencarian. AI dilarang menjawab di luar artikel. |
| Asisten chat | Setelah pesan masuk (setelah respons webhook), AI menulis saran balasan. Mode Saran: balasan muncul terisi di Inbox untuk diperiksa CS. Mode Otomatis: dikirim sendiri hanya bila keyakinan ≥ 0,75, tidak perlu CS, jendela 24 jam terbuka, belum ada CS yang menangani dalam 12 jam, dan maksimal 8 balasan AI per 24 jam. Komplain, refund, pesanan besar, atau info yang tidak ada diserahkan ke CS (tab Inbox "Perlu CS"). |
| Identifikasi lead | Panggilan yang sama memberi niat (beli, katering, reseller, reservasi, tanya info, komplain), skor 0-100, tahap dingin/hangat/panas, minat, perkiraan nilai, dan langkah berikut. Menu **AI → Lead dari WhatsApp**; lead panas diberi tag "Lead panas" sehingga bisa jadi segmen atau pemicu workflow. Percakapan yang belum dianalisis dinilai tiap jam (`rasentra:crm-ai-leads`). |

Pesan pelanggan diperlakukan sebagai data, bukan instruksi, dan AI tidak boleh mengarang harga, promo, atau diskon.
