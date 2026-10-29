<?php

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\TicketItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rasentra Mobile Terminal: meja dan kunjungan tamu. Setiap kunjungan punya token sendiri;
 * tablet memakai token itu di setiap permintaan, sehingga pesanan tidak pernah masuk ke tamu
 * sebelumnya setelah meja dibayar dan dibuka lagi.
 */
final class TableService
{
    public function __construct(private readonly OrderService $orders) {}

    /** Peta meja per area: kosong, terisi atau minta bayar, plus ringkasan pesanan dan dapur. */
    public function map(int $outletId): array
    {
        $tables = DiningTable::where('outlet_id', $outletId)->where('is_active', true)->orderBy('area')->orderBy('code')->get();
        $visits = Visit::where('outlet_id', $outletId)->where('status', 'open')->get()->keyBy('dining_table_id');
        $orders = Order::whereIn('visit_id', $visits->pluck('id'))->whereIn('status', ['draft', 'held', 'confirmed'])->get()->keyBy('visit_id');
        $kitchen = $this->kitchenSummary($orders->pluck('id'));
        $waiters = User::whereIn('id', $visits->pluck('waiter_id')->filter())->pluck('name', 'id');

        return $tables->map(function (DiningTable $t) use ($visits, $orders, $kitchen, $waiters) {
            $visit = $visits->get($t->id);
            $order = $visit ? $orders->get($visit->id) : null;
            $k = $order ? ($kitchen[$order->id] ?? []) : [];

            return [
                'id' => $t->id,
                'code' => $t->code,
                'area' => $t->area ?? 'Lainnya',
                'capacity' => $t->capacity,
                'status' => $visit === null ? 'empty' : ($visit->bill_requested_at ? 'billing' : 'occupied'),
                'visit' => $visit ? [
                    'token' => $visit->visit_token,
                    'guests' => $visit->guest_count,
                    'waiter' => $waiters[$visit->waiter_id] ?? null,
                    'opened_at' => $visit->opened_at->toIso8601String(),
                    'net_amount' => $order ? (string) $order->net_amount : '0',
                    'ready' => $k['ready'] ?? 0,
                    'cooking' => $k['cooking'] ?? 0,
                    'unsent' => $order ? $this->unsentCount($order) : 0,
                ] : null,
            ];
        })->values()->all();
    }

    public function open(int $outletId, DiningTable $table, int $guests, User $waiter): Visit
    {
        if ((int) $table->outlet_id !== $outletId || ! $table->is_active) {
            throw new DomainException('table_unknown', 'Meja tidak ditemukan di outlet ini.', 404);
        }

        return DB::transaction(function () use ($outletId, $table, $guests, $waiter) {
            DiningTable::lockForUpdate()->findOrFail($table->id);
            if (Visit::where('dining_table_id', $table->id)->where('status', 'open')->exists()) {
                throw DomainException::conflict('table_occupied', "Meja {$table->code} sudah terisi.");
            }
            $visit = Visit::create([
                'outlet_id' => $outletId, 'dining_table_id' => $table->id, 'visit_token' => Str::random(40),
                'guest_count' => $guests, 'waiter_id' => $waiter->id, 'status' => 'open', 'opened_at' => now(),
            ]);
            // Pesanan meja belum punya shift; kasir yang menerima pembayaran mengambilnya ke shift-nya.
            $this->orders->create($waiter, $outletId, 'dine_in', ['visit_id' => $visit->id]);
            Audit::record('table.opened', $visit, $waiter->id, after: ['table' => $table->code, 'guests' => $guests], outletId: $outletId);

            return $visit;
        });
    }

    /** Kunjungan yang masih buka untuk token ini; token lama ditolak. */
    public function visit(int $outletId, string $token): Visit
    {
        $visit = Visit::where('visit_token', $token)->where('outlet_id', $outletId)->first();
        if ($visit === null) {
            throw new DomainException('visit_unknown', 'Kunjungan tidak ditemukan.', 404);
        }
        if ($visit->status !== 'open') {
            throw DomainException::conflict('visit_closed', 'Meja ini sudah dibayar dan ditutup. Buka lagi dari peta meja.');
        }

        return $visit;
    }

    public function order(Visit $visit): Order
    {
        $order = Order::where('visit_id', $visit->id)->latest()->first();
        if ($order === null || ! $order->isEditable()) {
            throw DomainException::conflict('order_not_editable', 'Pesanan meja ini sudah dibayar.');
        }

        return $order;
    }

    /** Detail meja untuk tablet: baris pesanan dengan status dapur per baris. */
    public function detail(Visit $visit): array
    {
        $order = Order::where('visit_id', $visit->id)->latest()->firstOrFail();
        $lines = $order->lines()->where('status', 'active')->with('product:id,name,category,kitchen_station')->orderBy('line_no')->get();
        $items = TicketItem::whereIn('order_line_id', $lines->pluck('id'))->get()->groupBy('order_line_id');
        $table = DiningTable::find($visit->dining_table_id);

        return [
            'visit' => [
                'token' => $visit->visit_token, 'guests' => $visit->guest_count, 'opened_at' => $visit->opened_at->toIso8601String(),
                'bill_requested' => $visit->bill_requested_at !== null, 'waiter' => User::whereKey($visit->waiter_id)->value('name'),
                'table' => ['id' => $table->id, 'code' => $table->code, 'area' => $table->area, 'capacity' => $table->capacity],
            ],
            'order' => $order->only(['id', 'status', 'gross_amount', 'discount_amount', 'net_amount']),
            'lines' => $lines->map(function (OrderLine $l) use ($items) {
                $own = ($items->get($l->id) ?? collect())->where('status', '!=', 'cancelled');

                return [
                    'id' => $l->id,
                    'name' => $l->product->name,
                    'line_type' => $l->line_type,
                    'quantity' => (string) $l->quantity,
                    'uom' => $l->uom_code,
                    'net_amount' => (string) $l->net_amount,
                    'modifiers' => collect($l->modifiers ?? [])->map(fn ($m) => is_array($m) ? ($m['label'] ?? $m['key'] ?? '') : (string) $m)
                        ->reject(fn ($m) => $m === '' || str_starts_with($m, 'Cooking fee'))->values(),
                    'note' => $l->note,
                    'course' => $l->course,
                    'kitchen' => $l->product->kitchen_station !== null,
                    'status' => $this->lineStatus($own),
                    'ready_items' => $own->where('status', 'ready')->pluck('id')->values(),
                    'sent_quantity' => (string) $own->sum(fn ($i) => (float) $i->quantity),
                ];
            })->values(),
        ];
    }

    public function requestBill(Visit $visit, User $user): Visit
    {
        $order = $this->order($visit);
        if (! $order->lines()->where('status', 'active')->exists()) {
            throw DomainException::conflict('order_empty', 'Belum ada pesanan untuk ditagih.');
        }
        $visit->update(['bill_requested_at' => $visit->bill_requested_at ?? now()]);
        Audit::record('table.bill_requested', $visit, $user->id, outletId: $visit->outlet_id, amount: $order->net_amount);

        return $visit;
    }

    public function move(Visit $visit, DiningTable $target, User $user): Visit
    {
        if ((int) $target->outlet_id !== (int) $visit->outlet_id || ! $target->is_active) {
            throw new DomainException('table_unknown', 'Meja tujuan tidak ditemukan di outlet ini.', 404);
        }

        return DB::transaction(function () use ($visit, $target, $user) {
            DiningTable::lockForUpdate()->findOrFail($target->id);
            if (Visit::where('dining_table_id', $target->id)->where('status', 'open')->exists()) {
                throw DomainException::conflict('table_occupied', "Meja {$target->code} sudah terisi.");
            }
            $from = DiningTable::whereKey($visit->dining_table_id)->value('code');
            $visit->update(['dining_table_id' => $target->id]);
            Audit::record('table.moved', $visit, $user->id, ['table' => $from], ['table' => $target->code], outletId: $visit->outlet_id);

            return $visit;
        });
    }

    public function guests(Visit $visit, int $guests): Visit
    {
        $visit->update(['guest_count' => $guests]);

        return $visit;
    }

    /** Tamu pergi sebelum memesan: kunjungan ditutup tanpa struk. */
    public function close(Visit $visit, User $user): void
    {
        DB::transaction(function () use ($visit, $user) {
            $order = $this->order($visit);
            if ($order->lines()->where('status', 'active')->exists()) {
                throw DomainException::conflict('order_not_empty', 'Meja masih punya pesanan. Void dulu atau bayar di kasir.');
            }
            $order->update(['status' => 'voided']);
            $visit->update(['status' => 'closed', 'closed_at' => now()]);
            Audit::record('table.closed_empty', $visit, $user->id, outletId: $visit->outlet_id);
        });
    }

    /** Validasi modifier terhadap pilihan kategori menu dan ubah ke bentuk baris pesanan. */
    public function modifiers(Product $product, array $keys): array
    {
        $groups = config('rasentra.menu_modifiers.'.$product->category, []);
        $out = [];
        foreach ($groups as $g) {
            $chosen = array_values(array_filter($g['options'], fn ($o) => in_array($o[0], $keys, true)));
            if (! empty($g['required']) && $chosen === []) {
                throw new DomainException('modifier_required', "{$g['label']} untuk {$product->name} wajib dipilih.");
            }
            if (empty($g['multiple']) && count($chosen) > 1) {
                throw new DomainException('modifier_single', "{$g['label']} hanya boleh satu pilihan.");
            }
            foreach ($chosen as [$key, $label]) {
                $out[] = ['key' => $key, 'label' => $label, 'group' => $g['group']];
            }
        }
        $unknown = array_diff($keys, array_column($out, 'key'));
        if ($unknown !== []) {
            throw new DomainException('modifier_unknown', 'Pilihan '.implode(', ', $unknown).' tidak tersedia untuk menu ini.');
        }

        return $out;
    }

    private function lineStatus(Collection $items): string
    {
        if ($items->isEmpty()) {
            return 'unsent';
        }
        $rank = ['cancel_requested' => 0, 'queued' => 1, 'acknowledged' => 1, 'in_preparation' => 2, 'ready' => 3, 'served' => 4];
        $lowest = $items->min(fn ($i) => $rank[$i->status] ?? 4);

        return ['cancel_requested', 'queued', 'in_preparation', 'ready', 'served'][$lowest];
    }

    private function unsentCount(Order $order): int
    {
        return $order->lines()->where('status', 'active')->whereHas('product', fn ($q) => $q->whereNotNull('kitchen_station'))
            ->whereNotExists(fn ($q) => $q->from('ticket_items')->whereColumn('ticket_items.order_line_id', 'order_lines.id'))->count();
    }

    /** @return array<string, array{ready: int, cooking: int}> */
    private function kitchenSummary(Collection $orderIds): array
    {
        if ($orderIds->isEmpty()) {
            return [];
        }

        return TicketItem::query()
            ->join('kitchen_tickets', 'kitchen_tickets.id', '=', 'ticket_items.kitchen_ticket_id')
            ->whereIn('kitchen_tickets.order_id', $orderIds)
            ->get(['kitchen_tickets.order_id', 'ticket_items.status'])
            ->groupBy('order_id')
            ->map(fn ($g) => [
                'ready' => $g->where('status', 'ready')->count(),
                'cooking' => $g->whereIn('status', ['queued', 'acknowledged', 'in_preparation'])->count(),
            ])->all();
    }
}
