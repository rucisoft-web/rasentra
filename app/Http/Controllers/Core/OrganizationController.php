<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\DiningTable;
use App\Models\Region;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Struktur organisasi grup: Indoguna Group → perusahaan (CV, masing-masing dengan pembukuan
 * sendiri) → wilayah → outlet (satu Rasentra Lokal; usaha resto dan/atau meatshop) → lokasi,
 * station dan staf per peran.
 */
class OrganizationController extends Controller
{
    public function index(): View
    {
        $companies = BusinessUnit::with(['outlets.locations', 'outlets.region'])->orderBy('code')->get();
        $staff = User::whereNotNull('outlet_id')->where('is_active', true)->selectRaw('outlet_id, role, count(*) as n')->groupBy('outlet_id', 'role')->get()->groupBy('outlet_id');

        return view('core.ops.organization', [
            'title' => 'Struktur organisasi',
            'companies' => $companies,
            'regions' => Region::orderBy('code')->get()->groupBy('business_unit_id'),
            'staff' => $staff,
            'tables' => DiningTable::where('is_active', true)->selectRaw('outlet_id, count(*) as n')->groupBy('outlet_id')->pluck('n', 'outlet_id'),
            'hq' => User::whereNull('outlet_id')->where('is_active', true)->orderBy('role')->get(['name', 'role']),
        ]);
    }

    public function storeRegion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_unit_id' => ['required', 'exists:business_units,id'],
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('regions', 'code')],
            'name' => ['required', 'string', 'max:80'],
        ]);
        $region = Region::create(['code' => strtoupper($data['code'])] + $data);
        Audit::record('region.created', $region, $request->user()->id, after: $region->only(['code', 'name', 'business_unit_id']));

        return back()->with('ok', "Wilayah {$region->name} ditambahkan.");
    }
}
