<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Region;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Master outlet: fasilitas (retail, resto, cooking fee), kode gudang SAP dan lokasi stok. */
class OutletController extends Controller
{
    public const LOCATION_TYPES = ['sales' => 'Penjualan', 'storage' => 'Gudang', 'kitchen' => 'Dapur', 'quarantine' => 'Karantina', 'transit' => 'Transit'];

    public function index(): View
    {
        $outlets = Outlet::with('region')->withCount(['locations'])->orderBy('code')->get();
        $users = User::whereNotNull('outlet_id')->where('is_active', true)->groupBy('outlet_id')->selectRaw('outlet_id, COUNT(*) as n')->pluck('n', 'outlet_id');
        $devices = Device::where('is_active', true)->groupBy('outlet_id')->selectRaw('outlet_id, COUNT(*) as n')->pluck('n', 'outlet_id');

        return view('core.outlets.index', ['title' => 'Outlet & lokasi', 'outlets' => $outlets, 'users' => $users, 'devices' => $devices]);
    }

    public function edit(Outlet $outlet): View
    {
        return view('core.outlets.edit', [
            'title' => "Outlet {$outlet->code}",
            'outlet' => $outlet->load('locations'),
            'regions' => Region::orderBy('name')->get(),
            'types' => self::LOCATION_TYPES,
        ]);
    }

    public function update(Request $request, Outlet $outlet): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'region_id' => ['required', 'exists:regions,id'],
            'sap_warehouse_code' => ['nullable', 'string', 'max:20'],
        ]);
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
