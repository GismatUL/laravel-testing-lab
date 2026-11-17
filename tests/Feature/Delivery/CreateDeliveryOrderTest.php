<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery\Customer;
use Illuminate\Support\Facades\DB;

class CreateDeliveryOrderTest extends DeliveryTestCase
{
    public function test_it_creates_a_pending_order_with_distance_based_fee_and_history(): void
    {
        $customer = Customer::factory()->create();

        // 0.03 degrees of latitude north ~= 3,331 m along the meridian.
        $response = $this->postJson(self::API.'/orders', [
            'customer_id' => $customer->id,
            'pickup'      => ['lat' => self::LAT, 'lng' => self::LNG],
            'delivery'    => ['lat' => self::LAT + 0.03, 'lng' => self::LNG],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.courier_id', null)
            ->assertJsonPath('data.pickup_location.lat', self::LAT)
            ->assertJsonPath('data.pickup_location.lng', self::LNG);

        // fee = 2.00 + 0.80 * 3.331 km = 4.66
        $this->assertEqualsWithDelta(4.66, (float) $response->json('data.delivery_fee'), 0.02);

        $this->assertDatabaseHas('delivery_order_status_history', [
            'order_id'   => $response->json('data.id'),
            'old_status' => null,
            'new_status' => 'pending',
        ]);
    }

    public function test_coordinates_are_stored_longitude_first(): void
    {
        $customer = Customer::factory()->create();

        $id = $this->postJson(self::API.'/orders', [
            'customer_id' => $customer->id,
            'pickup'      => ['lat' => self::LAT, 'lng' => self::LNG],
            'delivery'    => ['lat' => self::LAT, 'lng' => self::LNG + 0.01],
        ])->json('data.id');

        // ST_X = longitude, ST_Y = latitude. If someone swaps the arguments of
        // ST_MakePoint, this point lands in Iran (lat 49.87, lng 40.41).
        $row = DB::selectOne(
            'SELECT ST_X(pickup_location::geometry) AS x, ST_Y(pickup_location::geometry) AS y FROM delivery_orders WHERE id = ?',
            [$id],
        );

        $this->assertEqualsWithDelta(self::LNG, $row->x, 1e-9);
        $this->assertEqualsWithDelta(self::LAT, $row->y, 1e-9);
    }

    public function test_it_validates_input(): void
    {
        $this->postJson(self::API.'/orders', [
            'customer_id' => 999999,
            'pickup'      => ['lat' => 91, 'lng' => self::LNG],
            'delivery'    => ['lng' => 181],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'pickup.lat', 'delivery.lat', 'delivery.lng']);
    }
}
