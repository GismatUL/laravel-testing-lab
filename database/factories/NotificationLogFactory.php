<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $order = Order::query()->inRandomOrder()->first() ?? Order::factory()->create();

        return [
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'type' => 'order_paid',
            'channel' => 'email',
            'recipient' => $order->user->email,
            'status' => 'pending',
            'message' => fake()->sentence(),
            'payload' => null,
            'sent_at' => null,
            'failure_reason' => null,
        ];
    }
}
