<?php

namespace App\Domain\Core;

use App\Models\InboxEvent;
use Illuminate\Support\Facades\DB;

/**
 * Rasentra Central: memproyeksikan event stock.adjusted (waste, selisih opname, lepas karantina)
 * ke tabel hq_stock_* untuk laporan opname dan waste di Rasentra Core. Idempoten per dokumen.
 */
final class StockProjector
{
    public function project(InboxEvent $event): void
    {
        if ($event->event_type !== 'stock.adjusted') {
            return;
        }
        $a = $event->payload;

        DB::transaction(function () use ($event, $a) {
            if (DB::table('hq_stock_adjustments')->where('id', $a['id'])->exists()) {
                return;
            }
            DB::table('hq_stock_adjustments')->insert([
                'id' => $a['id'], 'inbox_event_id' => $event->id, 'outlet_id' => $event->outlet_id, 'doc_no' => $a['doc_no'],
                'kind' => $a['kind'], 'reason' => $a['reason'], 'business_date' => $a['business_date'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($a['lines'] as $l) {
                DB::table('hq_stock_adjustment_lines')->insert([
                    'hq_stock_adjustment_id' => $a['id'], 'line_no' => $l['line_no'], 'product_id' => $l['product_id'], 'pack_id' => $l['pack_id'],
                    'quantity' => $l['quantity'], 'uom' => $l['uom'], 'from_state' => $l['from_state'], 'to_state' => $l['to_state'],
                ]);
            }
        });
    }

    public function rebuild(): int
    {
        DB::table('hq_stock_adjustment_lines')->delete();
        DB::table('hq_stock_adjustments')->delete();
        $count = 0;
        InboxEvent::where('event_type', 'stock.adjusted')->orderBy('id')->chunkById(500, function ($events) use (&$count) {
            foreach ($events as $event) {
                $this->project($event);
                $count++;
            }
        });

        return $count;
    }
}
