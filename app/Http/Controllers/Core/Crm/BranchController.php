<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Crm\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rasentra CRM: perusahaan (CV) dan cabang. Outlet Rasentra masuk otomatis; CV grup yang masih
 * memakai Accurate Online + Rene POS/Raptor ditambahkan di sini agar membernya bisa diimpor,
 * disinkron dari Accurate dan dikirimi WhatsApp, tanpa ikut ke POS/sinkronisasi/SAP Rasentra.
 */
class BranchController extends Controller
{
    public function index(): View
    {
        Branch::syncFromOutlets();

        return view('core.crm.branches', [
            'title' => 'Perusahaan & cabang CRM',
            'companies' => BusinessUnit::orderBy('code')->get(),
            'branches' => Branch::with('businessUnit')->withCount('memberships')->orderBy('business_unit_id')->orderBy('code')->get(),
        ]);
    }

    public function storeCompany(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:20', 'unique:business_units,code'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        $bu = BusinessUnit::create(['code' => strtoupper($data['code']), 'name' => $data['name']]);
        Audit::record('crm.company.created', $bu, $request->user()->id, after: $data);

        return back()->with('ok', "Perusahaan {$bu->name} ditambahkan. Hubungkan Accurate-nya di menu Accurate Online.");
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_unit_id' => ['required', 'exists:business_units,id'],
            'code' => ['required', 'alpha_dash', 'max:20', 'unique:crm_branches,code'],
            'name' => ['required', 'string', 'max:120'],
            'pos_system' => ['required', Rule::in(array_keys(Branch::POS_SYSTEMS))],
            'city' => ['nullable', 'string', 'max:80'],
        ]);
        if ($data['pos_system'] === 'rasentra') {
            return back()->withInput()->withErrors(['pos_system' => 'Outlet Rasentra dibuat di Perusahaan & outlet Rasentra Core; cabangnya muncul di sini otomatis.']);
        }
        $branch = Branch::create(['code' => strtoupper($data['code'])] + $data);
        Audit::record('crm.branch.created', $branch, $request->user()->id, after: $data);

        return back()->with('ok', "Cabang {$branch->code} ditambahkan.");
    }

    public function toggle(Request $request, Branch $branch): RedirectResponse
    {
        if ($branch->outlet_id) {
            return back()->with('error', 'Status outlet Rasentra diatur di Rasentra Core.');
        }
        $branch->update(['is_active' => ! $branch->is_active]);

        return back()->with('ok', "Cabang {$branch->code} ".($branch->is_active ? 'diaktifkan' : 'dinonaktifkan').'.');
    }
}
