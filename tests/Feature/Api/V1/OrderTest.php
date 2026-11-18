<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function addProductToCart(User $user, Product $product, int $quantity = 1): void
    {
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => $quantity,
        ]);
    }

    public function test_user_can_create_order_from_cart(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->addProductToCart($user, $product, 2);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'order_number', 'total', 'items']]);
    }

    public function test_order_decrements_product_stock(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->addProductToCart($user, $product, 3);
        $this->actingAs($user)->postJson('/api/v1/orders');

        $this->assertEquals(7, $product->fresh()->stock);
    }

    public function test_order_converts_cart_to_converted_status(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->addProductToCart($user, $product);
        $this->actingAs($user)->postJson('/api/v1/orders');

        $cart = $user->carts()->latest()->first();
        $this->assertEquals('converted', $cart->status->value);
    }

    public function test_cannot_create_order_from_empty_cart(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cart is empty.');
    }

    public function test_cannot_create_order_with_insufficient_stock(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(1)->create();

        $this->addProductToCart($user, $product, 1);
        $product->update(['stock' => 0]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $response->assertStatus(422);
    }

    public function test_user_can_view_own_order(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->addProductToCart($user, $product);
        $orderResponse = $this->actingAs($user)->postJson('/api/v1/orders');
        $orderId = $orderResponse->json('data.id');

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$orderId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $orderId);
    }

    public function test_user_cannot_view_another_users_order(): void
    {
        $user      = User::factory()->create();
        $otherUser = User::factory()->create();
        $product   = Product::factory()->withStock(10)->create();

        $this->addProductToCart($otherUser, $product);
        $orderResponse = $this->actingAs($otherUser)->postJson('/api/v1/orders');
        $orderId = $orderResponse->json('data.id');

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$orderId}");

        $response->assertStatus(403);
    }
}
