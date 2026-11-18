<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery\Courier;
use App\Models\Delivery\Customer;
use App\Services\Delivery\Sql\QueryCatalog;
use Illuminate\Support\Facades\DB;

class ReportTest extends DeliveryTestCase
{
    /** Insert a finished order with an exact timeline. */
    private function delivered(int $courierId, string $createdAt, int $minutes, float $fee): void
    {
        $customer = Customer::factory()->create();
        $id = DB::selectOne(
            "INSERT INTO delivery_orders (customer_id, courier_id, status, pickup_location, delivery_location, delivery_fee, created_at, updated_at)
             VALUES (?, ?, 'delivered', ST_SetSRID(ST_MakePoint(49.86, 40.40), 4326)::geography,
                     ST_SetSRID(ST_MakePoint(49.87, 40.41), 4326)::geography, ?, ?::timestamptz, ?::timestamptz)
             RETURNING id",
            [$customer->id, $courierId, $fee, $createdAt, $createdAt],
        )->id;

        DB::insert(
            "INSERT INTO delivery_order_status_history (order_id, old_status, new_status, created_at)
             VALUES (?, 'picked_up', 'delivered', ?::timestamptz + make_interval(mins => ?))",
            [$id, $createdAt, $minutes],
        );
    }

    public function test_daily_report_counts_and_average_delivery_time(): void
    {
        $courier = $this->courierAt(self::LAT, self::LNG);
        $this->delivered($courier->id, '2026-05-10 09:00:00+00', 30, 5.00);
        $this->delivered($courier->id, '2026-05-10 15:00:00+00', 50, 7.00);
        $this->delivered($courier->id, '2026-05-11 10:00:00+00', 20, 3.00);
        $cancelled = $this->pendingOrderAt(self::LAT, self::LNG);
        DB::update("UPDATE delivery_orders SET status = 'cancelled', created_at = '2026-05-11 11:00:00+00' WHERE id = ?", [$cancelled->id]);

        $data = $this->getJson(self::API.'/reports/daily?from=2026-05-10&to=2026-05-12')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame(['2026-05-10', 2, 2, 0, '40.0'], [$data[0]['day'], $data[0]['total_orders'], $data[0]['delivered'], $data[0]['cancelled'], $data[0]['avg_delivery_minutes']]);
        $this->assertSame(['2026-05-11', 2, 1, 1, '20.0'], [$data[1]['day'], $data[1]['total_orders'], $data[1]['delivered'], $data[1]['cancelled'], $data[1]['avg_delivery_minutes']]);
    }

    public function test_monthly_revenue_only_counts_delivered_orders(): void
    {
        $courier = $this->courierAt(self::LAT, self::LNG);
        $this->delivered($courier->id, '2026-04-30 23:59:59+00', 10, 10.00);
        $this->delivered($courier->id, '2026-05-01 00:00:00+00', 10, 4.50);
        $this->delivered($courier->id, '2026-05-20 12:00:00+00', 10, 5.50);
        $this->pendingOrderAt(self::LAT, self::LNG); // pending, never revenue

        $data = $this->getJson(self::API.'/reports/monthly?from=2026-04-01&to=2026-06-01')->assertOk()->json('data');

        $this->assertSame([
            ['month' => '2026-04', 'delivered_orders' => 1, 'revenue' => '10.00', 'avg_fee' => '10.00'],
            ['month' => '2026-05', 'delivered_orders' => 2, 'revenue' => '10.00', 'avg_fee' => '5.00'],
        ], $data);
    }

    public function test_top_couriers_are_ranked_by_delivery_count(): void
    {
        [$a, $b, $c] = Courier::factory()->count(3)->create();
        foreach ([[$b, 3], [$a, 1], [$c, 2]] as [$courier, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $this->delivered($courier->id, '2026-05-10 10:00:00+00', 15, 5.00);
            }
        }

        $data = $this->getJson(self::API.'/reports/top-couriers?limit=2')->assertOk()->json('data');

        $this->assertSame([$b->id, $c->id], array_column($data, 'id'));
        $this->assertSame([3, 2], array_column($data, 'deliveries'));
    }

    /** Every learning query must at least be valid SQL on the real schema. */
    public function test_every_catalog_query_runs(): void
    {
        $courier = $this->courierAt(self::LAT, self::LNG);
        $this->delivered($courier->id, now()->subDay()->toIso8601String(), 25, 20.00);

        foreach (QueryCatalog::all() as $key => $query) {
            DB::select($query['sql']);
            DB::select('EXPLAIN '.$query['sql']);
        }

        $this->assertCount(1, DB::select(QueryCatalog::get('big_spenders_exists')['sql']));
        $this->assertEquals(
            DB::select(QueryCatalog::get('big_spenders_join')['sql']),
            DB::select(QueryCatalog::get('big_spenders_exists')['sql']),
        );
    }
}
