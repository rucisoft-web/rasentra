<?php

namespace Database\Seeders;

use App\Domain\Crm\CustomerDirectory;
use App\Models\Crm\Segment;
use App\Models\Crm\Tag;
use App\Models\Outlet;
use Illuminate\Database\Seeder;

/** Data contoh Rasentra CRM: member Solo dan Lombok, riwayat belanja, tag dan segmen siap pakai. */
class CrmSeeder extends Seeder
{
    public function run(CustomerDirectory $directory): void
    {
        $outlets = Outlet::whereIn('code', ['SLO', 'LBK'])->pluck('id', 'code');
        if ($outlets->isEmpty()) {
            return;
        }
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

        foreach ([
            ['Win-back Solo', 'Member Solo yang lama tidak belanja, boleh dikirimi promo', ['outlet_ids' => [$outlets['SLO']], 'inactive_days' => 60, 'wa' => 'opted_in']],
            ['Ulang tahun bulan ini', 'Untuk ucapan dan voucher ulang tahun', ['birthday_month' => 'current', 'wa' => 'opted_in']],
            ['Pelanggan loyal', '≥ 5 transaksi dan aktif 60 hari terakhir', ['lifecycle' => 'loyal']],
            ['Belanja ≥ Rp5 juta', 'Kandidat VIP', ['min_total_spent' => 5000000]],
        ] as [$name, $desc, $rules]) {
            Segment::firstOrCreate(['name' => $name], ['description' => $desc, 'rules' => $rules]);
        }
    }
}
