<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\CourierStatus;
use App\Models\Delivery\Courier;
use App\Support\GeoPoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CourierService
{
    public const DEFAULT_RADIUS_METERS = 5000;

    /**
     * Business requirement #2: update the courier's GPS position.
     *
     *   UPDATE couriers
     *      SET current_location = ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
     *          last_location_update = now()
     *    WHERE id = :id;
     *
     * This is the hottest write path in a courier system (every courier pings
     * every few seconds). Keep it a single-row UPDATE by primary key. Note that
     * every index on current_location must be updated on each ping: that is
     * the write-cost side of the GiST index trade-off.
     */
    public function updateLocation(Courier $courier, GeoPoint $point): Courier
    {
        DB::update(
            'UPDATE couriers SET current_location = '.GeoPoint::SQL.', last_location_update = now() WHERE id = ?',
            [...$point->bindings(), $courier->id],
        );

        return Courier::query()->withCoordinates()->findOrFail($courier->id);
    }

    /**
     * Business requirement #3: nearest available couriers within a radius.
     *
     * Raw SQL:
     *
     *   SELECT c.*, ST_Distance(c.current_location, :point) AS distance_m
     *     FROM couriers c
     *    WHERE c.status = 'available'
     *      AND ST_DWithin(c.current_location, :point, 5000)
     *    ORDER BY distance_m
     *    LIMIT 5;
     *
     * Why ST_DWithin and NOT  `ST_Distance(...) < 5000`?
     *   ST_DWithin is "index-aware": PostGIS rewrites it into
     *   `current_location && ST_Expand(point, 5000)` (a bounding-box test the
     *   GiST index can answer) PLUS the exact distance check on the few
     *   candidates. ST_Distance(...) < 5000 is just a function call on every
     *   row: always a sequential scan computing 10,000 spheroid distances.
     *
     * @return Collection<int, Courier> each with a `distance_m` attribute
     */
    public function findNearestAvailable(GeoPoint $point, int $radiusMeters = self::DEFAULT_RADIUS_METERS, int $limit = 5): Collection
    {
        return Courier::query()
            ->withCoordinates()
            ->selectRaw('ST_Distance(couriers.current_location, '.GeoPoint::SQL.') AS distance_m', $point->bindings())
            ->where('status', CourierStatus::Available->value)
            ->whereRaw('ST_DWithin(couriers.current_location, '.GeoPoint::SQL.', ?)', [...$point->bindings(), $radiusMeters])
            ->orderBy('distance_m')
            ->limit($limit)
            ->get();
    }
}
