<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\DeliveryOrderStatus;
use App\Models\Delivery\DeliveryOrder;
use App\Support\GeoPoint;
use Illuminate\Support\Collection;

/**
 * Business requirement #7: pending orders inside a geographical area.
 */
class DeliveryAreaService
{
    /**
     * Rectangle (bounding box), e.g. the visible area of a dispatcher's map.
     *
     *   SELECT * FROM delivery_orders
     *    WHERE status = 'pending'
     *      AND ST_Intersects(
     *            pickup_location,
     *            ST_MakeEnvelope(:min_lng, :min_lat, :max_lng, :max_lat, 4326)::geography)
     *    ORDER BY created_at
     *    LIMIT 500;
     *
     * ST_MakeEnvelope(xmin, ymin, xmax, ymax, srid) builds a polygon - again
     * X (longitude) first. ST_Intersects on geography is index-aware: it uses
     * the && bounding-box operator against the GiST index first.
     *
     * Caveat: geography polygon edges are great-circle arcs, not lines of
     * constant latitude. For city-sized boxes the difference is centimetres;
     * for continent-sized boxes, use geometry instead.
     *
     * @return Collection<int, DeliveryOrder>
     */
    public function pendingInBox(float $minLat, float $minLng, float $maxLat, float $maxLng, int $limit = 500): Collection
    {
        return DeliveryOrder::query()
            ->withCoordinates()
            ->where('status', DeliveryOrderStatus::Pending->value)
            ->whereRaw(
                'ST_Intersects(delivery_orders.pickup_location, ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography)',
                [$minLng, $minLat, $maxLng, $maxLat],
            )
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Circle: "pending orders within N metres of me".
     *
     * @return Collection<int, DeliveryOrder> each with `distance_m`
     */
    public function pendingWithinRadius(GeoPoint $center, int $radiusMeters, int $limit = 500): Collection
    {
        return DeliveryOrder::query()
            ->withCoordinates()
            ->selectRaw('ST_Distance(delivery_orders.pickup_location, '.GeoPoint::SQL.') AS distance_m', $center->bindings())
            ->where('status', DeliveryOrderStatus::Pending->value)
            ->whereRaw('ST_DWithin(delivery_orders.pickup_location, '.GeoPoint::SQL.', ?)', [...$center->bindings(), $radiusMeters])
            ->orderBy('distance_m')
            ->limit($limit)
            ->get();
    }
}
