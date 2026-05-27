<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::query()->inRandomOrder()->value('id') ?? User::factory(),
            'order_number' => 'ORD-' . now()->format('YmdHis') . '-' . fake()->unique()->numberBetween(1000, 9999),
            'status' => 'pending',
            'subtotal' => fake()->randomFloat(2, 20, 1000),
            'total' => fake()->randomFloat(2, 20, 1000),
            'paid_at' => null,
            'cancelled_at' => null,
        ];
    }
}
