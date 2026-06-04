<?php

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id'          => Order::factory(),
            'payment_number'    => 'PAY-' . now()->format('YmdHis') . '-' . Str::random(6),
            'provider'          => PaymentProvider::Mock,
            'status'            => PaymentStatus::Success,
            'amount'            => fake()->randomFloat(2, 20, 1000),
            'currency'          => 'AZN',
            'idempotency_key'   => Str::uuid()->toString(),
            'provider_response' => ['message' => 'Mock payment successful'],
            'paid_at'           => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state([
            'status'            => PaymentStatus::Failed,
            'paid_at'           => null,
            'provider_response' => ['message' => 'Mock payment failed'],
        ]);
    }
}
