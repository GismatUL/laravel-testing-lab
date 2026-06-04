<?php

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Cart>
 */
class CartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status'  => CartStatus::Active,
        ];
    }

    public function converted(): static
    {
        return $this->state(['status' => CartStatus::Converted]);
    }
}
