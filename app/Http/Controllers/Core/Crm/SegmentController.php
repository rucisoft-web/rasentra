<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Crm\SegmentQuery;
use App\Http\Controllers\Controller;
use App\Models\Crm\Branch;
use App\Models\Crm\Customer;
use App\Models\Crm\Segment;
use App\Models\Crm\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Rasentra CRM: segmen pelanggan berbasis aturan (outlet, tag, RFM, ulang tahun, status WhatsApp). */
class SegmentController extends Controller
{
    public function index(): View
    {
        $segments = Segment::orderBy('name')->get();

        return view('core.crm.segments.index', [
            'title' => 'Segmen pelanggan',
            'segments' => $segments->map(fn (Segment $s) => [
                'segment' => $s,
                'count' => SegmentQuery::apply(Customer::query(), $s->rules)->count(),
            ]),
            'outlets' => Branch::pluck('code', 'id')->all(),
            'tags' => Tag::pluck('name', 'id')->all(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form(new Segment(['rules' => SegmentQuery::clean($request->query())]));
    }

    public function edit(Segment $segment): View
    {
        return $this->form($segment);
    }

    public function store(Request $request): RedirectResponse
    {
        $segment = Segment::create($this->validated($request) + ['created_by' => $request->user()->id]);
        Audit::record('crm.segment.created', $segment, $request->user()->id, after: $segment->rules);

        return redirect()->route('core.crm.customers.index', ['segment' => $segment->id])->with('ok', "Segmen \"{$segment->name}\" disimpan.");
    }

    public function update(Request $request, Segment $segment): RedirectResponse
    {
        $before = $segment->rules;
        $segment->update($this->validated($request, $segment));
        Audit::record('crm.segment.updated', $segment, $request->user()->id, before: $before, after: $segment->rules);

        return redirect()->route('core.crm.segments.index')->with('ok', 'Segmen diperbarui.');
    }

    public function destroy(Request $request, Segment $segment): RedirectResponse
    {
        Audit::record('crm.segment.deleted', $segment, $request->user()->id, before: $segment->rules);
        $segment->delete();

        return redirect()->route('core.crm.segments.index')->with('ok', 'Segmen dihapus.');
    }

    private function form(Segment $segment): View
    {
        Branch::syncFromOutlets();

        return view('core.crm.segments.form', [
            'title' => $segment->exists ? "Ubah segmen {$segment->name}" : 'Segmen baru',
            'segment' => $segment,
            'rules' => $segment->rules ?? [],
            'preview' => $segment->exists || $segment->rules ? SegmentQuery::apply(Customer::query(), $segment->rules ?? [])->count() : null,
            'outlets' => Branch::orderBy('code')->get(['id', 'code', 'name']),
            'tags' => Tag::orderBy('name')->get(),
        ]);
    }

    private function validated(Request $request, ?Segment $segment = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('crm_segments', 'name')->ignore($segment?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'branch_ids' => ['array'], 'branch_ids.*' => ['integer', 'exists:crm_branches,id'],
            'tag_ids' => ['array'], 'tag_ids.*' => ['integer', 'exists:crm_tags,id'],
            'lifecycle' => ['nullable', Rule::in(array_keys(SegmentQuery::LIFECYCLES))],
            'wa' => ['nullable', Rule::in(['opted_in', 'reachable', 'opted_out'])],
            'active_within_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'inactive_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'min_purchase_count' => ['nullable', 'integer', 'min:1'],
            'min_total_spent' => ['nullable', 'numeric', 'min:0'],
            'birthday_month' => ['nullable', Rule::in(array_merge(['current'], array_map('strval', range(1, 12))))],
            'city' => ['nullable', 'string', 'max:80'],
        ]);

        return ['name' => $data['name'], 'description' => $data['description'] ?? null, 'rules' => SegmentQuery::clean($data)];
    }
}
