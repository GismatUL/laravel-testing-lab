<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'name'        => ucfirst($name),
            'slug'        => Str::slug($name),
            'description' => fake()->paragraph(),
            'price'       => fake()->randomFloat(2, 5, 500),
            'stock'       => fake()->numberBetween(10, 100),
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(['stock' => 0]);
    }

    public function withStock(int $stock): static
    {
        return $this->state(['stock' => $stock]);
    }
}
