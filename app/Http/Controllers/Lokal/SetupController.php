<?php

namespace App\Http\Controllers\Lokal;

use App\Domain\Audit\Audit;
use App\Domain\Lokal\LokalSettings;
use App\Domain\Sync\ChangePuller;
use App\Domain\Sync\HeartbeatSender;
use App\Domain\Sync\PublicationApplier;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureLokalAccess;
use App\Models\Outlet;
use App\Models\PriceVersion;
use App\Models\Product;
use App\Models\PromotionVersion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Pemasangan Rasentra Lokal: hubungkan server outlet ke Rasentra Central dengan token server
 * yang dibuat admin di Rasentra Core, lalu ambil snapshot master, harga, promo dan resep.
 * Sebelum ada petugas di server ini, halaman terbuka tanpa login; token Central menjadi
 * buktinya. Sesudahnya hanya kepala outlet yang dapat mengubah pairing.
 */
class SetupController extends Controller
{
    public function show(Request $request, PublicationApplier $applier): View
    {
        $this->authorizeSetup($request);
        $settings = LokalSettings::get();

        return view('lokal.setup', [
            'title' => 'Pemasangan',
            'fresh' => self::isFresh(),
            'url' => config('rasentra.central.url'),
            'hasToken' => ! empty(config('rasentra.central.token')),
            'outletCode' => config('rasentra.outlet_code'),
            'sourceId' => config('rasentra.source_id'),
            'pairedAt' => $settings['paired_at'] ?? null,
            'outlet' => Outlet::where('code', config('rasentra.outlet_code'))->first(),
            'cursor' => $applier->cursor(),
            'counts' => ['produk' => Product::count(), 'petugas' => User::whereNotNull('outlet_id')->count(), 'versi harga' => PriceVersion::count(), 'promo' => PromotionVersion::count()],
        ]);
    }

    public function store(Request $request, HeartbeatSender $heartbeat): RedirectResponse
    {
        $this->authorizeSetup($request);
        $data = $request->validate([
            'central_url' => ['required', 'url:http,https', 'max:255'],
            'token' => [empty(config('rasentra.central.token')) ? 'required' : 'nullable', 'string', 'min:20', 'max:200'],
        ]);
        $token = $data['token'] ?: config('rasentra.central.token');
        $previous = [config('rasentra.central.url'), config('rasentra.central.token')];
        config(['rasentra.central.url' => rtrim($data['central_url'], '/'), 'rasentra.central.token' => $token]);

        $result = $heartbeat->send();
        $device = $result['device'] ?? null;
        $error = match (true) {
            ! $result['ok'] => 'Central tidak dapat dihubungi atau token ditolak: '.$result['error'],
            ($device['app'] ?? null) !== 'outlet_server' => 'Token ini bukan token server outlet. Buat token dengan jenis "outlet_server" di Rasentra Core → Perangkat & token.',
            config('rasentra.outlet_code') && ! self::isFresh() && $device['outlet_code'] !== config('rasentra.outlet_code') => "Token ini milik outlet {$device['outlet_code']}, sedangkan server ini melayani ".config('rasentra.outlet_code').'.',
            default => null,
        };
        if ($error !== null) {
            config(['rasentra.central.url' => $previous[0], 'rasentra.central.token' => $previous[1]]);

            return back()->withInput($request->except('token'))->with('error', $error);
        }

        LokalSettings::save([
            'central_url' => rtrim($data['central_url'], '/'),
            'token' => $token,
            'outlet_code' => $device['outlet_code'],
            'source_id' => $device['code'],
            'paired_at' => now()->toIso8601ZuluString(),
        ]);
        Audit::record('lokal.paired', "outlet:{$device['outlet_code']}", $request->user()?->id, after: ['central_url' => $data['central_url'], 'device' => $device['code']]);

        return redirect()->route('lokal.setup')->with('ok', "Terhubung ke Central sebagai {$device['code']} (outlet {$device['outlet_code']}). Selisih jam {$result['clock_skew_seconds']} detik.");
    }

    public function bootstrap(Request $request, ChangePuller $puller): RedirectResponse
    {
        $this->authorizeSetup($request);
        abort_unless(LokalSettings::isPaired(), 409, 'Hubungkan ke Central dulu.');
        try {
            $result = $puller->bootstrap();
        } catch (Throwable $e) {
            return back()->with('error', 'Snapshot gagal diambil: '.$e->getMessage());
        }
        $error = $result['snapshot']['error'] ?? $result['pull']['error'] ?? null;
        Audit::record('lokal.bootstrapped', 'outlet:'.config('rasentra.outlet_code'), $request->user()?->id, after: $result);

        return back()->with($error ? 'error' : 'ok', $error
            ? "Snapshot berhenti: {$error}"
            : "Snapshot diterapkan: {$result['snapshot']['applied']} data, cursor {$result['pull']['cursor']}. Petugas outlet sekarang bisa masuk dengan PIN.");
    }

    /** Server baru: belum ada petugas outlet yang bisa login di sini. */
    public static function isFresh(): bool
    {
        return ! User::whereNotNull('outlet_id')->whereNotNull('pin_hash')->where('is_active', true)->exists();
    }

    private function authorizeSetup(Request $request): void
    {
        if (self::isFresh()) {
            return;
        }
        if ($request->user() === null) {
            abort(redirect()->guest(route('pos.login')));
        }
        abort_unless(EnsureLokalAccess::allows($request->user()), 403, 'Pemasangan hanya untuk kepala outlet.');
    }
}
