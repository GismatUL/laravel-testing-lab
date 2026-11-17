<?php

namespace Database\Factories\Delivery;

use App\Enums\Delivery\CourierStatus;
use App\Models\Delivery\Courier;
use App\Support\GeoPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Courier> */
class CourierFactory extends Factory
{
    protected $model = Courier::class;

    public function definition(): array
    {
        return [
            'name'                 => fake()->name(),
            'status'               => CourierStatus::Available,
            'current_location'     => (new GeoPoint(40.4093, 49.8671))->toExpression(),
            'last_location_update' => now(),
        ];
    }

    public function at(float $lat, float $lng): static
    {
        return $this->state(fn () => [
            'current_location' => (new GeoPoint($lat, $lng))->toExpression(),
        ]);
    }

    public function status(CourierStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
