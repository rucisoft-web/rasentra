<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Cash\ShiftService;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\PaymentService;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Basis uji skenario UAT (Bab 27 dan 28 spesifikasi). Memakai data contoh outlet
 * Pangkalan Jati (PJT): Sirloin Rp200.000/kg, pack PK-000184 1,500 kg dan PK-000191 1,750 kg.
 */
abstract class AcceptanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected Outlet $outlet;

    protected User $cashier;

    protected User $head;

    protected array $approval = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([\Database\Seeders\OutletSeeder::class, \Database\Seeders\DemoSeeder::class]);
        $this->outlet = Outlet::where('code', 'PJT')->firstOrFail();
        $this->cashier = User::where('email', 'kasir@pjt.test')->firstOrFail();
        $this->head = User::where('email', 'kepala@pjt.test')->firstOrFail();
        $this->approval = ['approver_id' => $this->head->id, 'pin' => '9999'];
    }

    protected function orders(): OrderService
    {
        return app(OrderService::class);
    }

    protected function newOrder(string $channel = 'retail', ?User $user = null): Order
    {
        $user ??= $this->cashier;
        $shift = \App\Models\Shift::where('user_id', $user->id)->where('status', 'open')->first()
            ?? app(ShiftService::class)->open($user, $this->outlet->id, '500000');

        return $this->orders()->create($user, $this->outlet->id, $channel, ['shift_id' => $shift->id]);
    }

    protected function payAndFinalize(Order $order): Order
    {
        $order->refresh();
        app(PaymentService::class)->add($order, 'cash', (string) $order->net_amount, ['tendered' => (string) $order->net_amount]);

        return $this->orders()->finalize($order, $this->cashier);
    }

    protected function assertDomainError(string $code, callable $fn): \App\Support\DomainException
    {
        try {
            $fn();
        } catch (\App\Support\DomainException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        $this->fail("Diharapkan error {$code}");
    }

    protected function pack(string $code): Pack
    {
        return Pack::where('pack_code', $code)->firstOrFail();
    }
}
