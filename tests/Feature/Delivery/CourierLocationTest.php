<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery\Courier;

class CourierLocationTest extends DeliveryTestCase
{
    public function test_it_updates_the_courier_location_and_timestamp(): void
    {
        $courier = $this->courierAt(self::LAT, self::LNG);
        Courier::query()->whereKey($courier->id)->update(['last_location_update' => now()->subHour()]);

        $this->patchJson(self::API."/couriers/{$courier->id}/location", ['lat' => 40.3777, 'lng' => 49.8920])
            ->assertOk()
            ->assertJsonPath('data.current_location.lat', 40.3777)
            ->assertJsonPath('data.current_location.lng', 49.892);

        $fresh = Courier::query()->withCoordinates()->find($courier->id);
        $this->assertEqualsWithDelta(40.3777, $fresh->point('current_location')->lat, 1e-9);
        $this->assertTrue($fresh->last_location_update->greaterThan(now()->subMinute()));
    }

    public function test_it_rejects_invalid_coordinates(): void
    {
        $courier = $this->courierAt(self::LAT, self::LNG);

        $this->patchJson(self::API."/couriers/{$courier->id}/location", ['lat' => -91, 'lng' => 200])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_unknown_courier_returns_404(): void
    {
        $this->patchJson(self::API.'/couriers/999999/location', ['lat' => self::LAT, 'lng' => self::LNG])
            ->assertNotFound();
    }
}
