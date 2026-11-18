<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

/**
 * Immutable WGS84 coordinate.
 *
 * The constructor takes (latitude, longitude) because that is how humans and
 * the API speak, but PostGIS ALWAYS wants X = longitude, Y = latitude.
 * Keeping that conversion in exactly one place (SQL + bindings(), toExpression()) is how you
 * avoid the #1 PostGIS bug: swapped coordinates that put Baku in southern Russia.
 */
final class GeoPoint
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
    ) {
        if ($lat < -90 || $lat > 90) {
            throw new InvalidArgumentException("Latitude out of range: {$lat}");
        }
        if ($lng < -180 || $lng > 180) {
            throw new InvalidArgumentException("Longitude out of range: {$lng}");
        }
    }

    /**
     * SQL fragment with two placeholders, longitude first.
     *
     *   ST_MakePoint(x, y)        -> geometry POINT without SRID
     *   ST_SetSRID(..., 4326)     -> tag it as WGS84
     *   ::geography               -> spheroidal maths, results in metres
     */
    public const SQL = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';

    /** @return array{0: float, 1: float} bindings for self::SQL */
    public function bindings(): array
    {
        return [$this->lng, $this->lat];
    }

    /** Inline expression for Eloquent inserts/updates (values are floats, safe to inline). */
    public function toExpression(): ExpressionContract
    {
        return new Expression(sprintf(
            'ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography',
            $this->lng,
            $this->lat,
        ));
    }

    /** @return array{lat: float, lng: float} */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
