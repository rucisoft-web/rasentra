<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Inventory\StockLedger;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\Product;
use App\Support\Decimal;
use App\Support\DomainException;

class RetailPackSaleTest extends AcceptanceTestCase
{
    /** U01: jual pack 1,500 kg dan 1,750 kg pada Rp200.000/kg. */
    public function test_u01_two_packs_sold_by_actual_weight(): void
    {
        $sirloin = Product::where('code', '0100101')->first();
        $before = StockLedger::balance($this->outlet->id, $sirloin->id);

        $order = $this->newOrder();
        $this->orders()->addPack($order, 'PK-000184', $this->cashier);
        $this->orders()->addPack($order, 'PK-000191', $this->cashier);
        $order->refresh();

        $this->assertSame('650000.00', (string) $order->gross_amount);
        // Promo outlet uji "hemat 2 kg" aktif: total 3,250 kg memenuhi syarat 10%.
        $this->assertSame('65000.00', (string) $order->discount_amount);
        $this->assertSame('585000.00', (string) $order->net_amount);

        $order = $this->payAndFinalize($order);

        $this->assertSame('paid', $order->status);
        $this->assertMatchesRegularExpression('/^UJI-\d{6}-00001$/', $order->doc_no);
        $this->assertSame('sold', $this->pack('PK-000184')->status);
        $this->assertSame('sold', $this->pack('PK-000191')->status);
        $after = StockLedger::balance($this->outlet->id, $sirloin->id);
        $this->assertSame('3.250000', (string) Decimal::of($before)->minus(Decimal::of($after))->toScale(6));
        $this->assertSame(1, OutboxEvent::where('event_type', 'order.finalized')->count());

        // Finalisasi ulang tidak memotong stok lagi.
        $this->orders()->finalize($order, $this->cashier);
        $this->assertSame($after, StockLedger::balance($this->outlet->id, $sirloin->id));
    }

    /** U02: dua kasir menjual Pack ID yang sama. */
    public function test_u02_same_pack_cannot_be_sold_twice(): void
    {
        $a = $this->newOrder();
        $this->orders()->addPack($a, 'PK-000184', $this->cashier);

        $b = $this->orders()->create($this->head, $this->outlet->id, 'retail');
        try {
            $this->orders()->addPack($b, 'PK-000184', $this->head);
            $this->fail('Pack yang sedang dipakai seharusnya ditolak');
        } catch (DomainException $e) {
            $this->assertSame('pack_reserved', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        $this->payAndFinalize($a);
        $this->assertDomainError('pack_already_sold', fn () => $this->orders()->addPack($b, 'PK-000184', $this->head));
    }

    /** U03: SKU berulang menambah kuantitas; Pack ID berulang tidak menggandakan kemasan. */
    public function test_u03_rescan_sku_and_pack(): void
    {
        $order = $this->newOrder();
        $wings = Product::where('barcode', '8991234500011')->first();
        $this->orders()->addSku($order, $wings);
        $this->orders()->addSku($order, $wings);
        $this->assertSame(1, $order->lines()->count());
        $this->assertSame('2.000000', (string) $order->lines()->first()->quantity);

        $this->orders()->addPack($order, 'PK-000203', $this->cashier);
        try {
            $this->orders()->addPack($order, 'PK-000203', $this->cashier);
            $this->fail('Scan ulang Pack ID seharusnya ditolak');
        } catch (DomainException $e) {
            $this->assertSame('pack_already_in_cart', $e->errorCode);
        }
        $this->assertSame(2, $order->lines()->count());
    }

    /** U04: pack hold butuh approval; pack outlet lain diblokir. */
    public function test_u04_hold_and_other_outlet_packs(): void
    {
        $order = $this->newOrder();
        try {
            $this->orders()->addPack($order, 'PK-000999', $this->cashier);
            $this->fail('Pack hold seharusnya butuh approval');
        } catch (DomainException $e) {
            $this->assertSame('approval_required', $e->errorCode);
        }

        // Kasir tidak boleh menyetujui dirinya sendiri; kepala outlet dengan PIN benar boleh.
        $line = $this->orders()->addPack($order, 'PK-000999', $this->cashier, $this->approval);
        $this->assertNotNull($line->pack_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'pack.sell_override', 'approver_id' => $this->head->id]);

        $pack = $this->pack('PK-000310');
        $pack->update(['outlet_id' => Outlet::where('code', 'ANT')->value('id')]);
        $this->assertDomainError('pack_other_outlet', fn () => $this->orders()->addPack($order, 'PK-000310', $this->cashier));
    }

    public function test_void_releases_pack_and_unknown_pack_is_not_guessed(): void
    {
        $order = $this->newOrder();
        $line = $this->orders()->addPack($order, 'PK-000184', $this->cashier);
        $this->orders()->voidLine($line, $this->cashier, 'salah pilih');
        $this->assertSame('received', $this->pack('PK-000184')->status);

        try {
            $this->orders()->addPack($order, 'PK-UNKNOWN', $this->cashier);
            $this->fail();
        } catch (DomainException $e) {
            $this->assertSame('pack_unknown', $e->errorCode);
        }
    }

    public function test_finalize_requires_full_payment(): void
    {
        $order = $this->newOrder();
        $this->orders()->addPack($order, 'PK-000184', $this->cashier);
        $this->assertDomainError('payment_mismatch', fn () => $this->orders()->finalize($order, $this->cashier));
    }
}
