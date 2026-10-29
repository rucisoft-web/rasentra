<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Inventory\StockLedger;
use App\Domain\Kitchen\KitchenService;
use App\Domain\Recipe\RecipeService;
use App\Models\ConsumptionEvent;
use App\Models\Product;
use App\Models\TicketItem;

class RestaurantTest extends AcceptanceTestCase
{
    private function kitchen(): KitchenService
    {
        return app(KitchenService::class);
    }

    /** U08: sepuluh porsi mengonsumsi sepuluh kali resep. */
    public function test_u08_ten_portions_consume_ten_times_recipe(): void
    {
        $steak = Product::where('code', '0700701')->first();
        $potato = Product::where('code', '0500501')->first();
        $before = StockLedger::balance($this->outlet->id, $potato->id);

        $order = $this->newOrder('dine_in');
        $this->orders()->addSku($order, $steak, '10');
        $tickets = $this->kitchen()->send($order);
        $this->assertCount(1, $tickets);
        $this->assertSame('grill', $tickets[0]->station);

        $item = TicketItem::firstOrFail();
        $this->kitchen()->start($item, $this->cashier);
        $this->kitchen()->start($item, $this->cashier); // ulang tidak mengonsumsi dua kali

        $this->assertSame(5, ConsumptionEvent::where('source_id', $item->id)->count());
        $this->assertSame('1.500000', (string) ConsumptionEvent::where('source_id', $item->id)->where('product_id', $potato->id)->value('quantity'));
        $this->assertSame('1.500000', (string) \App\Support\Decimal::of($before)->minus(\App\Support\Decimal::of(StockLedger::balance($this->outlet->id, $potato->id)))->toScale(6));

        // Menu tidak dipotong lagi saat finalisasi penjualan.
        $this->payAndFinalize($order);
        $this->assertSame('1.500000', (string) \App\Support\Decimal::of($before)->minus(\App\Support\Decimal::of(StockLedger::balance($this->outlet->id, $potato->id)))->toScale(6));
    }

    /** U07 (sebagian): kirim ulang ke dapur hanya mengirim delta. */
    public function test_u07_resend_only_sends_delta(): void
    {
        $order = $this->newOrder('dine_in');
        $this->orders()->addSku($order, Product::where('code', '0700701')->first(), '2');
        $this->kitchen()->send($order);
        $this->assertCount(0, $this->kitchen()->send($order));

        $this->orders()->addSku($order, Product::where('code', '0700703')->first(), '2');
        $tickets = $this->kitchen()->send($order);
        $this->assertCount(1, $tickets);
        $this->assertSame('bar', $tickets[0]->station);
        $this->assertSame(2, TicketItem::count());
    }

    /** U09: produksi saus lalu jual menu; penjualan memotong saus hasil, bukan bahan asal. */
    public function test_u09_production_then_sale_uses_prep_item(): void
    {
        $sauce = Product::where('code', '0600601')->first();
        $butter = Product::where('code', '0500503')->first();
        $butterBefore = StockLedger::balance($this->outlet->id, $butter->id);

        app(RecipeService::class)->produce($this->outlet->id, $this->cashier,
            [['product_id' => $butter->id, 'quantity' => '0.500', 'uom' => 'KG']],
            [['product_id' => $sauce->id, 'quantity' => '0.480', 'uom' => 'KG']], 'susut masak');
        $afterProduction = StockLedger::balance($this->outlet->id, $butter->id);
        $this->assertSame('0.500000', (string) \App\Support\Decimal::of($butterBefore)->minus(\App\Support\Decimal::of($afterProduction))->toScale(6));

        $order = $this->newOrder('dine_in');
        $this->orders()->addSku($order, Product::where('code', '0700701')->first(), '1');
        $this->kitchen()->send($order);
        $this->kitchen()->start(TicketItem::firstOrFail(), $this->cashier);

        $this->assertSame('0.050000', (string) ConsumptionEvent::where('product_id', $sauce->id)->value('quantity'));
        // Butter dari resep steak (0,010) saja, bukan butter dari produksi saus lagi.
        $this->assertSame('0.010000', (string) \App\Support\Decimal::of($afterProduction)->minus(\App\Support\Decimal::of(StockLedger::balance($this->outlet->id, $butter->id)))->toScale(6));
    }

    /** U10: batal sebelum masak tanpa konsumsi; setelah masak menjadi waste. */
    public function test_u10_cancel_before_and_after_cooking(): void
    {
        $order = $this->newOrder('dine_in');
        $this->orders()->addSku($order, Product::where('code', '0700702')->first(), '2');
        $this->kitchen()->send($order);
        $item = TicketItem::firstOrFail();
        $this->kitchen()->cancel($item, $this->cashier, 'tamu batal');
        $this->assertSame(0, ConsumptionEvent::count());

        $order2 = $this->newOrder('dine_in');
        $this->orders()->addSku($order2, Product::where('code', '0700702')->first(), '1');
        $this->kitchen()->send($order2);
        $item2 = TicketItem::where('id', '!=', $item->id)->firstOrFail();
        $this->kitchen()->start($item2, $this->cashier);
        $this->kitchen()->cancel($item2, $this->cashier, 'jatuh');
        $this->assertSame('waste', ConsumptionEvent::where('source_id', $item2->id)->value('reason'));
    }

    /** U12: produksi potong daging dengan neraca berat. */
    public function test_u12_cutting_yield_balance(): void
    {
        $meat = Product::where('code', '0100101')->first();
        $steakMeat = Product::where('code', '0600602')->first();
        $trim = Product::firstOrCreate(['code' => '0600603'], ['name' => 'Trimming', 'type' => 'prep', 'uom_code' => 'KG', 'is_weighed' => true]);

        $this->assertDomainError('loss_reason_required', fn () => app(RecipeService::class)->produce($this->outlet->id, $this->cashier,
            [['product_id' => $meat->id, 'quantity' => '10']], [['product_id' => $steakMeat->id, 'quantity' => '8'], ['product_id' => $trim->id, 'quantity' => '1.5']]));

        $batch = app(RecipeService::class)->produce($this->outlet->id, $this->cashier,
            [['product_id' => $meat->id, 'quantity' => '10']], [['product_id' => $steakMeat->id, 'quantity' => '8'], ['product_id' => $trim->id, 'quantity' => '1.5']], 'susut potong');
        $this->assertSame('0.500000', (string) $batch->loss_quantity);
    }
}
