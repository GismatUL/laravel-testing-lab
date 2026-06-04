<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'   => User::factory(),
            'order_id'  => Order::factory(),
            'type'      => NotificationType::OrderPaid,
            'channel'   => 'email',
            'recipient' => fake()->safeEmail(),
            'status'    => NotificationStatus::Pending,
            'message'   => fake()->sentence(),
            'payload'   => [],
            'sent_at'   => null,
            'failed_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state([
            'status'  => NotificationStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status'    => NotificationStatus::Failed,
            'failed_at' => now(),
        ]);
    }
}
