<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(User $user): int
    {
        $product = Product::factory()->withStock(10)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => 1,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        return $response->json('data.id');
    }

    public function test_user_can_pay_for_order(): void
    {
        $user    = User::factory()->create();
        $orderId = $this->createOrder($user);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payments");

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'amount', 'status']]);
    }

    public function test_payment_amount_matches_order_total(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create(['price' => 50.00]);

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => 2,
        ]);

        $orderResponse = $this->actingAs($user)->postJson('/api/v1/orders');
        $orderId = $orderResponse->json('data.id');

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payments");

        $response->assertStatus(201)
            ->assertJsonPath('data.amount', '100.00');
    }

    public function test_cannot_pay_for_already_paid_order(): void
    {
        $user    = User::factory()->create();
        $orderId = $this->createOrder($user);

        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payments");

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payments");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Order is already paid.');
    }

    public function test_idempotent_payment_returns_existing_payment(): void
    {
        $user    = User::factory()->create();
        $orderId = $this->createOrder($user);

        $first  = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payments", [
            'idempotency_key' => 'unique-key-123',
        ]);

        $second = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payments", [
            'idempotency_key' => 'unique-key-123',
        ]);

        $this->assertEquals($first->json('data.id'), $second->json('data.id'));
    }

    public function test_user_cannot_pay_for_another_users_order(): void
    {
        $user      = User::factory()->create();
        $otherUser = User::factory()->create();
        $orderId   = $this->createOrder($otherUser);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payments");

        $response->assertStatus(403);
    }
}
