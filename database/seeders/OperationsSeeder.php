<?php

namespace Database\Seeders;

use App\Http\Controllers\Core\AccessController;
use App\Models\Attendance;
use App\Models\Delivery;
use App\Models\DiningTable;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberTier;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShiftTemplate;
use App\Models\StaffRoster;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Master operasional grup (peran & izin, metode bayar, tier member, template shift, pengaturan
 * dasar) dan, di luar produksi, data contoh: QR meja, kurir, roster minggu ini, member dengan
 * poin, serta riwayat delivery dan absensi 7 hari untuk laporan ringkasan grup.
 */
class OperationsSeeder extends Seeder
{
    public function run(): void
    {
        AccessController::ensureRoles();

        foreach ([
            ['cash', 'Tunai', 'cash', false, '1101', 1],
            ['qris', 'QRIS', 'integrated', false, '1102', 2],
            ['edc', 'EDC BCA', 'integrated', false, '1103', 3],
            ['edc_manual', 'EDC manual', 'manual', true, '1103', 4],
            ['transfer', 'Transfer bank', 'manual', true, '1104', 5],
            ['voucher', 'Voucher', 'voucher', false, '2105', 6],
            ['points', 'Poin member', 'points', false, '2106', 7],
        ] as [$code, $name, $type, $ref, $acct, $sort]) {
            PaymentMethod::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'requires_reference' => $ref, 'sap_account' => $acct, 'sort' => $sort, 'is_active' => true]);
        }

        foreach ([
            ['SILVER', 'Silver', 0, 1, 'Poin dasar di semua outlet'],
            ['GOLD', 'Gold', 5_000_000, 1.25, 'Poin 1,25×, prioritas reservasi'],
            ['PLATINUM', 'Platinum', 20_000_000, 1.5, 'Poin 1,5×, undangan tasting menu baru'],
        ] as $i => [$code, $name, $min, $mult, $benefit]) {
            MemberTier::firstOrCreate(['code' => $code], ['name' => $name, 'min_spend' => $min, 'point_multiplier' => $mult, 'benefits' => $benefit, 'sort' => $i, 'is_active' => true]);
        }

        foreach ([['PAGI', 'Pagi', '07:00', '15:00'], ['SIANG', 'Siang', '11:00', '19:00'], ['MALAM', 'Malam', '15:00', '23:00']] as [$code, $name, $start, $end]) {
            ShiftTemplate::firstOrCreate(['code' => $code], ['name' => $name, 'starts_at' => $start, 'ends_at' => $end, 'break_minutes' => 60, 'is_active' => true]);
        }

        Setting::firstOrCreate(['scope' => 'global', 'scope_id' => null, 'key' => 'receipt.footer'], ['value' => 'Terima kasih. Kumpulkan poin member di semua outlet '.config('rasentra.group_name').'.']);

        DiningTable::whereNull('qr_token')->get()->each(fn ($t) => $t->update(['qr_token' => Str::random(32)]));

        if (app()->isProduction() && ! config('rasentra.demo.enabled')) {
            return;
        }
        $this->demo();
    }

    private function demo(): void
    {
        $outlets = Outlet::orderBy('id')->get();
        $templates = ShiftTemplate::orderBy('starts_at')->get();
        foreach ($outlets as $o) {
            User::firstOrCreate(['email' => 'kurir@'.strtolower($o->code).'.test'], [
                'name' => 'Kurir '.$o->name, 'password' => Hash::make('password'), 'role' => 'courier',
                'outlet_id' => $o->id, 'pin_hash' => Hash::make('6666'), 'approval_limit' => 0, 'is_active' => true,
            ]);
            $monday = now($o->timezone)->startOfWeek();
            $staff = User::where('outlet_id', $o->id)->where('is_active', true)->orderBy('id')->get();
            foreach ($staff as $i => $u) {
                foreach (range(0, 6) as $d) {
                    $off = ($i + $d) % 7 === 6;
                    StaffRoster::firstOrCreate(['user_id' => $u->id, 'work_date' => $monday->copy()->addDays($d)->toDateString()],
                        ['outlet_id' => $o->id, 'shift_template_id' => $off ? null : $templates[($i % $templates->count())]->id]);
                }
            }
            // Absensi 7 hari terakhir (sebagian terlambat) untuk laporan kehadiran.
            foreach (range(1, 7) as $back) {
                $day = now($o->timezone)->subDays($back);
                foreach ($staff->take(6) as $i => $u) {
                    $t = $templates[$i % $templates->count()];
                    $start = Carbon::parse($day->toDateString().' '.$t->starts_at, $o->timezone)->utc();
                    $late = ($i + $back) % 5 === 0 ? 12 + $i : 0;
                    $in = $start->copy()->addMinutes($late ?: -5);
                    Attendance::firstOrCreate(['user_id' => $u->id, 'work_date' => $day->toDateString()], [
                        'id' => (string) Str::uuid7(), 'outlet_id' => $o->id, 'user_name' => $u->name, 'shift_code' => $t->code,
                        'scheduled_start' => $start, 'clock_in_at' => $in, 'clock_out_at' => $in->copy()->addHours(8),
                        'late_minutes' => $late, 'worked_minutes' => 480,
                    ]);
                }
            }
        }

        $silver = MemberTier::where('code', 'SILVER')->value('id');
        $gold = MemberTier::where('code', 'GOLD')->value('id');
        $people = [['Budi Santoso', '081234500011'], ['Sari Wulandari', '081234500012'], ['Andi Pratama', '081234500013'], ['Dewi Lestari', '081234500014'], ['Rudi Hartono', '081234500015'], ['Made Ayu', '081234500016']];
        foreach ($people as $i => [$name, $phone]) {
            $o = $outlets[$i % $outlets->count()];
            $phone = '62'.substr($phone, 1);
            $member = Member::where('phone', $phone)->first();
            if ($member === null) {
                $member = Member::create(['id' => (int) Member::max('id') + 1, 'phone' => $phone, 'name' => $name]);
            }
            $member->update(['code' => $member->code ?? sprintf('M%s%04d', $o->code, $i + 1), 'is_member' => true, 'home_outlet_id' => $o->id,
                'member_since' => now()->subDays(40 + $i * 9)->toDateString(), 'member_tier_id' => $i < 2 ? $gold : $silver]);
            if (MemberPointEntry::where('crm_customer_id', $member->id)->exists()) {
                continue;
            }
            $orders = DB::table('hq_orders')->where('outlet_id', $o->id)->where('kind', 'sale')->orderByDesc('business_date')->skip($i * 3)->limit(3)->get();
            $spend = 0;
            $points = 0;
            foreach ($orders as $ho) {
                $p = floor((float) $ho->net_amount / 10000) * ($i < 2 ? 1.25 : 1);
                MemberPointEntry::create(['id' => (string) Str::uuid7(), 'crm_customer_id' => $member->id, 'outlet_id' => $o->id, 'order_id' => $ho->id, 'type' => 'earn',
                    'points' => floor($p), 'order_amount' => $ho->net_amount, 'note' => 'Belanja '.$ho->doc_no, 'business_date' => $ho->business_date, 'applied_at_central' => true]);
                $spend += (float) $ho->net_amount;
                $points += floor($p);
            }
            $member->update(['points_balance' => $points + ($i < 2 ? 5000 : 800), 'member_spend' => $spend + ($i < 2 ? 6_000_000 : 400_000)]);
        }

        // Riwayat delivery untuk laporan: dari struk delivery yang sudah ada, atau contoh sintetis.
        foreach ($outlets as $o) {
            foreach (range(1, 5) as $n) {
                $date = now($o->timezone)->subDays($n)->toDateString();
                $promised = Carbon::parse($date.' 12:00', $o->timezone)->addMinutes($n * 20)->utc();
                Delivery::firstOrCreate(['order_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "demo-delivery-{$o->code}-{$n}")->toString()], [
                    'id' => (string) Str::uuid7(), 'outlet_id' => $o->id, 'doc_no' => sprintf('%s-%s-D%03d', $o->code, Carbon::parse($date)->format('ymd'), $n),
                    'provider' => ['internal', 'gofood', 'grabfood', 'internal', 'shopeefood'][$n - 1],
                    'courier_name' => $n % 2 ? 'Kurir '.$o->name : null, 'customer_name' => $people[$n % count($people)][0], 'customer_phone' => $people[$n % count($people)][1],
                    'address' => 'Jl. Contoh No. '.($n * 7), 'order_amount' => 150000 + $n * 35000, 'fee' => $n % 2 ? 10000 : 0,
                    'status' => $n === 3 ? 'failed' : 'delivered', 'business_date' => $date, 'promised_at' => $promised,
                    'assigned_at' => $promised->copy()->subMinutes(40), 'departed_at' => $promised->copy()->subMinutes(30),
                    'delivered_at' => $n === 3 ? null : $promised->copy()->addMinutes($n === 4 ? 15 : -5),
                ]);
            }
        }
    }
}
