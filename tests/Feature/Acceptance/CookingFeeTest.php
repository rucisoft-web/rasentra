<?php

namespace Tests\Feature\Acceptance;

use App\Domain\CookingService\CookingService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Kitchen\KitchenService;
use App\Models\ConsumptionEvent;
use App\Models\CookingJob;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TicketItem;

class CookingFeeTest extends AcceptanceTestCase
{
    private function cooking(): CookingService
    {
        return app(CookingService::class);
    }

    /** U25: daging 1,500 kg + cooking fee per kg; daging keluar sekali. */
    public function test_u25_meat_and_cooking_fee_on_one_order(): void
    {
        $order = $this->newOrder('dine_in');
        $packLine = $this->orders()->addPack($order, 'PK-000184', $this->cashier);
        $this->cooking()->requestForPackLine($packLine, $this->cashier, ['portions' => 2, 'doneness' => 'medium']);
        $this->orders()->addSku($order, Product::where('code', '0700702')->first(), '2');
        $order->refresh();

        $lines = $order->lines()->where('status', 'active')->get()->keyBy('line_type');
        $this->assertSame('300000.00', (string) $lines['retail_product']->gross_amount);
        $this->assertSame('60000.00', (string) $lines['cooking_service']->gross_amount);
        $this->assertSame('410000.00', (string) $order->gross_amount);

        app(KitchenService::class)->send($order);
        $feeItem = TicketItem::where('order_line_id', $lines['cooking_service']->id)->firstOrFail();
        $this->assertSame('PK-000184', \App\Models\Pack::find($feeItem->instructions['pack_id'])->pack_code);
        app(KitchenService::class)->start($feeItem, $this->cashier);

        // Resep jasa hanya bahan tambahan; daging tidak ada di konsumsi.
        $sirloin = Product::where('code', '0100101')->value('id');
        $this->assertSame(0, ConsumptionEvent::where('product_id', $sirloin)->count());

        $this->payAndFinalize($order);
        $this->assertSame(1, StockMovement::where('pack_id', $this->pack('PK-000184')->id)->where('movement_type', 'sale')->count());
        $this->assertSame('sold', $this->pack('PK-000184')->status);
    }

    /** U26 dan U30: daging sudah dibayar di kasir retail; hak layanan tidak bisa dipakai ulang. */
    public function test_u26_u30_paid_meat_uses_entitlement_once(): void
    {
        $retail = $this->newOrder();
        $this->orders()->addPack($retail, 'PK-000191', $this->cashier);
        $retail = $this->payAndFinalize($retail);

        $resto = $this->newOrder('dine_in');
        $this->cooking()->requestFromReceipt($resto, $retail->doc_no, 'PK-000191', $this->cashier, ['portions' => 1]);
        $resto->refresh();
        $this->assertSame(1, $resto->lines()->count());
        $this->assertSame('cooking_service', $resto->lines()->first()->line_type);
        $this->assertSame('70000.00', (string) $resto->net_amount); // 1,750 kg x Rp40.000

        $other = $this->newOrder('dine_in');
        $this->assertDomainError('entitlement_used', fn () => $this->cooking()->requestFromReceipt($other, $retail->doc_no, 'PK-000191', $this->cashier, []));
        $this->assertDomainError('pack_not_on_receipt', fn () => $this->cooking()->requestFromReceipt($other, $retail->doc_no, 'PK-000184', $this->cashier, []));
    }

    /** U28: batal setelah mulai masak; daging tidak kembali tersedia. */
    public function test_u28_cancel_after_cooking_does_not_return_meat(): void
    {
        $order = $this->newOrder('dine_in');
        $packLine = $this->orders()->addPack($order, 'PK-000310', $this->cashier);
        $job = $this->cooking()->requestForPackLine($packLine, $this->cashier, []);
        app(KitchenService::class)->send($order);
        app(KitchenService::class)->start(TicketItem::firstOrFail(), $this->cashier);

        $this->assertDomainError('pack_already_cooked', fn () => $this->orders()->voidLine($packLine->fresh(), $this->cashier, 'batal', $this->approval));
        $this->assertDomainError('approval_required', fn () => $this->cooking()->cancel($job, $this->cashier, 'tamu pergi'));

        $tenderloin = Product::where('code', '0100102')->value('id');
        $before = StockLedger::balance($this->outlet->id, $tenderloin);
        $this->cooking()->cancel($job, $this->cashier, 'tamu pergi', $this->approval);

        $this->assertSame('damaged', $this->pack('PK-000310')->status);
        $this->assertSame('cancelled_after_cook', CookingJob::find($job->id)->status);
        $this->assertSame('1.240000', (string) \App\Support\Decimal::of($before)->minus(\App\Support\Decimal::of(StockLedger::balance($this->outlet->id, $tenderloin)))->toScale(6));
        $this->assertSame('0.00', (string) $order->fresh()->net_amount);
    }

    public function test_minimum_fee_applies(): void
    {
        $rule = $this->cooking()->ruleFor($this->outlet->id);
        $this->assertSame('30000.00', $this->cooking()->fee($rule, '0.500', 1)); // 0,5 x 40.000 = 20.000 < minimum
        $this->assertSame('60000.00', $this->cooking()->fee($rule, '1.500', 1));
    }
}
