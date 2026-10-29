<?php

return [

    /*
    | Profil instalasi:
    | - "lokal"   Rasentra Lokal, server di setiap outlet yang melayani Rasentra POS,
    |             Mobile Terminal, Kitchen dan Inventory; tetap jalan saat internet putus.
    | - "central" Rasentra Central, pusat POS di kantor pusat: menerima sinkronisasi dari
    |             semua outlet, menyebarkan harga/promo/resep dan memposting ke SAP B1.
    |             Rasentra Core (aplikasi admin kantor pusat) berjalan di instalasi ini.
    */
    'profile' => env('RASENTRA_PROFILE', 'lokal'),

    // Versi aplikasi yang dilaporkan Rasentra Lokal lewat heartbeat.
    'version' => env('RASENTRA_VERSION', '1.0.0'),

    // Nama grup usaha yang membawahi semua CV (business unit).
    'group_name' => env('RASENTRA_GROUP_NAME', 'Indoguna Group'),

    // Identitas instalasi; dipakai sebagai source_id pada event sinkronisasi.
    'source_id' => env('RASENTRA_SOURCE_ID', 'dev-local'),

    // Kode outlet yang dilayani Rasentra Lokal ini (profil lokal).
    'outlet_code' => env('RASENTRA_OUTLET_CODE'),

    // Zona waktu tampilan Rasentra Core (data disimpan dalam UTC).
    'display_timezone' => env('RASENTRA_DISPLAY_TZ', 'Asia/Jakarta'),

    // Alamat Rasentra Central yang dituju sinkronisasi dari Rasentra Lokal.
    'central' => [
        'url' => env('RASENTRA_CENTRAL_URL'),
        'token' => env('RASENTRA_CENTRAL_TOKEN'),
        'batch_size' => (int) env('RASENTRA_SYNC_BATCH', 500),
    ],

    'money' => [
        // Skala rupiah yang disimpan dan metode pembulatan. Nilai final ditetapkan Finance.
        'scale' => 2,
        'rounding' => env('RASENTRA_ROUNDING', 'HALF_UP'),
    ],

    'quantity_scale' => 6,

    // Rasentra POS: batas hari retur sejak tanggal struk, dan teks kaki struk.
    'pos' => [
        'return_days' => (int) env('RASENTRA_RETURN_DAYS', 7),
        'receipt_footer' => env('RASENTRA_RECEIPT_FOOTER', 'Terima kasih atas kunjungan Anda'),
    ],

    // Rasentra Inventory: pack yang kedaluwarsa dalam N hari muncul sebagai peringatan.
    'inventory' => [
        'expiry_warning_days' => (int) env('RASENTRA_EXPIRY_WARNING_DAYS', 2),
    ],

    'sync' => [
        // Backoff eksponensial (detik) dengan jitter agar 20 outlet tidak retry serentak.
        'backoff_base' => 5,
        'backoff_max' => 900,
        'max_attempts' => 20,
    ],

    'xml' => [
        'max_bytes' => 20 * 1024 * 1024,
        'max_elements' => 500_000,
    ],

    // Rasentra Mobile Terminal: pilihan modifier per kategori menu. Kunci opsi dipakai resep
    // (mis. extra_butter menambah butter) dan tampil di tiket dapur sebagai label.
    'menu_modifiers' => [
        'Steak' => [
            ['group' => 'doneness', 'label' => 'Kematangan', 'required' => true, 'options' => [
                ['rare', 'Rare'], ['medium_rare', 'Medium rare'], ['medium', 'Medium'], ['medium_well', 'Medium well'], ['well_done', 'Well done'],
            ]],
            ['group' => 'extra', 'label' => 'Tambahan', 'multiple' => true, 'options' => [['extra_butter', 'Extra butter']]],
        ],
        'Bakaran' => [
            ['group' => 'spicy', 'label' => 'Level pedas', 'options' => [['not_spicy', 'Tidak pedas'], ['mild', 'Pedas sedang'], ['hot', 'Pedas']]],
        ],
        'Seafood' => [
            ['group' => 'spicy', 'label' => 'Level pedas', 'options' => [['not_spicy', 'Tidak pedas'], ['mild', 'Pedas sedang'], ['hot', 'Pedas']]],
        ],
        'Nasi & Mie' => [
            ['group' => 'spicy', 'label' => 'Level pedas', 'options' => [['not_spicy', 'Tidak pedas'], ['mild', 'Pedas sedang'], ['hot', 'Pedas']]],
        ],
        'Minuman' => [
            ['group' => 'sugar', 'label' => 'Gula', 'options' => [['normal_sugar', 'Normal'], ['less_sugar', 'Kurang manis'], ['no_sugar', 'Tanpa gula']]],
            ['group' => 'ice', 'label' => 'Es', 'options' => [['normal_ice', 'Es normal'], ['less_ice', 'Sedikit es'], ['no_ice', 'Tanpa es']]],
        ],
    ],

    // Catatan cepat untuk pelayan.
    'quick_notes' => ['Tanpa bawang', 'Tanpa sambal', 'Saus dipisah', 'Porsi anak', 'Alergi kacang', 'Bungkus'],

    // Rasentra Kitchen: station, target waktu saji (menit) dan ambang warna timer.
    'kitchen' => [
        'stations' => [
            'grill' => ['name' => 'Grill', 'target_minutes' => 15],
            'hot' => ['name' => 'Hot kitchen', 'target_minutes' => 10],
            'cold' => ['name' => 'Cold kitchen', 'target_minutes' => 8],
            'bar' => ['name' => 'Bar', 'target_minutes' => 5],
            'pastry' => ['name' => 'Pastry', 'target_minutes' => 10],
            'packing' => ['name' => 'Packing', 'target_minutes' => 5],
        ],
        // Timer kuning saat waktu berjalan >= 75% target, merah saat melewati target.
        'warn_ratio' => 0.75,
        // Tiket siap tetap tampil di layar antrean pickup selama N menit setelah disajikan.
        'queue_linger_minutes' => 3,
        'pickup_channels' => ['takeaway', 'pickup', 'delivery'],
        // Cetak tiket dapur (dengan barcode kode bump) di printer station setiap kirim ke dapur.
        'print_tickets' => (bool) env('RASENTRA_KITCHEN_PRINT', true),
    ],
];
