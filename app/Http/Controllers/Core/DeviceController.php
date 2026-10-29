<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Outlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Perangkat dan server outlet. Token hanya ditampilkan sekali saat dibuat; yang disimpan
 * hanya SHA-256-nya. Token server Rasentra Lokal dipakai sebagai RASENTRA_CENTRAL_TOKEN,
 * dan kode perangkatnya harus sama dengan RASENTRA_SOURCE_ID di server tersebut.
 */
class DeviceController extends Controller
{
    public const APPS = [
        'outlet_server' => 'Server Rasentra Lokal',
        'pos' => 'Rasentra POS',
        'terminal' => 'Rasentra Mobile Terminal',
        'kitchen' => 'Rasentra Kitchen',
        'inventory' => 'Rasentra Inventory',
        'kiosk' => 'Kiosk / layar antrean',
    ];

    public function index(Request $request): View
    {
        $devices = Device::query()
            ->when($request->query('outlet'), fn ($q, $o) => $q->where('outlet_id', $o))
            ->orderBy('outlet_id')->orderBy('app')->orderBy('code')->get();

        return view('core.devices.index', [
            'title' => 'Perangkat & token',
            'devices' => $devices,
            'outlets' => Outlet::orderBy('code')->get()->keyBy('id'),
            'apps' => self::APPS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'app' => ['required', Rule::in(array_keys(self::APPS))],
            'code' => ['required', 'alpha_dash', 'max:40', 'unique:devices,code'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        $token = $this->newToken();
        $device = Device::create(['code' => strtoupper($data['code']), 'token_hash' => hash('sha256', $token), 'is_active' => true] + $data);
        Audit::record('device.created', $device, $request->user()->id, after: $device->only(['code', 'app', 'outlet_id']), outletId: $device->outlet_id);

        return back()->with('token', ['device' => $device->code, 'token' => $token]);
    }

    public function rotate(Request $request, Device $device): RedirectResponse
    {
        $token = $this->newToken();
        $device->update(['token_hash' => hash('sha256', $token), 'is_active' => true]);
        Audit::record('device.token_rotated', $device, $request->user()->id, outletId: $device->outlet_id);

        return back()->with('token', ['device' => $device->code, 'token' => $token]);
    }

    public function revoke(Request $request, Device $device): RedirectResponse
    {
        $device->update(['token_hash' => null, 'is_active' => false]);
        Audit::record('device.revoked', $device, $request->user()->id, outletId: $device->outlet_id);

        return back()->with('ok', "Token {$device->code} dicabut. Perangkat tidak bisa lagi tersambung ke Rasentra Central.");
    }

    private function newToken(): string
    {
        return 'rst_'.Str::random(48);
    }
}
