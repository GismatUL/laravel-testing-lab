<?php

namespace App\Models\Delivery\Concerns;

use App\Support\GeoPoint;
use Illuminate\Database\Eloquent\Builder;

/**
 * PostgreSQL returns geography values as hex-encoded EWKB
 * (e.g. "0101000020E6100000..."), which is useless to PHP.
 * Instead of decoding WKB in PHP we ask PostGIS for the coordinates:
 *
 *   ST_Y(col::geometry) AS col_lat,  ST_X(col::geometry) AS col_lng
 *
 * (ST_X/ST_Y are defined for geometry, hence the cast.)
 */
trait HasGeographyColumns
{
    /** @return list<string> */
    abstract protected function geographyColumns(): array;

    public function scopeWithCoordinates(Builder $query): Builder
    {
        $table = $this->getTable();

        if ($query->getQuery()->columns === null) {
            $query->select("{$table}.*");
        }

        foreach ($this->geographyColumns() as $column) {
            $query->selectRaw(
                "ST_Y({$table}.{$column}::geometry) AS {$column}_lat, ST_X({$table}.{$column}::geometry) AS {$column}_lng"
            );
        }

        return $query;
    }

    public function point(string $column): ?GeoPoint
    {
        $lat = $this->getAttribute("{$column}_lat");
        $lng = $this->getAttribute("{$column}_lng");

        return $lat === null || $lng === null ? null : new GeoPoint((float) $lat, (float) $lng);
    }
}
