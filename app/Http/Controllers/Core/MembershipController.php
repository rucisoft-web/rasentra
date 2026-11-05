<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Membership\MembershipService;
use App\Domain\Sync\Publications;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberTier;
use App\Models\Outlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Membership management: member grup lintas outlet, tier dan poin. Data pelanggan dasar
 * (master, segmentasi, WhatsApp) dikelola modul Rasentra CRM; halaman ini fokus ke keanggotaan.
 */
class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $members, private readonly Publications $publications) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $members = Member::where('is_member', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('code', strtoupper($q))->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $q ?: 'x').'%')))
            ->when($request->query('tier'), fn ($w, $t) => $w->where('member_tier_id', $t))
            ->orderByDesc('member_spend')->paginate(30)->withQueryString();

        return view('core.ops.members', [
            'title' => 'Membership',
            'members' => $members,
            'tiers' => MemberTier::orderBy('min_spend')->get()->keyBy('id'),
            'outlets' => Outlet::pluck('code', 'id'),
            'stats' => [
                'members' => Member::where('is_member', true)->count(),
                'points' => (string) Member::where('is_member', true)->sum('points_balance'),
                'new30' => Member::where('is_member', true)->where('member_since', '>=', now()->subDays(30)->toDateString())->count(),
            ],
        ]);
    }

    public function show(Member $member): View
    {
        abort_unless($member->is_member, 404);

        return view('core.ops.member', [
            'title' => "Member {$member->code}",
            'member' => $member,
            'tier' => MemberTier::find($member->member_tier_id),
            'ledger' => MemberPointEntry::where('crm_customer_id', $member->id)->latest('created_at')->limit(100)->get(),
            'outlets' => Outlet::pluck('code', 'id'),
        ]);
    }

    public function enroll(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email'],
            'home_outlet_id' => ['required', 'exists:outlets,id'],
        ]);
        $member = $this->members->enroll($data, (int) $data['home_outlet_id'], $request->user());
        $this->publications->master('member', $member->id);

        return redirect()->route('core.members.show', $member)->with('ok', "Member {$member->code} terdaftar dan dikirim ke semua outlet.");
    }

    public function adjust(Request $request, Member $member): RedirectResponse
    {
        $data = $request->validate(['points' => ['required', 'numeric', 'not_in:0'], 'note' => ['required', 'string', 'max:200']]);
        $member = $this->members->adjust($member, (string) $data['points'], $data['note'], $request->user());
        $this->publications->master('member', $member->id);

        return back()->with('ok', 'Poin disesuaikan.');
    }

    public function tiers(): View
    {
        return view('core.ops.member-tiers', [
            'title' => 'Tier member',
            'tiers' => MemberTier::orderBy('min_spend')->get(),
            'counts' => Member::where('is_member', true)->selectRaw('member_tier_id, count(*) as n')->groupBy('member_tier_id')->pluck('n', 'member_tier_id'),
        ]);
    }

    public function storeTier(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'alpha_dash', 'max:20', Rule::unique('member_tiers', 'code')]]);
        $tier = MemberTier::create(['code' => strtoupper($request->input('code'))] + $this->tierAttrs($request));
        $this->publications->master('member_tier', $tier->id);
        Audit::record('member_tier.created', $tier, $request->user()->id, after: $tier->only(['code', 'name', 'min_spend', 'point_multiplier']));

        return back()->with('ok', "Tier {$tier->name} dibuat.");
    }

    public function updateTier(Request $request, MemberTier $tier): RedirectResponse
    {
        $before = $tier->only(['name', 'min_spend', 'point_multiplier', 'is_active']);
        $tier->update($this->tierAttrs($request));
        $this->publications->master('member_tier', $tier->id);
        Audit::record('member_tier.updated', $tier, $request->user()->id, before: $before, after: $tier->only(array_keys($before)));

        return back()->with('ok', "Tier {$tier->name} diperbarui. Member naik tier saat transaksi berikutnya tercatat di pusat.");
    }

    private function tierAttrs(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'min_spend' => ['required', 'numeric', 'min:0'],
            'point_multiplier' => ['required', 'numeric', 'min:0', 'max:10'],
            'benefits' => ['nullable', 'string', 'max:300'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return ['name' => $data['name'], 'min_spend' => $data['min_spend'], 'point_multiplier' => $data['point_multiplier'], 'benefits' => $data['benefits'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? false)];
    }
}
