<?php

namespace App\Domain\Membership;

use App\Domain\Audit\Audit;
use App\Domain\Settings\Settings;
use App\Domain\Sync\CentralClient;
use App\Domain\Sync\Outbox;
use App\Models\IntegrationException;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberTier;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membership grup: satu nomor member berlaku di semua outlet. Tier dan aturan poin diatur di
 * Rasentra Central; Rasentra Lokal menghitung poin saat transaksi final (tetap jalan offline)
 * lalu mengirim mutasinya ke Central lewat outbox. Central adalah sumber saldo resmi dan
 * menerbitkan ulang saldo member ke semua outlet.
 */
final class MembershipService
{
    /** Id sementara untuk member yang didaftarkan di outlet, di atas rentang id Central. */
    public const PROVISIONAL_BASE = 900_000_000_000;

    public function __construct(private readonly Settings $settings) {}

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return $digits;
    }

    public function find(string $query): ?Member
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        return Member::where('is_member', true)
            ->where(fn ($q) => $q->where('code', strtoupper($query))->orWhere('phone', self::normalizePhone($query)))
            ->first();
    }

    /** Daftar member baru di outlet. Nomor telepon adalah kunci unik lintas outlet. */
    public function enroll(array $data, int $outletId, ?User $actor = null): Member
    {
        $phone = self::normalizePhone($data['phone']);
        if (strlen($phone) < 9) {
            throw new DomainException('phone_invalid', 'Nomor telepon tidak valid.', 422);
        }

        return DB::transaction(function () use ($data, $phone, $outletId, $actor) {
            $member = Member::where('phone', $phone)->lockForUpdate()->first();
            if ($member?->is_member) {
                throw DomainException::conflict('member_exists', "Nomor ini sudah terdaftar sebagai member {$member->code}.");
            }
            $tier = MemberTier::where('is_active', true)->orderBy('min_spend')->orderBy('sort')->first();
            $attrs = [
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
                'is_member' => true,
                'member_since' => now()->toDateString(),
                'home_outlet_id' => $outletId,
                'member_tier_id' => $member?->member_tier_id ?? $tier?->id,
                'code' => $member?->code ?? $this->newCode(),
            ];
            if ($member) {
                $member->update($attrs);
            } else {
                $member = Member::create(['id' => $this->provisionalId($outletId), 'phone' => $phone] + $attrs);
            }

            if (config('rasentra.profile') !== 'central') {
                Outbox::record($outletId, 'member', (string) $member->id, 1, 'member.enrolled',
                    $member->only(['id', 'code', 'phone', 'name', 'email', 'birth_date', 'home_outlet_id', 'member_tier_id', 'member_since']),
                    now()->toDateString());
            }
            Audit::record('member.enrolled', $member, $actor?->id, after: $member->only(['code', 'phone', 'name']), outletId: $outletId);

            return $member;
        });
    }

    /** Pasang member ke pesanan yang masih terbuka. */
    public function attach(Order $order, ?Member $member): Order
    {
        if (! $order->isEditable()) {
            throw DomainException::conflict('order_not_editable', 'Pesanan sudah final.');
        }
        if ($member === null && $order->payments()->where('method', 'points')->exists()) {
            throw DomainException::conflict('points_in_use', 'Batalkan pembayaran poin dulu sebelum melepas member.');
        }
        $order->update([
            'crm_customer_id' => $member?->id,
            'customer_code' => $member?->code,
            'customer_name' => $order->customer_name ?: $member?->name,
            'customer_phone' => $order->customer_phone ?: $member?->phone,
        ]);

        return $order;
    }

    /** Validasi pembayaran dengan poin: member terpasang, saldo cukup, minimal tukar. */
    public function assertRedeemable(Order $order, string $amount): void
    {
        if (! $this->settings->get('member.enabled', $order->outlet_id)) {
            throw new DomainException('member_disabled', 'Program member sedang tidak aktif.', 422);
        }
        $member = $order->crm_customer_id ? Member::find($order->crm_customer_id) : null;
        if ($member === null) {
            throw new DomainException('member_required', 'Pasang member di pesanan ini untuk membayar dengan poin.', 422);
        }
        $points = $this->pointsFor($amount, $order->outlet_id);
        $already = $this->pointsFor((string) Decimal::sum($order->payments()->where('method', 'points')->whereIn('status', ['succeeded', 'pending'])->pluck('amount')), $order->outlet_id);
        $min = $this->settings->get('member.min_redeem', $order->outlet_id);
        if ($points->isLessThan($min)) {
            throw new DomainException('points_below_min', "Minimal penukaran {$min} poin.", 422);
        }
        [$available, $offline] = $this->availablePoints($member, $order->outlet_id);
        if ($points->plus($already)->isGreaterThan($available)) {
            if ($offline) {
                throw new DomainException('points_offline_limit', 'Rasentra Central tidak terjangkau. Saat offline penukaran dibatasi '.Decimal::money($available).' poin per member agar poin tidak terpakai ganda di outlet lain.', 422);
            }
            throw new DomainException('points_insufficient', 'Poin member tidak cukup. Saldo '.Decimal::money($available).' poin.', 422);
        }
    }

    /**
     * Saldo yang boleh ditukar di outlet ini. Saldo lokal bisa tertinggal bila member baru saja
     * menukar poin di outlet lain, jadi Rasentra Lokal menanyakan saldo resmi ke Central dan
     * memakai yang lebih kecil. Bila Central tidak terjangkau, penukaran dibatasi
     * member.offline_redeem_max poin supaya risiko penukaran ganda kecil dan terukur.
     *
     * @return array{0: BigDecimal, 1: bool} [saldo yang boleh dipakai, dibatasi karena offline]
     */
    public function availablePoints(Member $member, int $outletId): array
    {
        $local = Decimal::of($member->points_balance);
        if (config('rasentra.profile') === 'central' || blank(config('rasentra.central.url'))) {
            return [$local, false];
        }
        try {
            $res = app(CentralClient::class)->request(3)->get('member-balance', ['phone' => $member->phone]);
            if ($res->successful()) {
                $central = $res->json('points_balance');
                if ($central === null) {
                    return [$local, false]; // member belum sampai di Central: hanya poin outlet ini
                }
                $central = Decimal::of($central);

                return [$central->isLessThan($local) ? $central : $local, false];
            }
        } catch (\Throwable) {
            // jatuh ke batas offline
        }
        $cap = Decimal::of((string) $this->settings->get('member.offline_redeem_max', $outletId));

        return $cap->isLessThan($local) ? [$cap, true] : [$local, false];
    }

    /**
     * Dipanggil di dalam transaksi finalisasi: potong poin yang ditukar, beri poin dari belanja
     * non-poin dikali pengali tier, lalu catat mutasi ke outbox untuk Central.
     */
    public function settle(Order $order, User $actor): void
    {
        if (! $order->crm_customer_id) {
            return;
        }
        $member = Member::lockForUpdate()->find($order->crm_customer_id);
        if ($member === null) {
            return;
        }
        $outletId = $order->outlet_id;
        $redeemAmount = Decimal::sum($order->payments()->where('method', 'points')->where('status', 'succeeded')->pluck('amount'));
        $redeemed = $this->pointsFor((string) $redeemAmount, $outletId);
        $earnBase = Decimal::of($order->net_amount)->minus($redeemAmount);

        $earned = Decimal::of(0);
        if ($this->settings->get('member.enabled', $outletId)) {
            $per = Decimal::of($this->settings->get('member.earn_per_amount', $outletId));
            if ($per->isPositive() && $earnBase->isPositive()) {
                $multiplier = Decimal::of(MemberTier::whereKey($member->member_tier_id)->value('point_multiplier') ?? 1);
                $earned = $earnBase->dividedBy($per, 0, RoundingMode::Down)->multipliedBy($multiplier)->toScale(0, RoundingMode::Down);
            }
        }

        $entries = [];
        $date = $order->business_date->toDateString();
        if ($redeemed->isPositive()) {
            $entries[] = ['type' => 'redeem', 'points' => Decimal::money($redeemed->negated()), 'order_amount' => Decimal::money($redeemAmount), 'note' => 'Tukar poin '.$order->doc_no];
        }
        if ($earned->isPositive()) {
            $entries[] = ['type' => 'earn', 'points' => Decimal::money($earned), 'order_amount' => Decimal::money($earnBase), 'note' => 'Belanja '.$order->doc_no];
        }
        if ($entries === []) {
            return;
        }
        $rows = [];
        foreach ($entries as $e) {
            $rows[] = MemberPointEntry::create($e + [
                'id' => (string) Str::uuid7(),
                'crm_customer_id' => $member->id,
                'outlet_id' => $outletId,
                'order_id' => $order->id,
                'business_date' => $date,
                'created_by' => $actor->id,
            ])->toArray();
        }
        $member->update([
            'points_balance' => Decimal::money(Decimal::of($member->points_balance)->plus($earned)->minus($redeemed)),
            'member_spend' => Decimal::money(Decimal::of($member->member_spend)->plus($earnBase)),
        ]);
        $order->update(['points_earned' => Decimal::money($earned), 'points_redeemed' => Decimal::money($redeemed)]);

        Outbox::record($outletId, 'member', (string) $member->id, 1, 'member.points',
            ['member_id' => $member->id, 'phone' => $member->phone, 'entries' => $rows], $date);
    }

    /**
     * Dipanggil di dalam transaksi retur: poin yang dulu didapat dari struk ini ditarik pro-rata
     * nilai retur, dan bila uang dikembalikan sebagai poin, poinnya dikreditkan lagi. Mutasinya
     * dikirim ke Central lewat outbox seperti mutasi transaksi biasa.
     */
    public function reverseForReturn(Order $order, SalesReturn $return, User $actor): void
    {
        if (! $order->crm_customer_id) {
            return;
        }
        $member = Member::lockForUpdate()->find($order->crm_customer_id);
        if ($member === null) {
            return;
        }
        $outletId = $order->outlet_id;
        $earned = Decimal::of($order->points_earned ?? 0);
        $reversed = Decimal::sum(MemberPointEntry::where('order_id', $order->id)->where('type', 'reverse')->pluck('points'))->negated();
        $orderNet = Decimal::of($order->net_amount);
        $returnNet = Decimal::of($return->net_amount);
        $redeemAmount = Decimal::sum($order->payments()->where('method', 'points')->where('status', 'succeeded')->pluck('amount'));
        $earnBase = $orderNet->minus($redeemAmount);

        $entries = [];
        if ($earned->isPositive() && $orderNet->isPositive()) {
            $claw = $earned->multipliedBy($returnNet)->dividedBy($orderNet, 0, RoundingMode::Up);
            $left = $earned->minus($reversed);
            $claw = $claw->isGreaterThan($left) ? $left : $claw;
            if ($claw->isPositive()) {
                $spend = $earnBase->multipliedBy($returnNet)->dividedBy($orderNet, 2, RoundingMode::Down);
                $entries[] = ['type' => 'reverse', 'points' => Decimal::money($claw->negated()), 'order_amount' => Decimal::money($spend), 'note' => 'Retur '.$return->doc_no.' atas '.$order->doc_no];
            }
        }
        if ($return->refund_method === 'points') {
            $back = $this->pointsFor((string) $return->net_amount, $outletId)->toScale(0, RoundingMode::Down);
            if ($back->isPositive()) {
                $entries[] = ['type' => 'refund', 'points' => Decimal::money($back), 'order_amount' => Decimal::money($returnNet), 'note' => 'Pengembalian poin '.$return->doc_no];
            }
        }
        if ($entries === []) {
            return;
        }

        $date = $return->business_date->toDateString();
        $rows = [];
        $balance = Decimal::of($member->points_balance);
        $spend = Decimal::of($member->member_spend);
        foreach ($entries as $e) {
            $rows[] = MemberPointEntry::create($e + [
                'id' => (string) Str::uuid7(),
                'crm_customer_id' => $member->id,
                'outlet_id' => $outletId,
                'order_id' => $order->id,
                'business_date' => $date,
                'created_by' => $actor->id,
            ])->toArray();
            $balance = $balance->plus($e['points']);
            if ($e['type'] === 'reverse') {
                $spend = $spend->minus($e['order_amount']);
            }
        }
        // Poin yang sudah terpakai tidak bisa ditarik lagi; saldo berhenti di nol dan selisihnya tercatat di ledger.
        $member->update([
            'points_balance' => Decimal::money($balance->isNegative() ? Decimal::of(0) : $balance),
            'member_spend' => Decimal::money($spend->isNegative() ? Decimal::of(0) : $spend),
        ]);

        Outbox::record($outletId, 'member', (string) $member->id, 1, 'member.points',
            ['member_id' => $member->id, 'phone' => $member->phone, 'entries' => $rows], $date);
    }

    /** Central: terapkan mutasi poin sekali saja, perbarui saldo dan tier. */
    public function applyAtCentral(array $entries, ?int $memberId = null, ?string $phone = null): ?Member
    {
        $member = ($phone ? Member::where('phone', $phone)->first() : null) ?? ($memberId ? Member::find($memberId) : null);
        if ($member === null) {
            return null;
        }
        foreach ($entries as $e) {
            if (MemberPointEntry::whereKey($e['id'])->exists()) {
                continue;
            }
            MemberPointEntry::create(array_intersect_key($e, array_flip(['id', 'outlet_id', 'order_id', 'type', 'points', 'order_amount', 'note', 'business_date', 'created_by']))
                + ['crm_customer_id' => $member->id, 'applied_at_central' => true]);
            $member->points_balance = Decimal::money(Decimal::of($member->points_balance)->plus($e['points']));
            if ($e['type'] === 'earn') {
                $member->member_spend = Decimal::money(Decimal::of($member->member_spend)->plus($e['order_amount']));
            } elseif ($e['type'] === 'reverse') {
                $spend = Decimal::of($member->member_spend)->minus($e['order_amount']);
                $member->member_spend = Decimal::money($spend->isNegative() ? Decimal::of(0) : $spend);
            }
        }
        // Saldo minus berarti poin yang sama ditukar di lebih dari satu outlet sebelum tersinkron
        // (mis. salah satu outlet offline), atau poin hasil belanja yang diretur sudah terpakai.
        // Ditampilkan di antrean pengecualian agar pusat menindaklanjuti (koreksi atau tagih).
        if (Decimal::of($member->points_balance)->isNegative()) {
            IntegrationException::updateOrCreate(
                ['kind' => 'points_negative', 'source_type' => 'member', 'source_id' => (string) $member->id, 'status' => 'open'],
                ['message' => "Saldo poin member {$member->code} ({$member->name}) minus ".Decimal::money($member->points_balance).'. Periksa penukaran ganda antar-outlet lalu koreksi lewat penyesuaian poin.'],
            );
        }
        $member->member_tier_id = $this->tierFor((string) $member->member_spend)?->id ?? $member->member_tier_id;
        $member->save();

        return $member;
    }

    /** Penyesuaian manual dari Rasentra Core (koreksi, kompensasi keluhan). */
    public function adjust(Member $member, string $points, string $note, User $actor): Member
    {
        return DB::transaction(function () use ($member, $points, $note, $actor) {
            $member = Member::lockForUpdate()->findOrFail($member->id);
            $new = Decimal::of($member->points_balance)->plus($points);
            if ($new->isNegative()) {
                throw new DomainException('points_negative', 'Saldo poin tidak boleh minus.', 422);
            }
            MemberPointEntry::create([
                'id' => (string) Str::uuid7(), 'crm_customer_id' => $member->id, 'type' => 'adjust',
                'points' => Decimal::money(Decimal::of($points)), 'note' => $note, 'business_date' => now()->toDateString(),
                'created_by' => $actor->id, 'applied_at_central' => true,
            ]);
            $member->update(['points_balance' => Decimal::money($new)]);
            Audit::record('member.points_adjusted', $member, $actor->id, after: ['points' => $points, 'note' => $note]);

            return $member;
        });
    }

    public function tierFor(string $spend): ?MemberTier
    {
        return MemberTier::where('is_active', true)->where('min_spend', '<=', $spend)->orderByDesc('min_spend')->first();
    }

    public function pointsFor(string $amount, int $outletId): BigDecimal
    {
        $value = Decimal::of($this->settings->get('member.point_value', $outletId));

        return $value->isPositive() ? Decimal::of($amount)->dividedBy($value, 2, RoundingMode::Up) : Decimal::of(0);
    }

    private function newCode(): string
    {
        do {
            $code = 'M'.strtoupper(Str::random(7));
        } while (Member::where('code', $code)->exists());

        return $code;
    }

    private function provisionalId(int $outletId): int
    {
        if (config('rasentra.profile') === 'central') {
            return (int) Member::where('id', '<', self::PROVISIONAL_BASE)->max('id') + 1;
        }
        $base = self::PROVISIONAL_BASE + $outletId * 100_000_000;

        return max($base, (int) Member::where('id', '>=', $base)->where('id', '<', $base + 100_000_000)->max('id')) + 1;
    }
}
