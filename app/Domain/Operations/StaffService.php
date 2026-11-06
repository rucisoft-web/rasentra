<?php

namespace App\Domain\Operations;

use App\Domain\Audit\Audit;
use App\Domain\Settings\Settings;
use App\Domain\Sync\Outbox;
use App\Models\Attendance;
use App\Models\Outlet;
use App\Models\ShiftTemplate;
use App\Models\StaffRoster;
use App\Models\User;
use App\Support\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shift & staff management. Template shift (Pagi, Siang, Malam) dibuat sekali di Central dan
 * berlaku untuk semua outlet; kepala outlet menyusun roster mingguan dari template itu; staf
 * absen masuk/pulang dengan PIN di perangkat outlet. Absensi dikirim ke Central untuk laporan
 * kehadiran lintas outlet.
 */
final class StaffService
{
    public function __construct(private readonly Settings $settings) {}

    /** Template yang berlaku di outlet ini. */
    public function templates(int $outletId)
    {
        return ShiftTemplate::where('is_active', true)->orderBy('starts_at')->get()
            ->filter(fn ($t) => empty($t->outlet_ids) || in_array($outletId, array_map('intval', $t->outlet_ids), true))->values();
    }

    /** Simpan roster: [user_id => [Y-m-d => shift_template_id|null]]. */
    public function saveRoster(int $outletId, array $grid, User $actor): int
    {
        $valid = $this->templates($outletId)->pluck('id')->all();
        $staff = User::where('outlet_id', $outletId)->pluck('id')->all();
        $count = 0;
        DB::transaction(function () use ($grid, $valid, $staff, $outletId, &$count) {
            foreach ($grid as $userId => $days) {
                if (! in_array((int) $userId, $staff, true)) {
                    continue;
                }
                foreach ($days as $date => $templateId) {
                    $templateId = $templateId === '' || $templateId === null ? null : (int) $templateId;
                    if ($templateId !== null && ! in_array($templateId, $valid, true)) {
                        throw new DomainException('template_invalid', 'Template shift tidak berlaku di outlet ini.', 422);
                    }
                    StaffRoster::updateOrCreate(['user_id' => (int) $userId, 'work_date' => Carbon::parse($date)->toDateString()],
                        ['outlet_id' => $outletId, 'shift_template_id' => $templateId]);
                    $count++;
                }
            }
        });
        Audit::record('roster.saved', 'roster:'.$outletId, $actor->id, after: ['cells' => $count], outletId: $outletId);

        return $count;
    }

    /** Absen masuk bila belum ada absensi terbuka hari ini, selain itu absen pulang. */
    public function clock(User $user, int $outletId): Attendance
    {
        if ((int) $user->outlet_id !== $outletId || ! $user->is_active) {
            throw new DomainException('staff_unknown', 'Petugas bukan staf outlet ini.', 403);
        }
        $outlet = Outlet::findOrFail($outletId);
        $now = now();
        $local = $now->copy()->setTimezone($outlet->timezone);
        $workDate = $local->toDateString();

        return DB::transaction(function () use ($user, $outletId, $outlet, $now, $workDate) {
            $open = Attendance::where('user_id', $user->id)->whereNull('clock_out_at')->lockForUpdate()->latest('clock_in_at')->first();
            if ($open) {
                $open->update([
                    'clock_out_at' => $now,
                    'worked_minutes' => (int) $open->clock_in_at->diffInMinutes($now),
                    'version' => $open->version + 1,
                ]);
                $this->emit($open, 'attendance.clock_out');

                return $open;
            }

            $roster = StaffRoster::where('user_id', $user->id)->whereDate('work_date', $workDate)->first();
            $template = $roster?->shift_template_id ? ShiftTemplate::find($roster->shift_template_id) : null;
            $start = $template ? Carbon::parse($workDate.' '.$template->starts_at, $outlet->timezone)->utc() : null;
            $grace = (int) $this->settings->get('staff.late_grace_minutes', $outletId);
            $late = $start && $now->greaterThan($start->copy()->addMinutes($grace)) ? (int) $start->diffInMinutes($now) : 0;

            $attendance = Attendance::create([
                'id' => (string) Str::uuid7(),
                'outlet_id' => $outletId,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'work_date' => $workDate,
                'shift_code' => $template?->code,
                'scheduled_start' => $start,
                'clock_in_at' => $now,
                'late_minutes' => $late,
                'version' => 1,
            ]);
            $this->emit($attendance, 'attendance.clock_in');

            return $attendance;
        });
    }

    private function emit(Attendance $a, string $event): void
    {
        Outbox::record($a->outlet_id, 'attendance', $a->id, $a->version, $event, $a->fresh()->toArray(), substr((string) $a->work_date, 0, 10));
    }
}
