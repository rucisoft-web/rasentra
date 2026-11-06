<?php

namespace App\Domain\Operations;

use App\Domain\Membership\MembershipService;
use App\Domain\Sync\Publications;
use App\Models\Attendance;
use App\Models\Delivery;
use App\Models\InboxEvent;
use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra Central: memproyeksikan event operasional outlet (member, poin, pengiriman,
 * absensi) agar Rasentra Core punya gambaran seluruh grup. Idempoten: baris memakai UUID
 * dari outlet dan versi lama tidak menimpa versi baru.
 */
final class OperationsProjector
{
    public function __construct(
        private readonly MembershipService $members,
        private readonly Publications $publications,
    ) {}

    public function project(InboxEvent $event): void
    {
        $p = $event->payload;
        match ($event->event_type) {
            'member.enrolled' => $this->enrolled($p),
            'member.points' => $this->points($p),
            'delivery.updated' => $this->mirror(Delivery::class, $p),
            'attendance.clock_in', 'attendance.clock_out' => $this->mirror(Attendance::class, $p),
            default => null,
        };
    }

    private function enrolled(array $p): void
    {
        $member = Member::where('phone', $p['phone'])->first();
        $attrs = array_filter([
            'name' => $p['name'], 'email' => $p['email'] ?? null, 'birth_date' => isset($p['birth_date']) ? substr($p['birth_date'], 0, 10) : null,
            'home_outlet_id' => $p['home_outlet_id'] ?? null, 'member_tier_id' => $p['member_tier_id'] ?? null,
            'member_since' => isset($p['member_since']) ? substr($p['member_since'], 0, 10) : null,
        ], fn ($v) => $v !== null) + ['is_member' => true];
        if ($member) {
            // Pelanggan yang sudah dikenal CRM: kode member yang sudah ada dipertahankan.
            $member->update($attrs + ['code' => $member->code ?? $p['code']]);
        } else {
            $id = (int) Member::where('id', '<', MembershipService::PROVISIONAL_BASE)->max('id') + 1;
            $member = Member::create($attrs + ['id' => $id, 'phone' => $p['phone'], 'code' => $p['code']]);
        }
        $this->publications->master('member', $member->id);
    }

    private function points(array $p): void
    {
        $member = $this->members->applyAtCentral($p['entries'] ?? [], $p['member_id'] ?? null, $p['phone'] ?? null);
        if ($member) {
            $this->publications->master('member', $member->id);
        }
    }

    /** @param class-string<Model> $model */
    private function mirror(string $model, array $p): void
    {
        $existing = $model::find($p['id']);
        if ($existing && (int) $existing->version >= (int) ($p['version'] ?? 1)) {
            return;
        }
        $table = (new $model)->getTable();
        $columns = array_flip(Schema::getColumnListing($table));
        $row = [];
        foreach (array_intersect_key($p, $columns) as $k => $v) {
            $row[$k] = match (true) {
                in_array($k, ['business_date', 'work_date'], true) && is_string($v) => substr($v, 0, 10),
                is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v) === 1 => Carbon::parse($v)->utc()->format('Y-m-d H:i:s'),
                default => $v,
            };
        }
        $model::query()->toBase()->updateOrInsert(['id' => $p['id']], array_diff_key($row, ['id' => true]));
    }
}
