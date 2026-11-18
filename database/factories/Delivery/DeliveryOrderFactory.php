<?php

namespace Database\Factories\Delivery;

use App\Enums\Delivery\DeliveryOrderStatus;
use App\Models\Delivery\Customer;
use App\Models\Delivery\DeliveryOrder;
use App\Support\GeoPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryOrder> */
class DeliveryOrderFactory extends Factory
{
    protected $model = DeliveryOrder::class;

    public function definition(): array
    {
        return [
            'customer_id'       => Customer::factory(),
            'courier_id'        => null,
            'status'            => DeliveryOrderStatus::Pending,
            'pickup_location'   => (new GeoPoint(40.4093, 49.8671))->toExpression(),
            'delivery_location' => (new GeoPoint(40.3777, 49.8920))->toExpression(),
            'delivery_fee'      => fake()->randomFloat(2, 2, 20),
        ];
    }

    public function pickupAt(float $lat, float $lng): static
    {
        return $this->state(fn () => [
            'pickup_location' => (new GeoPoint($lat, $lng))->toExpression(),
        ]);
    }
}
