<?php

namespace App\Http\Controllers;

use App\Domain\Membership\MembershipService;
use App\Domain\Sales\PaymentService;
use App\Domain\Settings\Settings;
use App\Models\Member;
use App\Models\MemberTier;
use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rasentra POS: cari dan daftarkan member, pasang ke transaksi, daftar metode bayar outlet. */
class PosMemberController extends Controller
{
    public function __construct(private readonly MembershipService $members, private readonly Settings $settings) {}

    public function lookup(Request $request): JsonResponse
    {
        $member = $this->members->find((string) $request->query('q', ''));

        return response()->json(['member' => $member ? $this->present($member, $request->user()->outlet_id) : null]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'birth_date' => ['nullable', 'date'],
        ]);
        $member = $this->members->enroll($data, (int) $request->user()->outlet_id, $request->user());

        return response()->json(['member' => $this->present($member, $request->user()->outlet_id)], 201);
    }

    public function attach(Request $request, Order $order): JsonResponse
    {
        abort_unless((int) $order->outlet_id === (int) $request->user()->outlet_id || $request->user()->role === 'admin', 404);
        $data = $request->validate(['member_id' => ['nullable', 'integer']]);
        $member = ! empty($data['member_id']) ? Member::where('is_member', true)->findOrFail($data['member_id']) : null;
        $order = $this->members->attach($order, $member);

        return response()->json([
            'order' => $order->only(['id', 'crm_customer_id', 'customer_code', 'customer_name', 'customer_phone']),
            'member' => $member ? $this->present($member, $order->outlet_id) : null,
        ]);
    }

    public function methods(Request $request): JsonResponse
    {
        return response()->json(self::forOutlet((int) $request->user()->outlet_id));
    }

    /** Metode bayar aktif untuk outlet; bila belum diatur pusat, memakai daftar bawaan. */
    public static function forOutlet(int $outletId): array
    {
        $rows = PaymentMethod::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->filter(fn ($m) => empty($m->outlet_ids) || in_array($outletId, array_map('intval', $m->outlet_ids), true));
        if ($rows->isEmpty()) {
            return collect(PaymentService::METHODS)->reject(fn ($m) => $m === 'points')
                ->map(fn ($m) => ['code' => $m, 'name' => strtoupper(str_replace('_', ' ', $m)), 'type' => $m === 'cash' ? 'cash' : 'manual', 'requires_reference' => false])->values()->all();
        }

        return $rows->map->only(['code', 'name', 'type', 'requires_reference'])->values()->all();
    }

    private function present(Member $m, ?int $outletId): array
    {
        $tier = $m->member_tier_id ? MemberTier::find($m->member_tier_id) : null;
        $pointValue = $this->settings->get('member.point_value', $outletId);

        return $m->only(['id', 'code', 'name', 'phone']) + [
            'tier' => $tier?->name,
            'points' => (string) $m->points_balance,
            'points_value' => (string) round((float) $m->points_balance * (float) $pointValue),
        ];
    }
}
