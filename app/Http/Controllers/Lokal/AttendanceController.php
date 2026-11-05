<?php

namespace App\Http\Controllers\Lokal;

use App\Domain\Operations\StaffService;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** Layar absen staf di perangkat outlet: sentuh nama, ketik PIN, masuk atau pulang. */
class AttendanceController extends Controller
{
    public function __construct(private readonly StaffService $service) {}

    public function show(): View
    {
        $outlet = Outlet::where('code', config('rasentra.outlet_code'))->first();

        return view('lokal.ops.attendance', [
            'title' => 'Absen staf',
            'outlet' => $outlet,
            'staff' => $outlet ? User::where('outlet_id', $outlet->id)->where('is_active', true)->whereNotNull('pin_hash')->orderBy('name')->get(['id', 'name', 'role']) : collect(),
        ]);
    }

    public function clock(Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'pin' => ['required', 'digits_between:4,8']]);
        $outlet = Outlet::where('code', config('rasentra.outlet_code'))->firstOrFail();
        $user = User::where('outlet_id', $outlet->id)->find($data['user_id']);
        if (! $user || ! $user->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            return back()->withErrors(['pin' => 'PIN salah. Coba lagi.']);
        }
        $a = $this->service->clock($user, $outlet->id);
        $time = ($a->clock_out_at ?? $a->clock_in_at)->setTimezone($outlet->timezone)->format('H:i');
        $msg = $a->clock_out_at
            ? "{$user->name} absen pulang pukul {$time}. Durasi kerja ".intdiv($a->worked_minutes, 60).' jam '.($a->worked_minutes % 60).' menit.'
            : "{$user->name} absen masuk pukul {$time}".($a->late_minutes ? ", terlambat {$a->late_minutes} menit." : '.');

        return back()->with('ok', $msg);
    }
}
