<?php

namespace Tests\Feature\Delivery;

use Illuminate\Support\Facades\DB;

class PendingOrdersInAreaTest extends DeliveryTestCase
{
    public function test_bounding_box_returns_only_pending_orders_inside_it(): void
    {
        $inside = $this->pendingOrderAt(40.40, 49.86);
        $this->pendingOrderAt(40.50, 49.86);                 // north of the box
        $this->pendingOrderAt(40.40, 49.95);                 // east of the box
        $cancelled = $this->pendingOrderAt(40.401, 49.861);
        DB::update("UPDATE delivery_orders SET status = 'cancelled' WHERE id = ?", [$cancelled->id]);

        $response = $this->getJson(self::API.'/orders/pending-in-area?'.http_build_query([
            'min_lat' => 40.39, 'min_lng' => 49.85, 'max_lat' => 40.42, 'max_lng' => 49.88,
        ]))->assertOk();

        $this->assertSame([$inside->id], array_column($response->json('data'), 'id'));
    }

    public function test_circle_search_respects_radius_and_orders_by_distance(): void
    {
        $near = $this->pendingOrderAt(self::LAT + 0.005, self::LNG);  // ~555 m
        $mid = $this->pendingOrderAt(self::LAT + 0.009, self::LNG);   // ~999 m
        $this->pendingOrderAt(self::LAT + 0.0095, self::LNG);         // ~1,055 m

        $response = $this->getJson(self::API.'/orders/pending-in-area?'.http_build_query([
            'lat' => self::LAT, 'lng' => self::LNG, 'radius' => 1000,
        ]))->assertOk();

        $this->assertSame([$near->id, $mid->id], array_column($response->json('data'), 'id'));
    }

    public function test_it_requires_a_box_or_a_circle(): void
    {
        $this->getJson(self::API.'/orders/pending-in-area')->assertUnprocessable();
    }
}
