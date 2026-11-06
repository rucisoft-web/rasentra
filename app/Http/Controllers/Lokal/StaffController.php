<?php

namespace App\Http\Controllers\Lokal;

use App\Domain\Operations\StaffService;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Outlet;
use App\Models\StaffRoster;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Roster mingguan dan kehadiran hari ini, untuk kepala outlet. */
class StaffController extends Controller
{
    use OutletScope;

    public function __construct(private readonly StaffService $service) {}

    public function index(Request $request): View
    {
        $outletId = $this->outletId($request);
        $outlet = Outlet::findOrFail($outletId);
        $monday = Carbon::parse($request->query('week', now($outlet->timezone)->toDateString()))->startOfWeek();
        $days = collect(range(0, 6))->map(fn ($i) => $monday->copy()->addDays($i));
        $staff = User::where('outlet_id', $outletId)->where('is_active', true)->orderBy('role')->orderBy('name')->get();
        $roster = StaffRoster::where('outlet_id', $outletId)->whereBetween('work_date', [$days->first()->toDateString(), $days->last()->toDateString()])->get()
            ->mapWithKeys(fn ($r) => [$r->user_id.'@'.substr((string) $r->work_date, 0, 10) => $r->shift_template_id]);

        return view('lokal.ops.staff', [
            'title' => 'Roster & kehadiran staf',
            'monday' => $monday,
            'days' => $days,
            'staff' => $staff,
            'roster' => $roster,
            'templates' => $this->service->templates($outletId),
            'attendance' => Attendance::where('outlet_id', $outletId)->whereDate('work_date', now($outlet->timezone)->toDateString())->orderBy('clock_in_at')->get(),
            'timezone' => $outlet->timezone,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate(['roster' => ['array'], 'week' => ['nullable', 'date']]);
        $n = $this->service->saveRoster($this->outletId($request), $data['roster'] ?? [], $request->user());

        return redirect()->route('lokal.staff', ['week' => $data['week'] ?? null])->with('ok', "Roster disimpan ({$n} sel).");
    }
}
