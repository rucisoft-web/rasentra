<?php

namespace App\Domain\Operations;

use App\Domain\Audit\Audit;
use App\Domain\Catalog\PriceResolver;
use App\Domain\Kitchen\KitchenService;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\TableService;
use App\Domain\Settings\Settings;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Models\User;
use App\Models\Visit;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self ordering: tamu memindai QR di meja, memilih menu dari HP, lalu pesanannya menunggu
 * diterima pelayan atau kasir. Setelah diterima, item masuk ke pesanan meja yang sama dengan
 * pesanan dari Mobile Terminal (harga, promo, resep dan dapur memakai jalur yang sama), jadi
 * operasional tetap konsisten dan tamu iseng tidak bisa langsung membebani dapur.
 */
final class SelfOrderService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PriceResolver $prices,
        private readonly TableService $tables,
        private readonly OrderService $orders,
        private readonly KitchenService $kitchen,
    ) {}

    public function table(string $token): DiningTable
    {
        $table = DiningTable::where('qr_token', $token)->where('is_active', true)->first();
        if ($table === null) {
            throw new DomainException('table_unknown', 'QR meja tidak dikenal. Minta bantuan pelayan.', 404);
        }
        if (! $this->settings->get('self_order.enabled', $table->outlet_id)) {
            throw new DomainException('self_order_disabled', 'Pemesanan mandiri sedang tidak tersedia. Silakan panggil pelayan.', 403);
        }

        return $table;
    }

    /** Menu restoran dengan harga dine-in outlet meja ini. */
    public function menu(DiningTable $table): array
    {
        return Product::where('is_active', true)->where('type', 'menu')->orderBy('category')->orderBy('name')->get()
            ->map(function (Product $p) use ($table) {
                try {
                    $price = $this->prices->resolve($p->id, $table->outlet_id, 'dine_in')['unit_price'];
                } catch (DomainException) {
                    return null;
                }

                return $p->only(['id', 'name', 'category']) + ['price' => (string) $price];
            })->filter()->values()->all();
    }

    /** @param list<array{product_id:int, quantity:int, note?:string|null}> $items */
    public function submit(DiningTable $table, array $items, array $guest = []): SelfOrder
    {
        $menu = collect($this->menu($table))->keyBy('id');
        $lines = [];
        $total = Decimal::of(0);
        foreach ($items as $i) {
            $p = $menu->get((int) $i['product_id']);
            $qty = (int) ($i['quantity'] ?? 1);
            if ($p === null || $qty < 1 || $qty > 20) {
                throw new DomainException('item_invalid', 'Ada menu yang tidak tersedia.', 422);
            }
            $lines[] = ['product_id' => $p['id'], 'name' => $p['name'], 'quantity' => $qty, 'price' => $p['price'], 'note' => isset($i['note']) ? mb_substr((string) $i['note'], 0, 120) : null];
            $total = $total->plus(Decimal::mul($p['price'], $qty));
        }
        if ($lines === []) {
            throw new DomainException('cart_empty', 'Pilih minimal satu menu.', 422);
        }
        // Batasi banjir pesanan dari satu meja: maksimal 3 pesanan menunggu sekaligus.
        if (SelfOrder::where('dining_table_id', $table->id)->where('status', 'submitted')->count() >= 3) {
            throw DomainException::conflict('too_many_pending', 'Pesanan sebelumnya masih menunggu konfirmasi pelayan.');
        }

        return SelfOrder::create([
            'id' => (string) Str::uuid7(),
            'outlet_id' => $table->outlet_id,
            'dining_table_id' => $table->id,
            'guest_name' => isset($guest['name']) ? mb_substr((string) $guest['name'], 0, 60) : null,
            'guests' => max(1, min(50, (int) ($guest['guests'] ?? 1))),
            'items' => $lines,
            'estimated_total' => Decimal::money($total),
            'note' => isset($guest['note']) ? mb_substr((string) $guest['note'], 0, 200) : null,
            'status' => 'submitted',
        ]);
    }

    /** Pelayan menerima: buka meja bila kosong, masukkan item, kirim ke dapur sesuai pengaturan. */
    public function accept(SelfOrder $selfOrder, User $user): SelfOrder
    {
        return DB::transaction(function () use ($selfOrder, $user) {
            $selfOrder = SelfOrder::lockForUpdate()->findOrFail($selfOrder->id);
            if ($selfOrder->status !== 'submitted') {
                throw DomainException::conflict('self_order_handled', 'Pesanan ini sudah diproses.');
            }
            $table = DiningTable::findOrFail($selfOrder->dining_table_id);
            $visit = Visit::where('dining_table_id', $table->id)->where('status', 'open')->first()
                ?? $this->tables->open($table->outlet_id, $table, $selfOrder->guests, $user);
            $order = $this->tables->order($visit);
            foreach ($selfOrder->items as $i) {
                $this->orders->addSku($order, Product::findOrFail($i['product_id']), (string) $i['quantity'], array_filter(['note' => $i['note'] ?? null]));
            }
            if ($this->settings->get('self_order.auto_send_kitchen', $table->outlet_id)) {
                $this->kitchen->send($order->fresh());
            }
            $selfOrder->update(['status' => 'accepted', 'visit_id' => $visit->id, 'handled_by' => $user->id, 'handled_at' => now()]);
            Audit::record('self_order.accepted', $selfOrder, $user->id, after: ['table' => $table->code, 'items' => count($selfOrder->items)], outletId: $table->outlet_id);

            return $selfOrder;
        });
    }

    public function reject(SelfOrder $selfOrder, User $user, string $reason): SelfOrder
    {
        if ($selfOrder->status !== 'submitted') {
            throw DomainException::conflict('self_order_handled', 'Pesanan ini sudah diproses.');
        }
        $selfOrder->update(['status' => 'rejected', 'reject_reason' => mb_substr($reason, 0, 120), 'handled_by' => $user->id, 'handled_at' => now()]);
        Audit::record('self_order.rejected', $selfOrder, $user->id, after: ['reason' => $reason], outletId: $selfOrder->outlet_id);

        return $selfOrder;
    }
}
