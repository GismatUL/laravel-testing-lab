<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\CourierStatus;
use App\Enums\Delivery\DeliveryOrderStatus;
use App\Exceptions\Delivery\CourierAlreadyBusyException;
use App\Exceptions\Delivery\InvalidStatusTransitionException;
use App\Exceptions\Delivery\NoAvailableCourierException;
use App\Models\Delivery\DeliveryOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Business requirements #4 and #5: assign an order to the nearest available
 * courier, never giving one courier two concurrent orders.
 *
 * THE RACE WE ARE PREVENTING (naive version, no locks):
 *
 *   Request A (order 1)                    Request B (order 2)
 *   ------------------------------------   ------------------------------------
 *   SELECT nearest available -> courier 7
 *                                          SELECT nearest available -> courier 7
 *   UPDATE couriers SET busy WHERE id=7
 *   UPDATE order 1 SET courier 7
 *                                          UPDATE couriers SET busy WHERE id=7
 *                                          UPDATE order 2 SET courier 7   <-- BUG
 *
 * Both SELECTs ran before either UPDATE, so both saw courier 7 as available.
 * This is a classic "check-then-act" race; a transaction alone does NOT fix it
 * under READ COMMITTED (PostgreSQL's default), because plain SELECTs don't lock.
 *
 * THE FIX, in three layers:
 *
 *  1. SELECT ... FOR UPDATE on the order row: two requests for the SAME order
 *     are serialised; the second one waits, then sees status='assigned' and
 *     stops.
 *
 *  2. SELECT ... FOR UPDATE OF c SKIP LOCKED on the courier candidate: two
 *     requests for DIFFERENT orders that want the same courier don't block
 *     each other; the second one simply skips the locked courier and takes the
 *     next-nearest one. (Plain FOR UPDATE is also correct but makes B WAIT
 *     for A's commit, then re-check the row, find it busy and move on: all
 *     requests near the same courier are serialised. SKIP LOCKED is the
 *     standard job-queue pattern.)
 *
 *  3. The partial UNIQUE index delivery_orders_one_active_order_per_courier:
 *     a database-level guarantee that survives bugs in layers 1-2, manual SQL,
 *     other services writing to the same DB, etc.
 */
class CourierAssignmentService
{
    public function __construct(
        private readonly DeliveryOrderService $orders,
    ) {}

    public function assignNearest(int $orderId, int $radiusMeters = CourierService::DEFAULT_RADIUS_METERS): DeliveryOrder
    {
        try {
            // attempts: 3 -> Laravel retries the whole closure if PostgreSQL
            // reports a deadlock (40P01) or serialization failure (40001).
            DB::transaction(fn () => $this->assignInTransaction($orderId, $radiusMeters), attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            throw CourierAlreadyBusyException::forCourier($this->courierIdFrom($e));
        }

        return $this->orders->find($orderId);
    }

    private function assignInTransaction(int $orderId, int $radiusMeters): void
    {
        // Lock #1: the order. Lock order is always order -> courier (same as
        // DeliveryOrderService::transition) to avoid lock-ordering deadlocks.
        /** @var DeliveryOrder $order */
        $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($orderId);

        if ($order->status !== DeliveryOrderStatus::Pending) {
            throw InvalidStatusTransitionException::between($orderId, $order->status, DeliveryOrderStatus::Assigned);
        }

        // Lock #2: the nearest *unlocked* available courier.
        //
        // FOR UPDATE OF c  -> lock only the courier row, not the joined order.
        // SKIP LOCKED      -> silently ignore couriers another transaction is
        //                     currently assigning.
        //
        // Under READ COMMITTED, if a candidate row was updated by a transaction
        // that committed after our snapshot, PostgreSQL re-evaluates the WHERE
        // clause on the newest row version before locking it ("EvalPlanQual"),
        // so a courier that just became 'busy' is filtered out.
        $courier = DB::selectOne(
            "SELECT c.id, ST_Distance(c.current_location, o.pickup_location) AS distance_m
               FROM couriers c
               JOIN delivery_orders o ON o.id = ?
              WHERE c.status = 'available'
                AND ST_DWithin(c.current_location, o.pickup_location, ?)
              ORDER BY distance_m
              LIMIT 1
                FOR UPDATE OF c SKIP LOCKED",
            [$orderId, $radiusMeters],
        );

        if ($courier === null) {
            throw NoAvailableCourierException::withinRadius($orderId, $radiusMeters);
        }

        DB::update(
            'UPDATE couriers SET status = ? WHERE id = ?',
            [CourierStatus::Busy->value, $courier->id],
        );

        DB::update(
            'UPDATE delivery_orders SET status = ?, courier_id = ?, updated_at = now() WHERE id = ?',
            [DeliveryOrderStatus::Assigned->value, $courier->id, $orderId],
        );

        StatusHistory::record($orderId, DeliveryOrderStatus::Pending, DeliveryOrderStatus::Assigned);
    }

    private function courierIdFrom(UniqueConstraintViolationException $e): int
    {
        // PostgreSQL detail: "Key (courier_id)=(42) already exists."
        return preg_match('/\(courier_id\)=\((\d+)\)/', $e->getMessage(), $m) ? (int) $m[1] : 0;
    }
}
