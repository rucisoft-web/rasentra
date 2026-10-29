<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Core\CoreAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Login Rasentra Core dengan email dan kata sandi, khusus peran kantor pusat. */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (CoreAccess::isCoreUser(Auth::user())) {
            return redirect()->route('core.dashboard');
        }

        return view('core.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->first();

        if (! CoreAccess::isCoreUser($user) || ! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true])) {
            return back()->withErrors(['email' => 'Email atau kata sandi salah, atau akun bukan akun kantor pusat.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        Audit::record('core.login', $user, $user->id);

        return redirect()->intended(route('core.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('core.login');
    }
}
