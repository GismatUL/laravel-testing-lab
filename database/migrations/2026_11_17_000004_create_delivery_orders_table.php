<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Named "delivery_orders" (not "orders") because this project already has an
 * e-commerce `orders` table. Same concept, different bounded context.
 *
 * This migration deliberately creates NO performance indexes: only the primary
 * key, foreign keys, CHECK constraints and the one UNIQUE index that is
 * required for *correctness*. Performance indexes live in
 * 2026_11_17_000010_add_delivery_performance_indexes.php so you can roll back
 * that single migration and compare EXPLAIN ANALYZE before/after.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            // PostgreSQL does NOT automatically index foreign key columns
            // (MySQL/InnoDB does). We add those indexes later, consciously.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->geography('pickup_location', subtype: 'point', srid: 4326);
            $table->geography('delivery_location', subtype: 'point', srid: 4326);
            $table->decimal('delivery_fee', 10, 2);
            // NOT NULL (unlike Laravel's default timestamps()): every report
            // groups by created_at, so NULLs would silently drop rows.
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("
            ALTER TABLE delivery_orders
                ADD CONSTRAINT delivery_orders_status_check
                    CHECK (status IN ('pending', 'assigned', 'picked_up', 'delivered', 'cancelled')),
                ADD CONSTRAINT delivery_orders_fee_check
                    CHECK (delivery_fee >= 0),
                -- An order that is (or was) in a courier's hands must have one.
                ADD CONSTRAINT delivery_orders_courier_required_check
                    CHECK (status IN ('pending', 'cancelled') OR courier_id IS NOT NULL)
        ");

        // Business rule #5 enforced by the DATABASE, not only by PHP:
        // "a courier can have at most one active order".
        //
        // A *partial unique index* only contains rows matching its WHERE
        // clause, so delivered/cancelled history does not count. Even if two
        // PHP processes race past every application-level check, the second
        // UPDATE fails with SQLSTATE 23505 (unique_violation).
        DB::statement("
            CREATE UNIQUE INDEX delivery_orders_one_active_order_per_courier
                ON delivery_orders (courier_id)
                WHERE status IN ('assigned', 'picked_up')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};
