<?php

namespace Tests\Feature\Api\V1;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_empty_cart(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertStatus(200)
            ->assertJsonPath('data.items_count', 0);
    }

    public function test_user_can_add_item_to_cart(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => 2,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.items_count', 1);
    }

    public function test_adding_same_product_accumulates_quantity(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => 2,
        ]);

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => 3,
        ]);

        $cart = $user->carts()->latest()->first()->load('items');
        $this->assertEquals(5, $cart->items->first()->quantity);
    }

    public function test_add_item_validates_product_exists(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => 9999,
            'quantity'   => 1,
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_remove_item_from_cart(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => 1,
        ]);

        $item = $user->carts()->latest()->first()->items()->first();

        $response = $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$item->id}");

        $response->assertStatus(204);
    }

    public function test_user_cannot_remove_another_users_cart_item(): void
    {
        $user      = User::factory()->create();
        $otherUser = User::factory()->create();
        $product   = Product::factory()->withStock(10)->create();

        $this->actingAs($otherUser)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => 1,
        ]);

        $item = $otherUser->carts()->latest()->first()->items()->first();

        $response = $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$item->id}");

        $response->assertStatus(403);
    }

    public function test_user_can_clear_cart(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity'   => 1,
        ]);

        $response = $this->actingAs($user)->deleteJson('/api/v1/cart');

        $response->assertStatus(204);
    }
}
