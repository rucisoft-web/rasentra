<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Mode demo: tamu memilih aplikasi dan masuk dengan akun data contoh tanpa kata sandi atau PIN.
 * Aktif hanya bila RASENTRA_DEMO=true.
 */
class DemoController extends Controller
{
    public function index(): View
    {
        $this->ensureEnabled();

        return view('demo.index', ['accounts' => config('rasentra.demo.accounts'), 'resetAt' => config('rasentra.demo.reset_at')]);
    }

    public function enter(Request $request, string $key): RedirectResponse
    {
        $this->ensureEnabled();
        $account = config("rasentra.demo.accounts.{$key}");
        abort_if($account === null, 404);
        $user = User::where('email', $account['email'])->where('is_active', true)->first();
        if ($user === null) {
            return redirect()->route('demo')->with('error', 'Data contoh belum dimuat. Jalankan php artisan rasentra:demo-reset di server.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('demo', $key);

        return redirect($account['home']);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('rasentra.demo.enabled'), 404);
    }
}
