<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SapMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Mapping kode Rasentra ke master SAP B1 per instance perusahaan (sap_solo, sap_lombok):
 * item (dan kode pajak), gudang per outlet, akun per metode bayar, dan customer umum.
 * Posting ditahan selama mapping instance tujuan belum lengkap.
 */
class SapMappingController extends Controller
{
    public const TYPES = ['product' => 'Produk → ItemCode', 'outlet' => 'Outlet → Gudang', 'payment_method' => 'Metode bayar → Akun', 'customer' => 'Customer',
        'account' => 'Penyesuaian stok → Akun (waste, count)'];

    public const METHODS = ['cash', 'qris', 'edc', 'edc_manual', 'voucher', 'points'];

    /** Perusahaan yang dipilih (atau dikirim lewat form); bawaan perusahaan pertama. */
    private function company(Request $request): BusinessUnit
    {
        $companies = BusinessUnit::whereNotNull('sap_company_db')->orderBy('id')->get();
        abort_if($companies->isEmpty(), 404, 'Belum ada perusahaan dengan database SAP.');

        return $companies->firstWhere('sap_company_db', $request->input('company')) ?? $companies->first();
    }

    public function index(Request $request): View
    {
        $type = $request->query('type', 'product');
        $company = $this->company($request);
        $companyDb = $company->sap_company_db;
        $mappings = SapMapping::where('company_db', $companyDb)->where('entity_type', $type)->orderBy('entity_key')->paginate(80)->withQueryString();

        $mappedProducts = SapMapping::where('company_db', $companyDb)->where('entity_type', 'product')->pluck('entity_key');
        $unmappedSold = DB::table('hq_order_lines')->join('products', 'products.id', '=', 'hq_order_lines.product_id')
            ->join('hq_orders', 'hq_orders.id', '=', 'hq_order_lines.hq_order_id')
            ->whereIn('hq_orders.outlet_id', $company->outlets()->pluck('id'))
            ->whereNotIn('hq_order_lines.product_id', $mappedProducts->map(fn ($k) => (int) $k))
            ->groupBy('products.id', 'products.code', 'products.name')->selectRaw('products.id, products.code, products.name, COUNT(*) as lines')
            ->orderByDesc('lines')->limit(30)->get();
        $mappedMethods = SapMapping::where('company_db', $companyDb)->where('entity_type', 'payment_method')->pluck('entity_key')->all();

        return view('core.sap-mappings.index', [
            'title' => 'Mapping SAP',
            'type' => $type,
            'types' => self::TYPES,
            'mappings' => $mappings,
            'products' => Product::whereIn('id', $mappings->where('entity_type', 'product')->pluck('entity_key'))->get()->keyBy('id'),
            'outlets' => Outlet::orderBy('code')->get()->keyBy('id'),
            'unmappedSold' => $unmappedSold,
            'unmappedMethods' => array_values(array_diff(self::METHODS, $mappedMethods)),
            'companyDb' => $companyDb,
            'company' => $company,
            'companies' => BusinessUnit::whereNotNull('sap_company_db')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', Rule::in(array_keys(self::TYPES))],
            'entity_key' => ['required', 'string', 'max:64'],
            'sap_code' => ['required', 'string', 'max:64'],
            'tax_code' => ['nullable', 'string', 'max:20'],
            'company' => ['required', 'exists:business_units,sap_company_db'],
        ]);
        $company = $this->company($request);
        $key = trim($data['entity_key']);
        $key = match ($data['entity_type']) {
            'product' => (string) (Product::where('code', $key)->value('id') ?? throw ValidationException::withMessages(['entity_key' => "Kode produk {$key} tidak dikenal."])),
            'outlet' => (string) (Outlet::where('code', strtoupper($key))->where('business_unit_id', $company->id)->value('id')
                ?? throw ValidationException::withMessages(['entity_key' => "Outlet {$key} tidak dikenal atau bukan milik {$company->name}."])),
            default => $key,
        };

        $mapping = SapMapping::updateOrCreate(
            ['company_db' => $company->sap_company_db, 'entity_type' => $data['entity_type'], 'entity_key' => $key],
            ['sap_code' => trim($data['sap_code']), 'attributes' => array_filter(['tax_code' => $data['tax_code'] ?? null]) ?: null],
        );
        Audit::record('sap_mapping.saved', $mapping, $request->user()->id, after: $mapping->only(['entity_type', 'entity_key', 'sap_code', 'attributes']));

        return redirect()->route('core.sap-mappings.index', ['type' => $data['entity_type'], 'company' => $company->sap_company_db])->with('ok', 'Mapping disimpan. Posting ulang dokumen yang tertahan di menu Posting SAP.');
    }

    public function destroy(Request $request, SapMapping $mapping): RedirectResponse
    {
        Audit::record('sap_mapping.deleted', $mapping, $request->user()->id, before: $mapping->only(['entity_type', 'entity_key', 'sap_code']));
        $mapping->delete();

        return back()->with('ok', 'Mapping dihapus.');
    }
}
