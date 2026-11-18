<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_list_products(): void
    {
        Product::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_products_can_be_filtered_by_search(): void
    {
        Product::factory()->create(['name' => 'Laptop']);
        Product::factory()->create(['name' => 'Phone']);

        $response = $this->getJson('/api/v1/products?search=Laptop');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_products_can_be_filtered_by_category(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);
        Product::factory()->create();

        $response = $this->getJson("/api/v1/products?category_id={$category->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_anyone_can_view_a_product(): void
    {
        $product = Product::factory()->create();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id);
    }

    public function test_authenticated_user_can_create_product(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/products', [
            'name'  => 'New Product',
            'price' => 99.99,
            'stock' => 10,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Product');
    }

    public function test_create_product_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name'  => 'New Product',
            'price' => 99.99,
            'stock' => 10,
        ]);

        $response->assertStatus(401);
    }

    public function test_create_product_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/products', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'price', 'stock']);
    }

    public function test_authenticated_user_can_update_product(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        $response = $this->actingAs($user)->putJson("/api/v1/products/{$product->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_authenticated_user_can_delete_product(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        $response = $this->actingAs($user)->deleteJson("/api/v1/products/{$product->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
