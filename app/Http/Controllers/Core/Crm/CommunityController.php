<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Crm\Phone;
use App\Domain\Crm\SegmentQuery;
use App\Http\Controllers\Controller;
use App\Models\Crm\Branch;
use App\Models\Crm\Campaign;
use App\Models\Crm\Community;
use App\Models\Crm\Customer;
use App\Models\Crm\Segment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rasentra CRM: komunitas WhatsApp (per outlet atau minat). Anggota masuk dari segmen, manual,
 * atau sendiri dengan mengirim kata kunci komunitas ke nomor WhatsApp. Iklan untuk komunitas
 * dikirim dari menu Kampanye & iklan.
 */
class CommunityController extends Controller
{
    public function index(): View
    {
        return view('core.crm.communities.index', [
            'title' => 'Komunitas WhatsApp',
            'communities' => Community::with('branch')->withCount([
                'members',
                'members as opted_in_count' => fn ($q) => $q->where('wa_status', 'opted_in'),
            ])->orderBy('name')->get(),
            'branches' => Branch::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $community = Community::create($this->validated($request));
        Audit::record('crm.community.created', $community, $request->user()->id, after: $community->only(['name', 'join_keyword']));

        return redirect()->route('core.crm.communities.show', $community)->with('ok', 'Komunitas dibuat.');
    }

    public function show(Request $request, Community $community): View
    {
        $members = $community->members()->with('branches:id,code')->orderBy('name');
        if ($term = trim((string) $request->query('q'))) {
            $members->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $term).'%'));
        }

        return view('core.crm.communities.show', [
            'title' => $community->name,
            'community' => $community->load('branch'),
            'members' => $members->paginate(50)->withQueryString(),
            'optedIn' => $community->members()->where('wa_status', 'opted_in')->count(),
            'segments' => Segment::orderBy('name')->get(['id', 'name']),
            'branches' => Branch::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'campaigns' => Campaign::whereJsonContains('audience->community_ids', $community->id)->latest()->limit(10)->get(),
        ]);
    }

    public function update(Request $request, Community $community): RedirectResponse
    {
        $community->update($this->validated($request, $community));

        return back()->with('ok', 'Komunitas diperbarui.');
    }

    /** Tambah anggota dari segmen atau dari semua member satu cabang. */
    public function addMembers(Request $request, Community $community): RedirectResponse
    {
        $data = $request->validate([
            'segment_id' => ['nullable', 'exists:crm_segments,id'],
            'crm_branch_id' => ['nullable', 'exists:crm_branches,id'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        $q = Customer::query();
        if (! empty($data['segment_id'])) {
            SegmentQuery::apply($q, Segment::find($data['segment_id'])->rules);
        } elseif (! empty($data['crm_branch_id'])) {
            SegmentQuery::apply($q, ['branch_ids' => [$data['crm_branch_id']]]);
        } elseif (! empty($data['phone'])) {
            $q->where('phone', Phone::normalize($data['phone']) ?? '-');
        } else {
            return back()->with('error', 'Pilih segmen, cabang atau isi nomor HP.');
        }
        $before = $community->members()->count();
        $q->select('id')->chunkById(1000, function ($rows) use ($community, $data) {
            $source = ! empty($data['segment_id']) ? 'segment' : 'manual';
            $community->members()->syncWithoutDetaching($rows->mapWithKeys(fn ($c) => [$c->id => ['source' => $source, 'joined_at' => now()]])->all());
        });
        $added = $community->members()->count() - $before;
        Audit::record('crm.community.members_added', $community, $request->user()->id, after: $data + ['added' => $added]);

        return back()->with('ok', "{$added} anggota ditambahkan.");
    }

    public function removeMember(Request $request, Community $community, Customer $customer): RedirectResponse
    {
        $community->members()->detach($customer->id);

        return back()->with('ok', "{$customer->name} dikeluarkan dari komunitas.");
    }

    private function validated(Request $request, ?Community $community = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('crm_communities', 'name')->ignore($community?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'crm_branch_id' => ['nullable', 'exists:crm_branches,id'],
            'join_keyword' => ['nullable', 'alpha_dash', 'max:30', Rule::unique('crm_communities', 'join_keyword')->ignore($community?->id)],
        ]);
        if (! empty($data['join_keyword'])) {
            $data['join_keyword'] = strtoupper($data['join_keyword']);
        }

        return $data;
    }
}
