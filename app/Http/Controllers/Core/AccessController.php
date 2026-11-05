<?php

namespace App\Http\Controllers\Core;

use App\Domain\Access\Permissions;
use App\Domain\Audit\Audit;
use App\Domain\Core\CoreAccess;
use App\Domain\Sync\Publications;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Peran & izin: satu matriks untuk kantor pusat dan semua outlet. Perubahan izin peran outlet
 * dipublikasikan ke setiap Rasentra Lokal sehingga aturan kerja kasir, pelayan dan kepala
 * outlet sama di seluruh grup.
 */
class AccessController extends Controller
{
    public function index(): View
    {
        $this->ensureRoles();

        return view('core.ops.access', [
            'title' => 'Peran & izin',
            'roles' => Role::orderBy('scope')->orderBy('code')->get(),
            'central' => Permissions::CENTRAL,
            'outlet' => Permissions::OUTLET,
        ]);
    }

    public function update(Request $request, Publications $publications): RedirectResponse
    {
        $data = $request->validate(['perm' => ['array'], 'perm.*' => ['array'], 'perm.*.*' => ['string']]);
        $valid = array_keys(Permissions::CENTRAL + Permissions::OUTLET);
        DB::transaction(function () use ($data, $valid, $request, $publications) {
            foreach (Role::where('code', '!=', 'admin')->get() as $role) {
                $new = array_values(array_intersect($data['perm'][$role->code] ?? [], $valid));
                if ($new == $role->permissions) {
                    continue;
                }
                Audit::record('role.permissions_changed', $role, $request->user()->id, before: ['permissions' => $role->permissions], after: ['permissions' => $new]);
                $role->update(['permissions' => $new]);
                $publications->master('role', $role->id);
            }
        });

        return back()->with('ok', 'Izin disimpan dan dikirim ke semua outlet.');
    }

    public function store(Request $request, Publications $publications): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('roles', 'code')],
            'name' => ['required', 'string', 'max:80'],
            'scope' => ['required', Rule::in(['central', 'outlet'])],
        ]);
        $role = Role::create(['code' => strtolower($data['code']), 'name' => $data['name'], 'scope' => $data['scope'], 'permissions' => []]);
        $publications->master('role', $role->id);
        Audit::record('role.created', $role, $request->user()->id, after: $role->only(['code', 'name', 'scope']));

        return back()->with('ok', "Peran {$role->name} dibuat. Centang izinnya lalu simpan.");
    }

    /** Peran bawaan dibuat sekali dari izin di kode agar matriks bisa diubah. */
    public static function ensureRoles(): void
    {
        $outletNames = UserController::OUTLET_ROLES + ['courier' => 'Kurir'];
        foreach (CoreAccess::ROLES as $code => $name) {
            $areas = $code === 'admin' ? ['*'] : array_values(array_filter(array_keys(Permissions::CENTRAL), fn ($a) => CoreAccess::allows(new User(['role' => $code, 'is_active' => true]), $a)));
            Role::firstOrCreate(['code' => $code], ['name' => $name, 'scope' => 'central', 'permissions' => $areas, 'is_system' => true]);
        }
        foreach (Permissions::OUTLET_DEFAULTS as $code => $perms) {
            Role::firstOrCreate(['code' => $code], ['name' => $outletNames[$code] ?? $code, 'scope' => 'outlet', 'permissions' => $perms, 'is_system' => true]);
        }
    }
}
