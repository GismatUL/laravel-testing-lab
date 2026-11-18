<?php

namespace Tests\Feature\Delivery;

use App\Enums\Delivery\CourierStatus;
use App\Models\Delivery\Courier;
use App\Models\Delivery\Customer;
use App\Models\Delivery\DeliveryOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base class for the courier module's integration tests.
 *
 * These tests MUST run on PostgreSQL + PostGIS: SQLite has no geography type,
 * no ST_* functions, no GiST, no partial unique indexes with the same
 * semantics, no SELECT ... FOR UPDATE SKIP LOCKED. A green SQLite run would
 * prove nothing about the real queries.
 */
abstract class DeliveryTestCase extends TestCase
{
    use RefreshDatabase;

    /** Baku city centre. 0.01 degrees of latitude ~= 1,110 m. */
    protected const LAT = 40.4093;

    protected const LNG = 49.8671;

    protected const API = '/api/v1/delivery';

    /**
     * RefreshDatabase hook, runs BEFORE migrate:fresh. Guarding here (not in
     * setUp after parent::setUp()) is what actually protects the dev data.
     */
    protected function beforeRefreshingDatabase(): void
    {
        static::guardTestingDatabase();
    }

    public static function guardTestingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            static::markTestSkipped('Courier module tests require PostgreSQL + PostGIS.');
        }

        if (! str_ends_with(DB::getDatabaseName(), '_testing')) {
            throw new \RuntimeException(sprintf(
                "Refusing to run against database '%s': tests must use a *_testing database (see phpunit.xml).",
                DB::getDatabaseName(),
            ));
        }
    }

    protected function courierAt(float $lat, float $lng, CourierStatus $status = CourierStatus::Available): Courier
    {
        return Courier::factory()->at($lat, $lng)->status($status)->create();
    }

    protected function pendingOrderAt(float $lat, float $lng, ?Customer $customer = null): DeliveryOrder
    {
        return DeliveryOrder::factory()
            ->for($customer ?? Customer::factory()->create())
            ->pickupAt($lat, $lng)
            ->create();
    }
}
