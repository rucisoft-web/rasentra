<?php

namespace Database\Seeders;

use App\Domain\Crm\CustomerDirectory;
use App\Models\BusinessUnit;
use App\Models\Crm\Branch;
use App\Models\Crm\Community;
use App\Models\Crm\Customer;
use App\Models\Crm\KbArticle;
use App\Models\Crm\Segment;
use App\Models\Crm\Tag;
use App\Models\Crm\Workflow;
use App\Models\Outlet;
use Illuminate\Database\Seeder;

/** Data contoh Rasentra CRM: member Solo dan Lombok, riwayat belanja, tag dan segmen siap pakai. */
class CrmSeeder extends Seeder
{
    public function run(CustomerDirectory $directory): void
    {
        Branch::syncFromOutlets();
        $outlets = Branch::whereIn('outlet_id', Outlet::whereIn('code', ['SLO', 'LBK'])->select('id'))->pluck('id', 'code');
        if ($outlets->isEmpty()) {
            return;
        }
        $this->accurateCompanies();
        $tags = collect(['VIP', 'Reseller', 'Katering', 'Pecinta steak'])->mapWithKeys(fn ($n) => [$n => Tag::firstOrCreate(['name' => $n])->id]);

        $first = ['Budi', 'Sari', 'Andi', 'Dewi', 'Rudi', 'Maya', 'Agus', 'Rina', 'Hendra', 'Lusi', 'Tono', 'Wati', 'Joko', 'Nina', 'Eko', 'Fitri', 'Bayu', 'Intan', 'Yusuf', 'Lina'];
        $last = ['Santoso', 'Wijaya', 'Pratama', 'Lestari', 'Hidayat', 'Saputra', 'Kusuma', 'Halim', 'Nugroho', 'Permata'];
        mt_srand(20261105);
        for ($i = 0; $i < 60; $i++) {
            $code = $i % 3 === 0 ? 'LBK' : 'SLO';
            $phone = '628'.(12 + $i % 7).sprintf('%08d', 31000000 + $i * 7919);
            [$c] = $directory->upsert($phone, [
                'name' => $first[$i % 20].' '.$last[($i + intdiv($i, 20) * 3) % 10],
                'city' => $code === 'SLO' ? 'Surakarta' : 'Mataram',
                'birth_date' => sprintf('%04d-%02d-%02d', 1970 + $i % 30, 1 + $i % 12, 1 + $i % 27),
                'member_code' => sprintf('%s-M%04d', $code, $i + 1),
                'joined_on' => now()->subDays(30 + $i * 9)->toDateString(),
            ], $outlets[$code], 'import');
            if ($i % 11 === 0 && $outlets->has('LBK') && $code === 'SLO') {
                $directory->upsert($phone, [], $outlets['LBK'], 'import'); // member di dua kota
            }
            $c->update(['wa_status' => $i % 9 === 0 ? 'opted_out' : ($i % 4 === 0 ? 'unknown' : 'opted_in'), 'wa_status_at' => now()->subDays($i)]);
            if ($i % 10 === 0) {
                $c->tags()->syncWithoutDetaching([$tags['VIP']]);
            }
            if ($i % 13 === 0) {
                $c->tags()->syncWithoutDetaching([$tags['Katering']]);
            }
            if ($i % 6 === 0) {
                $c->tags()->syncWithoutDetaching([$tags['Pecinta steak']]);
            }

            // Pola belanja beragam: loyal, aktif, berisiko, tidur, belum pernah belanja.
            $visits = [12, 6, 3, 2, 1, 0][$i % 6];
            $gap = [6, 12, 25, 70, 160, 0][$i % 6];
            for ($v = 0; $v < $visits; $v++) {
                $days = $gap * ($v + 1) - ($i % 5);
                $amount = (string) (mt_rand(8, 120) * 25000);
                $directory->recordPurchase($c, 'pos', sprintf('demo-%03d-%02d', $i, $v), now()->subDays(max(0, $days))->toDateString(), $amount, $outlets[$code], docNo: sprintf('%s-DEMO-%03d%02d', $code, $i, $v));
            }
        }

        foreach ([['Pecinta Steak Solo', 'STEAKSOLO', 'SLO', 6], ['Katering & Reseller Lombok', 'RESELLERLBK', 'LBK', 13]] as [$name, $kw, $code, $every]) {
            $community = Community::firstOrCreate(['name' => $name], ['join_keyword' => $kw, 'crm_branch_id' => $outlets[$code] ?? null]);
            $ids = Customer::orderBy('id')->pluck('id')->filter(fn ($id) => $id % $every === 0 || $id % 5 === 0)->values();
            $community->members()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['source' => 'segment', 'joined_at' => now()]])->all());
        }

        foreach ([
            ['Win-back Solo', 'Member Solo yang lama tidak belanja, boleh dikirimi promo', ['branch_ids' => [$outlets['SLO']], 'inactive_days' => 60, 'wa' => 'opted_in']],
            ['Ulang tahun bulan ini', 'Untuk ucapan dan voucher ulang tahun', ['birthday_month' => 'current', 'wa' => 'opted_in']],
            ['Pelanggan loyal', '≥ 5 transaksi dan aktif 60 hari terakhir', ['lifecycle' => 'loyal']],
            ['Belanja ≥ Rp5 juta', 'Kandidat VIP', ['min_total_spent' => 5000000]],
        ] as [$name, $desc, $rules]) {
            Segment::firstOrCreate(['name' => $name], ['description' => $desc, 'rules' => $rules]);
        }

        // Contoh knowledge base AI: ganti isinya dengan informasi resmi sebelum asisten AI diaktifkan.
        foreach ([
            ['Jam buka outlet', 'jam, buka, tutup, operasional, libur', 'CONTOH, mohon disesuaikan: outlet buka setiap hari pukul 10.00 sampai 21.00 waktu setempat. Pada hari raya jam buka bisa berubah dan diumumkan lewat WhatsApp komunitas.'],
            ['Cara pesan dan pengiriman', 'pesan, order, antar, kirim, ongkir, delivery', 'CONTOH, mohon disesuaikan: pesanan bisa lewat chat WhatsApp ini dengan menyebut produk, jumlah, alamat dan jam antar. Pengiriman dalam kota memakai kurir instan dengan ongkos sesuai aplikasi. Daging dikirim dalam kemasan dingin.'],
            ['Katering dan acara', 'katering, catering, acara, pax, prasmanan, nasi box', 'CONTOH, mohon disesuaikan: katering melayani acara mulai 30 porsi dan dipesan paling lambat 3 hari sebelumnya. Penawaran harga dibuat oleh tim penjualan setelah jumlah porsi, tanggal dan menu diketahui.'],
            ['Reseller dan pembelian grosir', 'reseller, grosir, agen, partai, restoran, hotel', 'CONTOH, mohon disesuaikan: harga khusus tersedia untuk reseller, restoran dan hotel dengan pembelian rutin. Tim penjualan akan menghubungi untuk mengirim daftar harga grosir dan syarat pembayaran.'],
        ] as [$title, $keywords, $body]) {
            KbArticle::firstOrCreate(['title' => $title], ['keywords' => $keywords, 'body' => $body, 'is_active' => true]);
        }

        // Contoh workflow (nonaktif): staf CRM tinggal memilih template yang sudah disetujui Meta lalu mengaktifkan.
        Workflow::firstOrCreate(['name' => 'Balas otomatis MENU'], [
            'trigger' => 'keyword', 'trigger_config' => ['keywords' => 'menu, daftar menu, harga'], 'cooldown_days' => 0,
            'steps' => [
                ['type' => 'send_text', 'body' => 'Halo {first_name}! Menu lengkap dan harga terbaru ada di https://indoguna.co.id/menu. Balas RESERVASI bila ingin pesan meja, CS kami akan membantu.'],
                ['type' => 'add_tag', 'tag_id' => $tags['Pecinta steak']],
            ],
        ]);
        Workflow::firstOrCreate(['name' => 'Sambut anggota komunitas, serahkan ke CS'], [
            'trigger' => 'joined_community', 'trigger_config' => [], 'cooldown_days' => 30,
            'steps' => [
                ['type' => 'wait', 'hours' => 2],
                ['type' => 'send_text', 'body' => 'Terima kasih sudah bergabung, {first_name}. Ada menu favorit yang ingin Anda coba minggu ini? Balas saja pesan ini.'],
                ['type' => 'assign', 'user_id' => null],
            ],
        ]);
    }

    /** CV grup yang masih memakai Accurate Online + Rene POS/Raptor: masuk CRM lewat Accurate dan impor member. */
    private function accurateCompanies(): void
    {
        foreach ([
            ['MKS', 'CV Makasar Kulina', 'rene', 'Makassar'],
            ['BPN', 'CV Balikpapan Kulina', 'rene', 'Balikpapan'],
            ['SIL', 'CV Sarana Indoguna Lestari', 'raptor', 'Jakarta'],
            ['JOG', 'CV Jogja Kulina Utama', 'rene', 'Yogyakarta'],
            ['SMG', 'CV Semarang Kulina Utama', 'rene', 'Semarang'],
            ['BDG', 'CV Bandung Kulina Utama', 'rene', 'Bandung'],
        ] as [$code, $name, $pos, $city]) {
            $bu = BusinessUnit::firstOrCreate(['code' => $code], ['name' => $name]);
            Branch::firstOrCreate(['code' => $code.'-01'], ['business_unit_id' => $bu->id, 'name' => "Outlet {$city}", 'pos_system' => $pos, 'city' => $city]);
        }
    }
}
