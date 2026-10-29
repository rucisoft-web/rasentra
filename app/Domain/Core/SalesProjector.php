<?php

namespace App\Domain\Core;

use App\Models\InboxEvent;
use Illuminate\Support\Facades\DB;

/**
 * Rasentra Central: memproyeksikan event order.finalized dari inbox ke tabel hq_* untuk
 * laporan Rasentra Core. Idempoten per inbox event, jadi aman dijalankan ulang.
 */
final class SalesProjector
{
    public function project(InboxEvent $event): void
    {
        if ($event->event_type !== 'order.finalized') {
            return;
        }
        $o = $event->payload;

        DB::transaction(function () use ($event, $o) {
            if (DB::table('hq_orders')->where('id', $o['id'])->exists()) {
                return;
            }
            DB::table('hq_orders')->insert([
                'id' => $o['id'],
                'inbox_event_id' => $event->id,
                'outlet_id' => $event->outlet_id,
                'doc_no' => $o['doc_no'] ?? null,
                'channel' => $o['channel'],
                'business_date' => $o['business_date'],
                'gross_amount' => $o['gross_amount'],
                'discount_amount' => $o['discount_amount'],
                'net_amount' => $o['net_amount'],
                'finalized_at' => $o['finalized_at'] ?? $event->created_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($o['lines'] ?? [] as $l) {
                DB::table('hq_order_lines')->insert([
                    'hq_order_id' => $o['id'],
                    'line_no' => $l['line_no'],
                    'line_type' => $l['line_type'],
                    'product_id' => $l['product_id'],
                    'quantity' => $l['quantity'],
                    'uom' => $l['uom'],
                    'gross_amount' => $l['gross_amount'],
                    'discount_amount' => $l['discount_amount'],
                    'net_amount' => $l['net_amount'],
                ]);
                foreach ($l['discounts'] ?? [] as $d) {
                    DB::table('hq_order_discounts')->insert([
                        'hq_order_id' => $o['id'],
                        'line_no' => $l['line_no'],
                        'promotion_version_id' => $d['promotion_version_id'] ?? null,
                        'amount' => $d['amount'],
                        'funded_by' => $d['funded_by'] ?? 'internal',
                    ]);
                }
            }

            foreach ($o['payments'] ?? [] as $p) {
                if (($p['status'] ?? 'succeeded') !== 'succeeded') {
                    continue;
                }
                DB::table('hq_order_payments')->insert(['hq_order_id' => $o['id'], 'method' => $p['method'], 'amount' => $p['amount']]);
            }
        });
    }

    public function rebuild(): int
    {
        foreach (['hq_order_payments', 'hq_order_discounts', 'hq_order_lines', 'hq_orders'] as $table) {
            DB::table($table)->delete();
        }
        $count = 0;
        InboxEvent::where('event_type', 'order.finalized')->orderBy('id')->chunkById(500, function ($events) use (&$count) {
            foreach ($events as $event) {
                $this->project($event);
                $count++;
            }
        });

        return $count;
    }
}
