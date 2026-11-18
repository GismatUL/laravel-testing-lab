<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery\Customer;
use Illuminate\Support\Facades\DB;

class ListDeliveryOrdersTest extends DeliveryTestCase
{
    public function test_it_filters_by_status_customer_courier_and_date_range(): void
    {
        $alice = Customer::factory()->create();
        $bob = Customer::factory()->create();
        $courier = $this->courierAt(self::LAT, self::LNG);

        $old = $this->pendingOrderAt(self::LAT, self::LNG, $alice);
        $recent = $this->pendingOrderAt(self::LAT, self::LNG, $alice);
        $bobs = $this->pendingOrderAt(self::LAT, self::LNG, $bob);
        DB::update("UPDATE delivery_orders SET created_at = '2026-01-10 12:00:00+00' WHERE id = ?", [$old->id]);
        DB::update("UPDATE delivery_orders SET created_at = '2026-03-10 12:00:00+00' WHERE id = ?", [$recent->id]);
        DB::update("UPDATE delivery_orders SET status = 'assigned', courier_id = ? WHERE id = ?", [$courier->id, $bobs->id]);

        $ids = fn (array $query) => array_column($this->getJson(self::API.'/orders?'.http_build_query($query))->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$old->id, $recent->id], $ids(['customer_id' => $alice->id]));
        $this->assertSame([$bobs->id], $ids(['status' => 'assigned']));
        $this->assertSame([$bobs->id], $ids(['courier_id' => $courier->id]));
        $this->assertSame([$recent->id], $ids(['customer_id' => $alice->id, 'from' => '2026-03-01', 'to' => '2026-04-01']));
    }

    public function test_it_uses_cursor_pagination_newest_first(): void
    {
        $customer = Customer::factory()->create();
        $orders = collect(range(1, 5))->map(fn () => $this->pendingOrderAt(self::LAT, self::LNG, $customer));

        $page1 = $this->getJson(self::API.'/orders?per_page=2')->assertOk();
        $this->assertSame([$orders[4]->id, $orders[3]->id], array_column($page1->json('data'), 'id'));

        $page2 = $this->getJson($page1->json('links.next'))->assertOk();
        $this->assertSame([$orders[2]->id, $orders[1]->id], array_column($page2->json('data'), 'id'));

        // Filters must survive pagination too.
        $filtered = $this->getJson(self::API."/orders?per_page=1&customer_id={$customer->id}")->assertOk();
        $this->assertStringContainsString("customer_id={$customer->id}", $filtered->json('links.next'));
    }

    public function test_it_rejects_unknown_status(): void
    {
        $this->getJson(self::API.'/orders?status=lost')->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
