<?php

namespace App\Domain\Sync;

use App\Models\DailyReconciliation;
use App\Models\InboxEvent;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rasentra Central: mencocokkan ringkasan tutup hari dari Rasentra Lokal dengan penjualan
 * yang sudah diterima Central. "waiting" berarti masih ada struk yang belum sampai (mis.
 * outlet sempat offline); statusnya diperiksa ulang setiap kali struk outlet itu masuk.
 */
final class DayReconciler
{
    public function handle(InboxEvent $event): void
    {
        if ($event->event_type === 'business_day.closed') {
            $this->record($event);
        } elseif ($event->event_type === 'order.finalized') {
            $date = $event->payload['business_date'] ?? null;
            $open = $date && DailyReconciliation::where('outlet_id', $event->outlet_id)->whereDate('business_date', $date)->where('status', '!=', 'matched')->exists();
            if ($open) {
                $this->check($event->outlet_id, $date);
            }
        }
    }

    public function record(InboxEvent $event): DailyReconciliation
    {
        $p = $event->payload;
        $existing = DailyReconciliation::where('outlet_id', $event->outlet_id)->whereDate('business_date', $p['business_date'])->first();
        if ($existing && $existing->closing_version > $event->entity_version) {
            return $existing; // versi tutup hari yang lebih lama datang terlambat
        }

        $row = $existing ?? new DailyReconciliation(['outlet_id' => $event->outlet_id, 'business_date' => $p['business_date']]);
        $row->fill([
            'closing_version' => $event->entity_version,
            'closed_at' => Carbon::parse($p['closed_at']),
            'local_orders' => $p['orders'],
            'local_net' => $p['net_amount'],
            'cash_difference' => $p['cash_difference'],
            'shifts' => count($p['shifts']),
            'open_shifts' => $p['open_shifts'],
            'local_summary' => $p,
        ])->save();

        return $this->check($event->outlet_id, $p['business_date']);
    }

    public function check(int $outletId, string $businessDate): DailyReconciliation
    {
        $row = DailyReconciliation::where('outlet_id', $outletId)->whereDate('business_date', $businessDate)->firstOrFail();
        $central = DB::table('hq_orders')->where('outlet_id', $outletId)->whereDate('business_date', $businessDate)->get(['doc_no', 'net_amount']);
        $localDocs = $row->local_summary['doc_nos'] ?? [];
        $centralDocs = $central->pluck('doc_no')->filter()->all();

        $missing = array_values(array_diff($localDocs, $centralDocs));
        $extra = array_values(array_diff($centralDocs, $localDocs));
        $centralNet = Decimal::sum($central->pluck('net_amount'));
        $same = $central->count() === (int) $row->local_orders && $centralNet->isEqualTo(Decimal::of((string) $row->local_net));

        $row->update([
            'central_orders' => $central->count(),
            'central_net' => Decimal::money($centralNet),
            'missing_doc_nos' => $missing,
            'extra_doc_nos' => $extra,
            'status' => $missing !== [] ? 'waiting' : ($extra === [] && $same ? 'matched' : 'mismatch'),
            'checked_at' => now(),
        ]);

        return $row;
    }
}
