<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 20, 1000);

        return [
            'user_id'      => User::factory(),
            'order_number' => 'ORD-' . now()->format('YmdHis') . '-' . Str::random(6),
            'status'       => OrderStatus::Pending,
            'subtotal'     => $subtotal,
            'total'        => $subtotal,
            'paid_at'      => null,
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status'  => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
