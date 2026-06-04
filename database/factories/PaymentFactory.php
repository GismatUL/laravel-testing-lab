<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::query()->inRandomOrder()->first() ?? Order::factory()->create(),
            'payment_number' => 'PAY-' . now()->format('YmdHis') . '-' . fake()->unique()->numberBetween(1000, 9999),
            'provider' => 'mock',
            'status' => 'pending',
            'amount' => fake()->randomFloat(2, 20, 1000),
            'currency' => 'AZN',
            'idempotency_key' => (string) Str::uuid(),
            'provider_response' => null,
            'paid_at' => null,
        ];
    }
}
