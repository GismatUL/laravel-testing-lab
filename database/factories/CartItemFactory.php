<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CartItem>
 */
class CartItemFactory extends Factory
{
    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 5, 200);
        $quantity  = fake()->numberBetween(1, 5);

        return [
            'cart_id'     => Cart::factory(),
            'product_id'  => Product::factory(),
            'quantity'    => $quantity,
            'unit_price'  => $unitPrice,
            'total_price' => round($unitPrice * $quantity, 2),
        ];
    }
}
