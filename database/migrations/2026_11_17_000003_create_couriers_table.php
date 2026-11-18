<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status', 20)->default('offline');

            // geography(Point, 4326):
            //  - "geography" = coordinates on the WGS84 spheroid; distance
            //    functions return METRES, not degrees.
            //  - SRID 4326 = WGS84, the coordinate system GPS uses.
            //  - Nullable: a newly registered courier has not sent GPS yet.
            $table->geography('current_location', subtype: 'point', srid: 4326)->nullable();
            $table->timestampTz('last_location_update')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // CHECK constraint instead of a native PostgreSQL ENUM type:
        // changing allowed values is a simple DROP/ADD CONSTRAINT, while enum
        // values can never be removed (only added with ALTER TYPE).
        DB::statement("
            ALTER TABLE couriers
            ADD CONSTRAINT couriers_status_check
            CHECK (status IN ('available', 'busy', 'offline'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
