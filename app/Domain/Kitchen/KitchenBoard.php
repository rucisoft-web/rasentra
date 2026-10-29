<?php

namespace App\Domain\Kitchen;

use App\Events\KitchenUpdated;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\TicketItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Layar Rasentra Kitchen: papan station, expeditor, antrean pickup dan analisis waktu saji.
 * Status item maju satu langkah per bump: Baru -> Dimasak -> Siap -> Disajikan.
 * Station hanya membawa item sampai Siap; Disajikan dilakukan expeditor atau pelayan.
 */
final class KitchenBoard
{
    public const ACTIVE = ['queued', 'acknowledged', 'in_preparation', 'ready'];

    public function __construct(private readonly KitchenService $kitchen) {}

    /**
     * Tiket aktif untuk satu station, atau semua station bila $station = null (expeditor).
     * Tiket yang baru tampil otomatis berstatus diterima (acknowledged).
     */
    public function board(int $outletId, ?string $station): array
    {
        $tickets = KitchenTicket::query()
            ->where('outlet_id', $outletId)
            ->when($station, fn ($q) => $q->where('station', $station))
            ->whereHas('items', fn ($q) => $q->whereIn('status', $station ? ['queued', 'acknowledged', 'in_preparation'] : self::ACTIVE))
            ->with(['items' => fn ($q) => $q->orderBy('created_at'), 'order:id,doc_no,channel,queue_no,visit_id,status'])
            ->orderBy('created_at')
            ->limit(80)
            ->get();

        if ($station !== null) {
            TicketItem::whereIn('kitchen_ticket_id', $tickets->pluck('id'))->where('status', 'queued')
                ->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
            $tickets->load(['items' => fn ($q) => $q->orderBy('created_at')]);
        }

        $tables = $this->tablesFor($tickets->pluck('order.visit_id')->filter()->unique());
        $now = now();

        $cards = $tickets->map(fn (KitchenTicket $t) => $this->card($t, $tables, $now, $station !== null))->values();

        if ($station === null) {
            // Expeditor: kelompokkan per pesanan agar kelengkapan meja terlihat sekaligus.
            $cards = $cards->groupBy('order_id')->map(function (Collection $group) {
                $first = $group->first();
                $items = $group->flatMap(fn ($c) => collect($c['items'])->map(fn ($i) => $i + ['station' => $c['station']]));

                return [
                    'order_id' => $first['order_id'],
                    'label' => $first['label'],
                    'channel' => $first['channel'],
                    'elapsed' => $group->max('elapsed'),
                    'level' => $group->contains('level', 'late') ? 'late' : ($group->contains('level', 'warn') ? 'warn' : 'ok'),
                    'items' => $items->values(),
                    'complete' => $items->every(fn ($i) => $i['status'] === 'ready'),
                ];
            })->values();
        }

        return [
            'station' => $station,
            'stations' => config('rasentra.kitchen.stations'),
            'server_time' => $now->toIso8601String(),
            'tickets' => $cards,
        ];
    }

    /** Bump satu item: maju satu langkah. */
    public function bumpItem(TicketItem $item, User $user, bool $expeditor = false): TicketItem
    {
        $item = match ($item->status) {
            'queued', 'acknowledged' => $this->kitchen->start($item, $user),
            'in_preparation' => $this->kitchen->ready($item),
            'ready' => $expeditor ? $this->kitchen->served($item)
                : throw DomainException::conflict('needs_expeditor', 'Item sudah Siap. Penyajian dikonfirmasi expeditor.'),
            default => throw DomainException::conflict('item_closed', 'Item sudah selesai atau dibatalkan.'),
        };
        $item->update(['bumped_by' => $user->id]);
        $this->broadcast($item->ticket);

        return $item;
    }

    /**
     * Bump satu tiket: item paling belakang statusnya dimajukan bersama, sehingga satu
     * sentuhan pada kartu tiket memindahkan seluruh tiket ke langkah berikutnya.
     */
    public function bumpTicket(KitchenTicket $ticket, User $user, bool $expeditor = false): KitchenTicket
    {
        DB::transaction(function () use ($ticket, $user, $expeditor) {
            $items = $ticket->items()->whereIn('status', $expeditor ? self::ACTIVE : ['queued', 'acknowledged', 'in_preparation'])->get();
            if ($items->isEmpty()) {
                throw DomainException::conflict('ticket_done', 'Tiket ini sudah selesai di station.');
            }
            $rank = ['queued' => 0, 'acknowledged' => 0, 'in_preparation' => 1, 'ready' => 2];
            $lowest = $items->min(fn ($i) => $rank[$i->status]);
            foreach ($items->filter(fn ($i) => $rank[$i->status] === $lowest) as $item) {
                match ($item->status) {
                    'queued', 'acknowledged' => $this->kitchen->start($item, $user),
                    'in_preparation' => $this->kitchen->ready($item),
                    'ready' => $this->kitchen->served($item),
                };
                $item->update(['bumped_by' => $user->id]);
            }
        });
        $this->broadcast($ticket);

        return $ticket->fresh('items');
    }

    /** Bump lewat scan barcode tiket cetak. */
    public function bumpByCode(int $outletId, string $code, User $user, bool $expeditor = false): KitchenTicket
    {
        $ticket = KitchenTicket::where('outlet_id', $outletId)->where('bump_code', trim($code))->first();
        if ($ticket === null) {
            throw new DomainException('ticket_unknown', "Tiket {$code} tidak ditemukan di outlet ini.", 404);
        }

        return $this->bumpTicket($ticket, $user, $expeditor);
    }

    /** Kembalikan item Siap ke Dimasak bila salah sentuh (tanpa konsumsi ulang). */
    public function recall(TicketItem $item): TicketItem
    {
        if ($item->status !== 'ready') {
            throw DomainException::conflict('cannot_recall', 'Hanya item Siap yang bisa dikembalikan.');
        }
        $item->update(['status' => 'in_preparation', 'ready_at' => null]);
        $this->broadcast($item->ticket);

        return $item;
    }

    /**
     * Layar antrean pickup untuk takeaway dan kiosk. Status pesanan dihitung otomatis dari
     * item dapurnya: Disiapkan selama ada item belum Siap, Siap diambil saat semua Siap.
     */
    public function queue(int $outletId): array
    {
        $linger = now()->subMinutes((int) config('rasentra.kitchen.queue_linger_minutes'));
        $orders = Order::where('outlet_id', $outletId)
            ->whereIn('channel', config('rasentra.kitchen.pickup_channels'))
            ->whereNotNull('queue_no')
            ->where('created_at', '>=', now()->subHours(12))
            ->get(['id', 'queue_no', 'channel']);

        $items = TicketItem::query()
            ->join('kitchen_tickets', 'kitchen_tickets.id', '=', 'ticket_items.kitchen_ticket_id')
            ->whereIn('kitchen_tickets.order_id', $orders->pluck('id'))
            ->where('ticket_items.status', '!=', 'cancelled')
            ->get(['kitchen_tickets.order_id', 'ticket_items.status', 'ticket_items.ready_at', 'ticket_items.served_at'])
            ->groupBy('order_id');

        $preparing = [];
        $ready = [];
        foreach ($orders as $order) {
            $own = $items->get($order->id);
            if ($own === null) {
                continue;
            }
            if ($own->every(fn ($i) => $i->status === 'served')) {
                if ($own->max('served_at') < $linger) {
                    continue;
                }
                $ready[] = ['no' => $order->queue_no, 'served' => true, 'at' => $own->max('ready_at')];
            } elseif ($own->every(fn ($i) => in_array($i->status, ['ready', 'served'], true))) {
                $ready[] = ['no' => $order->queue_no, 'served' => false, 'at' => $own->max('ready_at')];
            } else {
                $preparing[] = ['no' => $order->queue_no];
            }
        }

        usort($ready, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        usort($preparing, fn ($a, $b) => $a['no'] <=> $b['no']);

        return ['preparing' => $preparing, 'ready' => $ready, 'server_time' => now()->toIso8601String()];
    }

    /**
     * Analisis waktu saji: dari tiket dibuat sampai Siap, per station, per menu dan per jam.
     * Dipakai kepala dapur untuk melihat bottleneck dan menyetel target waktu.
     */
    public function analytics(int $outletId, Carbon $from, Carbon $to): array
    {
        $rows = TicketItem::query()
            ->join('kitchen_tickets', 'kitchen_tickets.id', '=', 'ticket_items.kitchen_ticket_id')
            ->where('kitchen_tickets.outlet_id', $outletId)
            ->whereBetween('kitchen_tickets.created_at', [$from, $to])
            ->whereNotNull('ticket_items.ready_at')
            ->get(['kitchen_tickets.station', 'kitchen_tickets.created_at as ticket_at', 'ticket_items.started_at', 'ticket_items.ready_at', 'ticket_items.instructions'])
            ->map(function ($r) {
                $created = Carbon::parse($r->ticket_at);
                $ready = Carbon::parse($r->ready_at);
                $started = $r->started_at ? Carbon::parse($r->started_at) : null;

                return [
                    'station' => $r->station,
                    'product' => $r->instructions['product'] ?? '-',
                    'hour' => (int) $created->format('G'),
                    'total' => max(0, $created->diffInSeconds($ready)),
                    'wait' => $started ? max(0, $created->diffInSeconds($started)) : null,
                    'cook' => $started ? max(0, $started->diffInSeconds($ready)) : null,
                ];
            });

        $stations = config('rasentra.kitchen.stations');
        $summ = function (Collection $g, ?string $station = null) use ($stations) {
            $totals = $g->pluck('total')->sort()->values();
            $target = $station ? ($stations[$station]['target_minutes'] ?? null) : null;

            return [
                'count' => $totals->count(),
                'avg_seconds' => (int) round($totals->avg() ?? 0),
                'p50_seconds' => (int) $this->percentile($totals, 0.5),
                'p90_seconds' => (int) $this->percentile($totals, 0.9),
                'avg_wait_seconds' => (int) round($g->pluck('wait')->filter(fn ($v) => $v !== null)->avg() ?? 0),
                'avg_cook_seconds' => (int) round($g->pluck('cook')->filter(fn ($v) => $v !== null)->avg() ?? 0),
                'target_seconds' => $target ? $target * 60 : null,
                'late' => $target ? $totals->filter(fn ($t) => $t > $target * 60)->count() : null,
            ];
        };

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'overall' => $summ($rows),
            'by_station' => $rows->groupBy('station')->map(fn ($g, $s) => ['station' => $s] + $summ($g, $s))->values(),
            'by_product' => $rows->groupBy('product')->map(fn ($g, $p) => ['product' => $p] + $summ($g))->sortByDesc('avg_seconds')->values(),
            'by_hour' => $rows->groupBy('hour')->map(fn ($g, $h) => ['hour' => $h] + $summ($g))->sortBy('hour')->values(),
        ];
    }

    private function card(KitchenTicket $t, Collection $tables, Carbon $now, bool $stationView): array
    {
        $target = (int) (config("rasentra.kitchen.stations.{$t->station}.target_minutes") ?? 10) * 60;
        $elapsed = (int) $t->created_at->diffInSeconds($now);
        $ratio = $target > 0 ? $elapsed / $target : 0;
        $order = $t->order;
        $table = $order?->visit_id ? $tables->get($order->visit_id) : null;
        $label = match (true) {
            $table !== null => 'Meja '.$table,
            $order?->queue_no !== null => 'No. '.$order->queue_no,
            default => $order?->doc_no ?? '#'.$t->sequence,
        };

        return [
            'id' => $t->id,
            'order_id' => $t->order_id,
            'station' => $t->station,
            'bump_code' => $t->bump_code,
            'sequence' => $t->sequence,
            'label' => $label,
            'channel' => $order?->channel,
            'created_at' => $t->created_at->toIso8601String(),
            'elapsed' => $elapsed,
            'target' => $target,
            'level' => $ratio >= 1 ? 'late' : ($ratio >= (float) config('rasentra.kitchen.warn_ratio') ? 'warn' : 'ok'),
            'items' => $t->items
                ->filter(fn ($i) => in_array($i->status, $stationView ? ['queued', 'acknowledged', 'in_preparation', 'ready', 'cancelled'] : self::ACTIVE, true))
                ->map(fn (TicketItem $i) => [
                    'id' => $i->id,
                    'status' => $i->status,
                    'quantity' => (string) $i->quantity,
                    'instructions' => $i->instructions,
                ])->values(),
        ];
    }

    private function tablesFor(Collection $visitIds): Collection
    {
        if ($visitIds->isEmpty()) {
            return collect();
        }

        $visits = Visit::whereIn('id', $visitIds)->pluck('dining_table_id', 'id');

        return $visits->map(fn ($tableId) => DiningTable::whereKey($tableId)->value('code'));
    }

    private function percentile(Collection $sorted, float $p): float
    {
        if ($sorted->isEmpty()) {
            return 0;
        }
        $idx = (int) ceil($p * $sorted->count()) - 1;

        return (float) $sorted[max(0, $idx)];
    }

    private function broadcast(?KitchenTicket $ticket): void
    {
        if ($ticket !== null && $ticket->outlet_id !== null) {
            event(new KitchenUpdated($ticket->outlet_id, $ticket->station, $ticket->id));
        }
    }
}
