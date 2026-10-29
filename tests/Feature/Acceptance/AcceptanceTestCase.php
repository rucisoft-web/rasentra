<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Cash\ShiftService;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\PaymentService;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\Shift;
use App\Models\User;
use App\Support\DomainException;
use Database\Seeders\DemoSeeder;
use Database\Seeders\OutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Basis uji skenario UAT (Bab 27 dan 28 spesifikasi). Memakai data contoh outlet
 * Pangkalan Jati (UJI): Sirloin Rp200.000/kg, pack PK-000184 1,500 kg dan PK-000191 1,750 kg.
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
        $this->seed([OutletSeeder::class, DemoSeeder::class]);
        $this->outlet = Outlet::where('code', 'UJI')->firstOrFail();
        $this->cashier = User::where('email', 'kasir@uji.test')->firstOrFail();
        $this->head = User::where('email', 'kepala@uji.test')->firstOrFail();
        $this->approval = ['approver_id' => $this->head->id, 'pin' => '9999'];
    }

    protected function orders(): OrderService
    {
        return app(OrderService::class);
    }

    protected function newOrder(string $channel = 'retail', ?User $user = null): Order
    {
        $user ??= $this->cashier;
        $shift = Shift::where('user_id', $user->id)->where('status', 'open')->first()
            ?? app(ShiftService::class)->open($user, $this->outlet->id, '500000');

        return $this->orders()->create($user, $this->outlet->id, $channel, ['shift_id' => $shift->id]);
    }

    protected function payAndFinalize(Order $order): Order
    {
        $order->refresh();
        app(PaymentService::class)->add($order, 'cash', (string) $order->net_amount, ['tendered' => (string) $order->net_amount]);

        return $this->orders()->finalize($order, $this->cashier);
    }

    protected function assertDomainError(string $code, callable $fn): DomainException
    {
        try {
            $fn();
        } catch (DomainException $e) {
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
