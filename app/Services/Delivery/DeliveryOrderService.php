<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\CourierStatus;
use App\Enums\Delivery\DeliveryOrderStatus;
use App\Exceptions\Delivery\InvalidStatusTransitionException;
use App\Models\Delivery\DeliveryOrder;
use App\Support\GeoPoint;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;

class DeliveryOrderService
{
    /** Pricing: base fee + per-km fee on the straight-line distance. */
    public const BASE_FEE = 2.00;

    public const FEE_PER_KM = 0.80;

    /**
     * Business requirement #1: create a delivery order.
     *
     * Raw SQL (what actually runs):
     *
     *   INSERT INTO delivery_orders (customer_id, status, pickup_location, delivery_location, delivery_fee)
     *   SELECT ?, 'pending', p.g, d.g, round(2.00 + 0.80 * ST_Distance(p.g, d.g) / 1000, 2)
     *   FROM (SELECT ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography AS g) p,
     *        (SELECT ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography AS g) d
     *   RETURNING id;
     *
     * The fee is computed by PostGIS in the same statement: ST_Distance on
     * geography returns metres along the WGS84 spheroid, so no Haversine in PHP.
     *
     * The order row and its first history row are written in ONE transaction:
     * either both exist or neither does (atomicity).
     */
    public function create(int $customerId, GeoPoint $pickup, GeoPoint $dropoff): DeliveryOrder
    {
        $orderId = DB::transaction(function () use ($customerId, $pickup, $dropoff) {
            $row = DB::selectOne(
                'INSERT INTO delivery_orders (customer_id, status, pickup_location, delivery_location, delivery_fee)
                 SELECT ?, ?, p.g, d.g, round((? + ? * ST_Distance(p.g, d.g) / 1000)::numeric, 2)
                 FROM (SELECT '.GeoPoint::SQL.' AS g) p,
                      (SELECT '.GeoPoint::SQL.' AS g) d
                 RETURNING id',
                [
                    $customerId,
                    DeliveryOrderStatus::Pending->value,
                    self::BASE_FEE,
                    self::FEE_PER_KM,
                    ...$pickup->bindings(),
                    ...$dropoff->bindings(),
                ],
            );

            StatusHistory::record($row->id, null, DeliveryOrderStatus::Pending);

            return $row->id;
        });

        return $this->find($orderId);
    }

    public function find(int $orderId): DeliveryOrder
    {
        return DeliveryOrder::query()->withCoordinates()->findOrFail($orderId);
    }

    /**
     * Business requirement #6: list orders with filters.
     *
     * Uses KEYSET (cursor) pagination instead of OFFSET:
     *
     *   OFFSET: ... ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 50000
     *           -> PostgreSQL must produce and throw away 50,000 rows. Page N
     *              costs O(N).
     *   KEYSET: ... WHERE (created_at, id) < (:last_created_at, :last_id)
     *           ORDER BY created_at DESC, id DESC LIMIT 20
     *           -> an index seek straight to the next page. Every page costs
     *              the same.
     *
     * `id` is the tie-breaker so the ordering is total (created_at is not unique).
     *
     * @param  array{status?: string, customer_id?: int, courier_id?: int, from?: string, to?: string}  $filters
     */
    public function list(array $filters, int $perPage = 20): CursorPaginator
    {
        return DeliveryOrder::query()
            ->withCoordinates()
            ->filter($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString(); // keep filters + per_page in the next/prev links
    }

    /**
     * Move an order through its state machine. When an order reaches a final
     * state its courier becomes available again.
     *
     * Locking order (ALWAYS the same in every code path, see
     * CourierAssignmentService): 1) the order row, 2) the courier row.
     * Acquiring locks in a consistent global order is the #1 way to avoid
     * deadlocks.
     */
    public function transition(int $orderId, DeliveryOrderStatus $next): DeliveryOrder
    {
        DB::transaction(function () use ($orderId, $next) {
            /** @var DeliveryOrder $order */
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($orderId);
            $current = $order->status;

            // "assigned" needs a courier, so it only happens through
            // CourierAssignmentService::assignNearest().
            if ($next === DeliveryOrderStatus::Assigned || ! $current->canTransitionTo($next)) {
                throw InvalidStatusTransitionException::between($orderId, $current, $next);
            }

            $order->status = $next;
            $order->save();

            if ($next->isFinal() && $order->courier_id !== null) {
                DB::update(
                    'UPDATE couriers SET status = ? WHERE id = ? AND status = ?',
                    [CourierStatus::Available->value, $order->courier_id, CourierStatus::Busy->value],
                );
            }

            StatusHistory::record($orderId, $current, $next);
        });

        return $this->find($orderId);
    }
}
