<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Cash\ShiftService;
use App\Models\Shift;

class ShiftTest extends AcceptanceTestCase
{
    public function test_shift_close_with_large_difference_needs_approval(): void
    {
        $order = $this->newOrder();
        $this->orders()->addPack($order, 'PK-000184', $this->cashier);
        $this->payAndFinalize($order);
        $shift = Shift::where('user_id', $this->cashier->id)->firstOrFail();
        $service = app(ShiftService::class);

        $this->assertDomainError('reason_required', fn () => $service->cashMovement($shift, $this->cashier, 'cash_out', '10000', ' '));
        $service->cashMovement($shift, $this->cashier, 'cash_out', '50000', 'beli es batu');
        $this->assertSame('750000.00', $service->expected($shift)['cash']); // 500.000 + 300.000 - 50.000

        $this->assertDomainError('approval_required', fn () => $service->close($shift, $this->cashier, ['cash' => '700000']));
        $closed = $service->close($shift, $this->cashier, ['cash' => '700000'], $this->approval);
        $this->assertSame('-50000.00', (string) $closed->cash_difference);
    }
}
