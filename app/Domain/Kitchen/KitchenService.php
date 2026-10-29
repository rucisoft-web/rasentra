<?php

namespace App\Domain\Kitchen;

use App\Domain\Audit\Audit;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Recipe\RecipeService;
use App\Events\KitchenUpdated;
use App\Models\ConsumptionEvent;
use App\Models\CookingFeeRule;
use App\Models\CookingJob;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RecipeVersion;
use App\Models\TicketItem;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Rasentra Kitchen. Mengirim hanya delta (baris baru atau revisi baru) ke station,
 * sehingga koneksi ulang tidak mencetak atau memasak ulang seluruh pesanan.
 */
final class KitchenService
{
    public function __construct(private readonly RecipeService $recipes) {}

    /** @return list<KitchenTicket> */
    public function send(Order $order): array
    {
        $tickets = DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $lines = $order->lines()->where('status', 'active')
                ->whereIn('line_type', ['restaurant_menu', 'cooking_service', 'add_on'])
                ->with('product')->get();

            // Delta: baris yang direvisi (qty ditambah) hanya mengirim tambahannya; revisi tanpa
            // tambahan qty (mis. ubah harga) tidak dikirim lagi ke dapur.
            $pending = $lines->filter(fn (OrderLine $l) => $l->product->kitchen_station !== null
                && ! TicketItem::where('order_line_id', $l->id)->where('revision', $l->revision)->exists())
                ->map(function (OrderLine $l) {
                    $sent = TicketItem::where('order_line_id', $l->id)->where('status', '!=', 'cancelled')->pluck('quantity');
                    $l->setAttribute('kitchen_delta', Decimal::qty(Decimal::of($l->quantity)->minus(Decimal::sum($sent->all()))));

                    return $l;
                })
                ->filter(fn (OrderLine $l) => Decimal::of($l->kitchen_delta)->isPositive());

            $tickets = [];
            foreach ($pending->groupBy(fn ($l) => $l->product->kitchen_station) as $station => $stationLines) {
                $ticket = KitchenTicket::create([
                    'order_id' => $order->id,
                    'outlet_id' => $order->outlet_id,
                    'station' => $station,
                    'sequence' => KitchenTicket::where('order_id', $order->id)->count() + 1,
                    'bump_code' => $this->newBumpCode(),
                ]);
                foreach ($stationLines as $line) {
                    $job = $line->cooking_job_id ? CookingJob::find($line->cooking_job_id) : null;
                    $ticket->items()->create([
                        'order_line_id' => $line->id,
                        'revision' => $line->revision,
                        'quantity' => $line->kitchen_delta,
                        'instructions' => array_filter([
                            'product' => $line->product->name,
                            'modifiers' => $line->modifiers,
                            'course' => $line->course,
                            'note' => $line->note,
                            'pack_id' => $job?->pack_id,
                            'raw_weight' => $job ? (string) $job->raw_weight : null,
                            'portions' => $job?->portions,
                            'doneness' => $job?->doneness,
                            'method' => $job?->method,
                            'sauce' => $job?->sauce,
                            'allergy' => $job?->allergy_note,
                        ], fn ($v) => $v !== null && $v !== []),
                    ]);
                }
                $tickets[] = $ticket;
            }

            if ($tickets !== [] && $order->queue_no === null && in_array($order->channel, config('rasentra.kitchen.pickup_channels'), true)) {
                $order->update(['queue_no' => $this->nextQueueNo($order)]);
            }

            if ($order->status === 'draft' || $order->status === 'held') {
                $order->update(['status' => 'confirmed']);
            }

            return $tickets;
        });

        foreach ($tickets as $ticket) {
            event(new KitchenUpdated($order->outlet_id, $ticket->station, $ticket->id));
        }

        return $tickets;
    }

    public function acknowledge(TicketItem $item): TicketItem
    {
        if ($item->status === 'queued') {
            $item->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        }

        return $item;
    }

    /** Mulai memasak: konsumsi bahan sesuai versi resep dicatat sekali untuk item ini. */
    public function start(TicketItem $item, User $user): TicketItem
    {
        return DB::transaction(function () use ($item) {
            $item = TicketItem::lockForUpdate()->findOrFail($item->id);
            if (in_array($item->status, ['in_preparation', 'ready', 'served'], true)) {
                return $item;
            }
            if ($item->status === 'cancelled') {
                throw DomainException::conflict('item_cancelled', 'Item sudah dibatalkan.');
            }
            $line = OrderLine::with('order')->findOrFail($item->order_line_id);
            $version = $this->versionFor($line);

            if ($version !== null) {
                $this->recipes->consume($line->order->outlet_id, $version, (string) $item->quantity, $this->modifierKeys($line), 'ticket_item', $item->id);
                $line->update(['recipe_version_id' => $version->id]);
            }

            if ($line->cooking_job_id) {
                CookingJob::whereKey($line->cooking_job_id)->update(['status' => 'cooking']);
            }

            $item->update(['status' => 'in_preparation', 'started_at' => now()]);

            return $item;
        });
    }

    public function ready(TicketItem $item): TicketItem
    {
        $item->update(['status' => 'ready', 'ready_at' => now()]);

        return $item;
    }

    public function served(TicketItem $item): TicketItem
    {
        $item->update(['status' => 'served', 'served_at' => now()]);
        $line = OrderLine::find($item->order_line_id);
        if ($line?->cooking_job_id) {
            CookingJob::whereKey($line->cooking_job_id)->update(['status' => 'served']);
        }

        return $item;
    }

    /**
     * Batal. Sebelum dimasak: tidak ada konsumsi. Sesudah dimasak: bahan yang terpakai
     * menjadi waste, tidak kembali ke stok, dan dicatat sebagai dokumen waste untuk SAP.
     */
    public function cancel(TicketItem $item, User $user, string $reason): TicketItem
    {
        $item = DB::transaction(function () use ($item, $user, $reason) {
            $item = TicketItem::lockForUpdate()->findOrFail($item->id);
            if ($item->status === 'cancelled') {
                return $item;
            }
            $afterCook = $item->started_at !== null || in_array($item->status, ['in_preparation', 'ready', 'served'], true);
            if ($afterCook) {
                $this->wasteConsumption($item, $user, $reason);
            }
            $item->update(['status' => 'cancelled', 'cancel_reason' => $item->cancel_reason ?? $reason]);
            Audit::record($afterCook ? 'kitchen.cancel_after_cook' : 'kitchen.cancel_before_cook', $item, $user->id, reason: $reason);

            return $item;
        });
        $this->broadcast($item);

        return $item;
    }

    /**
     * Kasir mem-void baris yang sudah dikirim ke dapur. Item yang belum dimasak langsung batal;
     * item yang sedang dimasak atau sudah siap menjadi "minta batal" dan harus dikonfirmasi
     * station, karena bahannya sudah terpakai dan akan dicatat sebagai waste.
     */
    public function lineVoided(OrderLine $line, User $actor, ?string $reason): void
    {
        $items = TicketItem::where('order_line_id', $line->id)->whereIn('status', ['queued', 'acknowledged', 'in_preparation', 'ready'])->get();
        foreach ($items as $item) {
            if ($item->started_at === null && in_array($item->status, ['queued', 'acknowledged'], true)) {
                $item->update(['status' => 'cancelled', 'cancel_reason' => $reason ?? 'Void kasir']);
                Audit::record('kitchen.cancel_before_cook', $item, $actor->id, reason: $reason ?? 'Void kasir');
            } else {
                $item->update(['status' => 'cancel_requested', 'cancel_reason' => $reason ?? 'Void kasir']);
                Audit::record('kitchen.cancel_requested', $item, $actor->id, reason: $reason ?? 'Void kasir');
            }
            $this->broadcast($item);
        }
    }

    /** Station mengonfirmasi permintaan batal dari kasir. */
    public function confirmCancel(TicketItem $item, User $user): TicketItem
    {
        if ($item->status !== 'cancel_requested') {
            throw DomainException::conflict('no_cancel_request', 'Item ini tidak sedang diminta batal.');
        }

        return $this->cancel($item, $user, $item->cancel_reason ?? 'Void kasir');
    }

    /** Bahan yang sudah dikonsumsi resep berubah menjadi waste dan dicatat sebagai dokumen penyesuaian. */
    private function wasteConsumption(TicketItem $item, User $user, string $reason): void
    {
        $events = ConsumptionEvent::where('source_type', 'ticket_item')->where('source_id', $item->id)->where('reason', '!=', 'waste')->get();
        if ($events->isEmpty()) {
            return;
        }
        ConsumptionEvent::whereKey($events->pluck('id'))->update(['reason' => 'waste']);
        $outletId = KitchenTicket::whereKey($item->kitchen_ticket_id)->value('outlet_id');
        // Stok sudah keluar saat konsumsi; dokumen ini hanya memindahkan pencatatannya ke waste.
        app(StockAdjustmentService::class)->record('waste', $outletId, $user, null, 'Batal setelah dimasak: '.$reason,
            $events->map(fn (ConsumptionEvent $e) => [
                'product_id' => $e->product_id, 'pack_id' => null, 'quantity' => (string) $e->quantity, 'uom' => $e->uom_code,
                'from_state' => 'available', 'to_state' => null,
            ])->values()->all(), 'ticket_item', $item->id);
    }

    private function broadcast(TicketItem $item): void
    {
        $ticket = KitchenTicket::find($item->kitchen_ticket_id);
        if ($ticket?->outlet_id !== null) {
            event(new KitchenUpdated($ticket->outlet_id, $ticket->station, $ticket->id));
        }
    }

    /** Kode numerik pendek untuk barcode tiket cetak; dipindai di layar station untuk bump. */
    private function newBumpCode(): string
    {
        do {
            $code = (string) random_int(10000000, 99999999);
        } while (KitchenTicket::where('bump_code', $code)->exists());

        return $code;
    }

    /** Nomor antrean pickup per outlet per hari usaha, dipanggil di layar antrean. */
    private function nextQueueNo(Order $order): int
    {
        $last = Order::where('outlet_id', $order->outlet_id)->where('business_date', $order->business_date)
            ->lockForUpdate()->max('queue_no');

        return ((int) $last % 999) + 1;
    }

    private function versionFor(OrderLine $line): ?RecipeVersion
    {
        if ($line->line_type === 'cooking_service' && $line->cooking_job_id) {
            // Resep jasa hanya memuat bahan tambahan (butter, bumbu, saus). Daging pelanggan tidak dipotong lagi.
            $job = CookingJob::find($line->cooking_job_id);
            $rule = CookingFeeRule::find($job?->fee_rule_id);

            return $rule?->recipe_version_id ? RecipeVersion::with('lines')->find($rule->recipe_version_id) : null;
        }

        return $this->recipes->activeVersionFor($line->product_id)?->load('lines');
    }

    private function modifierKeys(OrderLine $line): array
    {
        return array_values(array_map(fn ($m) => is_array($m) ? ($m['key'] ?? '') : (string) $m, $line->modifiers ?? []));
    }
}
