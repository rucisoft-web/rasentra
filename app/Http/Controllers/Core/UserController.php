<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Core\CoreAccess;
use App\Domain\Sync\Publications;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pengguna outlet (login PIN) dan pengguna kantor pusat (login email). PIN dan kata sandi
 * hanya disimpan sebagai hash; perubahan peran, limit dan status tercatat di audit.
 */
class UserController extends Controller
{
    public const OUTLET_ROLES = [
        'cashier' => 'Kasir',
        'waiter' => 'Pelayan',
        'outlet_head' => 'Kepala outlet',
        'chef' => 'Chef / cook',
        'kitchen' => 'Petugas dapur/bar',
        'storekeeper' => 'Petugas gudang',
    ];

    public static function roles(): array
    {
        return self::OUTLET_ROLES + CoreAccess::ROLES;
    }

    public function index(Request $request): View
    {
        $users = User::with('outlet')
            ->when($request->query('outlet'), fn ($q, $o) => $o === 'hq' ? $q->whereNull('outlet_id') : $q->where('outlet_id', $o))
            ->when($request->query('role'), fn ($q, $r) => $q->where('role', $r))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->orderBy('outlet_id')->orderBy('name')->paginate(50)->withQueryString();

        return view('core.users.index', ['title' => 'Pengguna', 'users' => $users, 'outlets' => Outlet::orderBy('code')->get(), 'roles' => self::roles()]);
    }

    public function create(): View
    {
        return view('core.users.form', ['title' => 'Pengguna baru', 'user' => new User(['is_active' => true]), 'outlets' => Outlet::orderBy('code')->get(), 'roles' => self::roles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $user = User::create($data);
        Audit::record('user.created', $user, $request->user()->id, after: $this->snapshot($user), outletId: $user->outlet_id);
        app(Publications::class)->master('user', $user->id);

        return redirect()->route('core.users.edit', $user)->with('ok', "Pengguna {$user->name} dibuat.");
    }

    public function edit(User $user): View
    {
        return view('core.users.form', ['title' => $user->name, 'user' => $user, 'outlets' => Outlet::orderBy('code')->get(), 'roles' => self::roles()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && (! $data['is_active'] || $data['role'] !== $user->role)) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan atau mengubah peran akun sendiri.');
        }
        $before = $this->snapshot($user);
        $oldOutlet = $user->outlet_id;
        $user->update($data);
        // Petugas yang pindah outlet ikut dikirim ke outlet lama agar datanya di sana diperbarui.
        app(Publications::class)->master('user', $user->id, $oldOutlet && $oldOutlet !== $user->outlet_id ? [$oldOutlet] : []);
        Audit::record('user.updated', $user, $request->user()->id, before: $before, after: $this->snapshot($user->fresh()), outletId: $user->outlet_id);

        return back()->with('ok', 'Perubahan disimpan.');
    }

    private function validated(Request $request, ?User $user): array
    {
        $isHq = array_key_exists((string) $request->input('role'), CoreAccess::ROLES);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_keys(self::roles()))],
            'outlet_id' => [$isHq ? 'nullable' : 'required', 'nullable', 'exists:outlets,id'],
            'pin' => [$user === null && ! $isHq ? 'required' : 'nullable', 'digits_between:4,8'],
            'password' => [$user === null && $isHq ? 'required' : 'nullable', 'string', 'min:10'],
            'approval_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        $out = [
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'role' => $data['role'],
            'outlet_id' => $isHq ? null : $data['outlet_id'],
            'approval_limit' => in_array($data['role'], ['outlet_head', 'admin'], true) ? ($data['approval_limit'] ?? 0) : 0,
            'is_active' => $request->boolean('is_active'),
        ];
        if (! empty($data['pin'])) {
            $out['pin_hash'] = Hash::make($data['pin']);
        }
        if ($isHq) {
            $out['pin_hash'] = null; // akun pusat tidak memakai PIN outlet
        }
        if (! empty($data['password'])) {
            $out['password'] = $data['password'];
        } elseif ($user === null) {
            $out['password'] = Str::random(40); // petugas outlet login dengan PIN, bukan kata sandi
        }

        return $out;
    }

    private function snapshot(User $user): array
    {
        return $user->only(['name', 'email', 'role', 'outlet_id', 'approval_limit', 'is_active']);
    }
}
