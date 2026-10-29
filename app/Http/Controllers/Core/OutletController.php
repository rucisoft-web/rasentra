<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Device;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Region;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Master perusahaan (CV dan instance SAP) dan outlet: nama usaha retail/resto, fasilitas (retail, resto, cooking fee), kode gudang SAP dan lokasi stok. */
class OutletController extends Controller
{
    public const LOCATION_TYPES = ['sales' => 'Penjualan', 'storage' => 'Gudang', 'kitchen' => 'Dapur', 'quarantine' => 'Karantina', 'transit' => 'Transit'];

    public function index(): View
    {
        $outlets = Outlet::with('region', 'businessUnit')->withCount(['locations'])->orderBy('code')->get();
        $users = User::whereNotNull('outlet_id')->where('is_active', true)->groupBy('outlet_id')->selectRaw('outlet_id, COUNT(*) as n')->pluck('n', 'outlet_id');
        $devices = Device::where('is_active', true)->groupBy('outlet_id')->selectRaw('outlet_id, COUNT(*) as n')->pluck('n', 'outlet_id');

        return view('core.outlets.index', [
            'title' => 'Perusahaan, outlet & lokasi', 'outlets' => $outlets, 'users' => $users, 'devices' => $devices,
            'companies' => BusinessUnit::withCount('outlets')->orderBy('id')->get(),
        ]);
    }

    /** Perusahaan (CV) di grup dan database SAP B1 tujuan posting-nya. */
    public function updateCompany(Request $request, BusinessUnit $company): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sap_company_db' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('business_units')->ignore($company->id)],
        ]);
        $before = $company->only(array_keys($data));
        $company->update($data + ['legal_name' => $data['name']]);
        Audit::record('company.updated', $company, $request->user()->id, before: $before, after: $company->only(array_keys($data)));

        return back()->with('ok', "Data {$company->name} disimpan. Pastikan kredensial Service Layer untuk {$company->sap_company_db} sudah diisi di server Central.");
    }

    public function edit(Outlet $outlet): View
    {
        return view('core.outlets.edit', [
            'title' => "Outlet {$outlet->code}",
            'outlet' => $outlet->load('locations'),
            'regions' => Region::with('businessUnit')->orderBy('name')->get(),
            'types' => self::LOCATION_TYPES,
        ]);
    }

    public function update(Request $request, Outlet $outlet): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'retail_name' => ['nullable', 'string', 'max:120'],
            'restaurant_name' => ['nullable', 'string', 'max:120'],
            'timezone' => ['required', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'region_id' => ['required', 'exists:regions,id'],
            'sap_warehouse_code' => ['nullable', 'string', 'max:20'],
        ]);
        // Perusahaan mengikuti wilayah; perpindahan wilayah berarti posting SAP pindah instance.
        $data['business_unit_id'] = Region::whereKey($data['region_id'])->value('business_unit_id');
        foreach (['has_retail', 'has_restaurant', 'has_cooking_fee', 'is_active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $before = $outlet->only(array_keys($data));
        $outlet->update($data);
        Audit::record('outlet.updated', $outlet, $request->user()->id, before: $before, after: $outlet->only(array_keys($data)), outletId: $outlet->id);

        return redirect()->route('core.outlets.edit', $outlet)->with('ok', 'Data outlet disimpan.');
    }

    public function addLocation(Request $request, Outlet $outlet): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('locations')->where('outlet_id', $outlet->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(self::LOCATION_TYPES))],
        ]);
        $location = $outlet->locations()->create(['code' => strtoupper($data['code'])] + $data);
        Audit::record('location.created', $location, $request->user()->id, after: $location->only(['code', 'name', 'type']), outletId: $outlet->id);

        return back()->with('ok', "Lokasi {$location->code} ditambahkan. QR lokasi siap dicetak untuk Rasentra Inventory.");
    }

    public function toggleLocation(Request $request, Location $location): RedirectResponse
    {
        $location->update(['is_active' => ! $location->is_active]);
        Audit::record($location->is_active ? 'location.activated' : 'location.deactivated', $location, $request->user()->id, outletId: $location->outlet_id);

        return back()->with('ok', "Lokasi {$location->code} ".($location->is_active ? 'diaktifkan.' : 'dinonaktifkan.'));
    }
}
