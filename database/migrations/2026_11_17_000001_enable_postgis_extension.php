<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task02 – Courier Management (PostGIS).
 *
 * PostGIS is a PostgreSQL *extension*: it adds the geometry/geography types,
 * hundreds of ST_* functions and GiST operator classes for spatial indexing.
 * The extension binaries must exist on the server (we use the postgis/postgis
 * Docker image); CREATE EXTENSION only registers them in this database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // The courier module is PostgreSQL-only by design.
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        // Intentionally empty: DROP EXTENSION postgis CASCADE would also drop
        // every geography column in the database.
    }
};
