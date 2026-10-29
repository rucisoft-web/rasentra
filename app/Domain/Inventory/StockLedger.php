<?php

namespace App\Domain\Inventory;

use App\Models\Outlet;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Support\Decimal;

/**
 * Ledger stok append-only. Setiap pergerakan memperbarui proyeksi saldo dalam transaksi
 * yang sama. Unique (source_type, source_id, line, type, product) mencegah pemotongan ganda.
 */
final class StockLedger
{
    public static function move(
        int $outletId,
        int $productId,
        string $quantity,
        string $uom,
        string $movementType,
        string $sourceType,
        string $sourceId,
        int $sourceLineNo = 0,
        ?int $locationId = null,
        ?string $packId = null,
        string $state = 'available',
        ?string $businessDate = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'outlet_id' => $outletId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'pack_id' => $packId,
            'movement_type' => $movementType,
            'quantity' => Decimal::qty($quantity),
            'uom_code' => $uom,
            'stock_state' => $state,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_line_no' => $sourceLineNo,
            'occurred_at' => now(),
            'business_date' => $businessDate ?? Outlet::businessDate($outletId),
        ]);

        $balance = StockBalance::query()
            ->where(['outlet_id' => $outletId, 'location_id' => $locationId, 'product_id' => $productId, 'stock_state' => $state])
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            StockBalance::create([
                'outlet_id' => $outletId,
                'location_id' => $locationId,
                'product_id' => $productId,
                'stock_state' => $state,
                'quantity' => Decimal::qty($quantity),
            ]);
        } else {
            $balance->update(['quantity' => Decimal::qty(Decimal::of($balance->quantity)->plus(Decimal::of($quantity)))]);
        }

        return $movement;
    }

    public static function balance(int $outletId, int $productId, string $state = 'available'): string
    {
        return Decimal::qty(Decimal::sum(
            StockBalance::where(['outlet_id' => $outletId, 'product_id' => $productId, 'stock_state' => $state])->pluck('quantity')
        ));
    }
}
