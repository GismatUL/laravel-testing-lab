<?php

namespace Tests\Feature\Delivery;

use App\Enums\Delivery\CourierStatus;

class NearestCourierTest extends DeliveryTestCase
{
    public function test_it_returns_available_couriers_ordered_by_distance(): void
    {
        $far = $this->courierAt(self::LAT + 0.02, self::LNG);   // ~2.2 km
        $near = $this->courierAt(self::LAT + 0.005, self::LNG); // ~0.55 km
        $mid = $this->courierAt(self::LAT + 0.01, self::LNG);   // ~1.1 km

        $response = $this->getJson(self::API.'/couriers/nearest?'.http_build_query(['lat' => self::LAT, 'lng' => self::LNG]))
            ->assertOk();

        $this->assertSame([$near->id, $mid->id, $far->id], array_column($response->json('data'), 'id'));
        $this->assertEqualsWithDelta(555, $response->json('data.0.distance_m'), 5);
    }

    public function test_busy_and_offline_couriers_are_ignored(): void
    {
        $this->courierAt(self::LAT + 0.001, self::LNG, CourierStatus::Busy);
        $this->courierAt(self::LAT + 0.002, self::LNG, CourierStatus::Offline);
        $available = $this->courierAt(self::LAT + 0.03, self::LNG);

        $this->getJson(self::API.'/couriers/nearest?'.http_build_query(['lat' => self::LAT, 'lng' => self::LNG]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->id);
    }

    public function test_radius_is_enforced_in_metres(): void
    {
        // 1 degree of latitude at 40.4 N ~= 111,034 m along the WGS84 meridian.
        $inside = $this->courierAt(self::LAT + 0.044, self::LNG);  // ~4,885 m
        $this->courierAt(self::LAT + 0.046, self::LNG);            // ~5,108 m

        $this->getJson(self::API.'/couriers/nearest?'.http_build_query(['lat' => self::LAT, 'lng' => self::LNG, 'radius' => 5000]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inside->id);
    }

    public function test_it_requires_coordinates(): void
    {
        $this->getJson(self::API.'/couriers/nearest')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);
    }
}
