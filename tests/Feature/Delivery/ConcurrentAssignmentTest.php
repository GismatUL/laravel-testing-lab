<?php

namespace Tests\Feature\Delivery;

use App\Enums\Delivery\CourierStatus;
use App\Exceptions\Delivery\NoAvailableCourierException;
use App\Models\Delivery\Courier;
use App\Models\Delivery\Customer;
use App\Models\Delivery\DeliveryOrder;
use App\Services\Delivery\CourierAssignmentService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrency needs COMMITTED data visible to a second session, so this class
 * cannot use RefreshDatabase (which hides everything inside one uncommitted
 * transaction). DatabaseTruncation commits for real and truncates afterwards.
 *
 * "Second session" = a second PDO connection to the same database, exactly
 * what a second PHP-FPM worker handling a parallel request would have.
 * For a real multi-process run see: php artisan delivery:race-demo stress
 */
class ConcurrentAssignmentTest extends TestCase
{
    use DatabaseTruncation;

    protected $tablesToTruncate = ['delivery_order_status_history', 'delivery_orders', 'couriers', 'customers'];

    private const LAT = 40.4093;

    private const LNG = 49.8671;

    private Connection $other;

    /** DatabaseTruncation hook: runs before any table is touched. */
    protected function beforeTruncatingDatabase(): void
    {
        DeliveryTestCase::guardTestingDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.pgsql_other' => config('database.connections.pgsql')]);
        $this->other = DB::connection('pgsql_other');
    }

    protected function tearDown(): void
    {
        while (isset($this->other) && $this->other->transactionLevel() > 0) {
            $this->other->rollBack();
        }
        DB::purge('pgsql_other');

        // DatabaseTruncation only truncates BEFORE each test, so the committed
        // rows of the last test here would leak into later RefreshDatabase tests.
        DB::statement('TRUNCATE '.implode(', ', $this->tablesToTruncate).' RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    public function test_skip_locked_gives_the_next_courier_when_the_nearest_is_being_assigned(): void
    {
        $nearest = Courier::factory()->at(self::LAT + 0.001, self::LNG)->create();
        $second = Courier::factory()->at(self::LAT + 0.003, self::LNG)->create();
        $order = $this->order();

        // Session B is in the middle of assigning the nearest courier: it holds
        // the row lock but has not committed yet.
        $this->other->beginTransaction();
        $this->other->select('SELECT id FROM couriers WHERE id = ? FOR UPDATE', [$nearest->id]);

        // Session A (our service) must not wait and must not take that courier.
        $started = microtime(true);
        $assigned = app(CourierAssignmentService::class)->assignNearest($order->id);

        $this->assertSame($second->id, $assigned->courier_id);
        $this->assertLessThan(1.0, microtime(true) - $started, 'Assignment must not block on the locked row');

        $this->other->rollBack();
        $this->assertSame(CourierStatus::Available, $nearest->fresh()->status);
    }

    public function test_when_all_candidates_are_locked_the_request_fails_fast_instead_of_waiting(): void
    {
        $only = Courier::factory()->at(self::LAT + 0.001, self::LNG)->create();
        $order = $this->order();

        $this->other->beginTransaction();
        $this->other->select('SELECT id FROM couriers WHERE id = ? FOR UPDATE', [$only->id]);

        $this->expectException(NoAvailableCourierException::class);
        app(CourierAssignmentService::class)->assignNearest($order->id);
    }

    public function test_two_sessions_assigning_two_orders_never_share_a_courier(): void
    {
        $c1 = Courier::factory()->at(self::LAT + 0.001, self::LNG)->create();
        $c2 = Courier::factory()->at(self::LAT + 0.002, self::LNG)->create();
        [$o1, $o2] = [$this->order(), $this->order()];

        // Session B runs the same locking query as the service and keeps its
        // transaction open while session A runs the real service.
        $this->other->beginTransaction();
        $picked = $this->other->selectOne(
            "SELECT c.id FROM couriers c JOIN delivery_orders o ON o.id = ?
              WHERE c.status = 'available' AND ST_DWithin(c.current_location, o.pickup_location, 5000)
              ORDER BY ST_Distance(c.current_location, o.pickup_location) LIMIT 1
                FOR UPDATE OF c SKIP LOCKED",
            [$o2->id],
        )->id;
        $this->other->update("UPDATE couriers SET status = 'busy' WHERE id = ?", [$picked]);
        $this->other->update("UPDATE delivery_orders SET status = 'assigned', courier_id = ? WHERE id = ?", [$picked, $o2->id]);

        $a = app(CourierAssignmentService::class)->assignNearest($o1->id);
        $this->other->commit();

        $this->assertSame($c1->id, $picked);
        $this->assertSame($c2->id, $a->courier_id);
        $this->assertSame(2, DeliveryOrder::query()->where('status', 'assigned')->distinct()->count('courier_id'));
    }

    private function order(): DeliveryOrder
    {
        return DeliveryOrder::factory()
            ->for(Customer::factory()->create())
            ->pickupAt(self::LAT, self::LNG)
            ->create();
    }
}
