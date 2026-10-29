<?php

namespace Tests\Feature\Acceptance;

class PosHttpTest extends AcceptanceTestCase
{
    public function test_touch_pos_flow_over_http(): void
    {
        $this->post('/pos/login', ['user_id' => $this->cashier->id, 'pin' => '0000'])->assertSessionHasErrors('pin');
        $this->post('/pos/login', ['user_id' => $this->cashier->id, 'pin' => '1111'])->assertRedirect('/pos');

        $this->get('/pos')->assertOk()->assertSee('Rasentra POS');
        $this->postJson('/pos/api/orders')->assertStatus(422)->assertJsonPath('error', 'shift_required');
        $this->postJson('/pos/api/shift/open', ['opening_cash' => 500000])->assertCreated();

        $orderId = $this->postJson('/pos/api/orders')->assertCreated()->json('order.id');
        $this->postJson("/pos/api/orders/{$orderId}/scan", ['code' => 'PK-000184'])->assertOk()->assertJsonPath('order.gross_amount', '300000.00');
        $this->postJson("/pos/api/orders/{$orderId}/scan", ['code' => 'PK-000184'])->assertStatus(409)->assertJsonPath('error', 'pack_already_in_cart');
        $this->postJson("/pos/api/orders/{$orderId}/scan", ['code' => '8991234500011'])->assertOk();

        $due = $this->getJson("/pos/api/orders/{$orderId}")->json('remaining');
        $this->postJson("/pos/api/orders/{$orderId}/payments", ['method' => 'cash', 'amount' => $due, 'tendered' => 400000])
            ->assertOk()->assertJsonPath('payments.0.change_amount', '68000.00');
        $this->postJson("/pos/api/orders/{$orderId}/finalize")->assertOk()->assertJsonPath('order.status', 'paid');

        $this->getJson('/pos/api/catalog?category=Daging%20Sapi')->assertOk()->assertJsonFragment(['name' => 'Sirloin Australia']);
        $this->get('/')->assertOk()->assertSee('Rasentra');
    }
}
