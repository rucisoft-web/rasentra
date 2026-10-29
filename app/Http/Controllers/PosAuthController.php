<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Login layar sentuh: pilih nama petugas, ketik PIN di keypad. Akun tetap individu;
 * PIN hanya mempercepat pergantian petugas di perangkat outlet.
 */
class PosAuthController extends Controller
{
    public function show(): View
    {
        $outlet = Outlet::where('code', config('rasentra.outlet_code'))->first() ?? Outlet::orderBy('id')->first();
        $users = $outlet
            ? User::where('outlet_id', $outlet->id)->where('is_active', true)->whereNotNull('pin_hash')->orderBy('name')->get(['id', 'name', 'role'])
            : collect();

        return view('pos.login', ['outlet' => $outlet, 'users' => $users]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'pin' => ['required', 'digits_between:4,8']]);
        $user = User::where('id', $data['user_id'])->where('is_active', true)->first();

        if (! $user || ! $user->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            return back()->withErrors(['pin' => 'PIN salah. Coba lagi.'])->withInput(['user_id' => $data['user_id']]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Petugas dapur ke Rasentra Kitchen, pelayan ke Mobile Terminal, petugas gudang ke
        // Rasentra Inventory, lainnya ke Rasentra POS.
        $home = match ($user->role) {
            'chef', 'kitchen' => route('kitchen'),
            'waiter' => route('mobile'),
            'storekeeper' => route('inventory'),
            default => route('pos'),
        };

        return redirect()->intended($home);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('pos.login');
    }
}
