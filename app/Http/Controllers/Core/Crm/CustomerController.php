<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Crm\CustomerDirectory;
use App\Domain\Crm\Phone;
use App\Domain\Crm\SegmentQuery;
use App\Http\Controllers\Controller;
use App\Models\Crm\Customer;
use App\Models\Crm\Segment;
use App\Models\Crm\Tag;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Rasentra CRM: database pelanggan lintas outlet dan profil 360° per pelanggan. */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $customers = $this->filtered($request)->with(['outlets:id,code', 'tags'])
            ->orderByDesc('last_purchase_on')->orderBy('name')->paginate(50)->withQueryString();

        $since = now()->subDays(30)->toDateString();

        return view('core.crm.customers.index', [
            'title' => 'Pelanggan',
            'customers' => $customers,
            'outlets' => Outlet::orderBy('code')->get(['id', 'code', 'name']),
            'tags' => Tag::orderBy('name')->get(),
            'segments' => Segment::orderBy('name')->get(['id', 'name']),
            'stats' => [
                'total' => Customer::count(),
                'opted_in' => Customer::where('wa_status', 'opted_in')->count(),
                'active' => Customer::where('last_purchase_on', '>=', $since)->count(),
                'new' => Customer::where('created_at', '>=', $since)->count(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('outlets:id,code')->orderBy('name');
        Audit::record('crm.customers.exported', 'Customer:export', $request->user()->id, after: ['filter' => $request->query()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Nama', 'No HP', 'Email', 'Kota', 'Outlet', 'Status WA', 'Transaksi', 'Total belanja', 'Terakhir belanja']);
            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    fputcsv($out, [$c->name, $c->phone, $c->email, $c->city, $c->outlets->pluck('code')->implode(' '), $c->wa_status, $c->purchase_count, $c->total_spent, $c->last_purchase_on?->toDateString()]);
                }
            });
            fclose($out);
        }, 'pelanggan-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function store(Request $request, CustomerDirectory $directory): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
        ]);
        $phone = Phone::normalize($data['phone']);
        if ($phone === null) {
            return back()->withInput()->withErrors(['phone' => 'Nomor HP tidak valid. Contoh: 0812 3456 7890.']);
        }
        [$customer, $created] = $directory->upsert($phone, ['name' => $data['name']], $data['outlet_id'] ?? null, 'manual');
        Audit::record('crm.customer.created', $customer, $request->user()->id, after: ['phone' => $phone]);

        return redirect()->route('core.crm.customers.show', $customer)->with('ok', $created ? 'Pelanggan ditambahkan.' : 'Nomor ini sudah terdaftar; profil yang ada ditampilkan.');
    }

    public function show(Customer $customer): View
    {
        $customer->load(['memberships.outlet', 'tags']);

        return view('core.crm.customers.show', [
            'title' => $customer->name,
            'customer' => $customer,
            'stage' => SegmentQuery::stageOf($customer),
            'purchases' => $customer->purchases()->with('outlet:id,code')->orderByDesc('purchased_on')->limit(50)->get(),
            'byOutlet' => $customer->purchases()->leftJoin('outlets', 'outlets.id', '=', 'crm_purchases.outlet_id')
                ->groupBy('outlets.code', 'crm_purchases.source')
                ->get([DB::raw("COALESCE(outlets.code, '-') AS code"), 'crm_purchases.source', DB::raw('COUNT(*) AS n'), DB::raw('SUM(amount) AS total')]),
            'externalIds' => DB::table('crm_external_ids')->leftJoin('business_units', 'business_units.id', '=', 'crm_external_ids.scope_id')
                ->where('crm_customer_id', $customer->id)->get(['system', 'business_units.code AS company', 'external_id']),
            'tags' => Tag::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'city' => ['nullable', 'string', 'max:80'],
            'wa_status' => ['required', Rule::in(['unknown', 'opted_in', 'opted_out'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'exists:crm_tags,id'],
        ]);
        $before = $customer->only(['name', 'email', 'birth_date', 'city', 'wa_status']);
        $fields = collect($data)->except('tag_ids')->all();
        if ($fields['wa_status'] !== $customer->wa_status) {
            $fields['wa_status_at'] = now();
        }
        $customer->update($fields);
        $customer->tags()->sync($data['tag_ids'] ?? []);
        Audit::record('crm.customer.updated', $customer, $request->user()->id, before: $before, after: $customer->only(array_keys($before)));

        return back()->with('ok', 'Profil pelanggan disimpan.');
    }

    public function storeTag(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60', 'unique:crm_tags,name']]);
        Tag::create($data);

        return back()->with('ok', "Tag \"{$data['name']}\" dibuat.");
    }

    private function filtered(Request $request): Builder
    {
        $q = Customer::query();
        if ($term = trim((string) $request->query('q'))) {
            $phone = Phone::normalize($term);
            $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', '%'.($phone ?? preg_replace('/\D/', '', $term) ?: $term).'%'));
        }
        $rules = SegmentQuery::clean([
            'outlet_ids' => array_filter([(int) $request->query('outlet')]),
            'tag_ids' => array_filter([(int) $request->query('tag')]),
            'lifecycle' => $request->query('stage'),
            'wa' => $request->query('wa'),
        ]);
        if ($segmentId = (int) $request->query('segment')) {
            $rules = array_merge(Segment::find($segmentId)?->rules ?? [], $rules);
        }

        return SegmentQuery::apply($q, $rules);
    }
}
