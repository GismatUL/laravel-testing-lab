<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 – performance indexes, kept in ONE migration so you can compare:
 *
 *   php artisan migrate:rollback --step=1          # BEFORE (no indexes)
 *   php artisan delivery:sql --group=optimization --explain
 *   php artisan migrate                            # AFTER
 *   php artisan delivery:sql --group=optimization --explain
 *
 * Every index below exists because a concrete query in
 * App\Services\Delivery\Sql\QueryCatalog needs it. Every index has a cost:
 * disk space, RAM in shared_buffers, and slower INSERT/UPDATE/DELETE (each
 * write must also update every index). See docs/delivery/LEARNING_GUIDE.md §5.
 *
 * Production note: on a live table use CREATE INDEX CONCURRENTLY (does not
 * block writes, but cannot run inside a transaction, so the migration would
 * need `public $withinTransaction = false;`). For a lab, plain CREATE INDEX
 * is fine and faster.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // 1) B-tree, composite. Serves the default listing
        //    ORDER BY created_at DESC, id DESC LIMIT n (scanned backwards, no
        //    sort node), keyset pagination (created_at, id) < (...), and any
        //    date-range filter. `id` makes the order total for cursors.
        DB::statement('CREATE INDEX delivery_orders_created_at_id_idx
            ON delivery_orders (created_at, id)');

        // 2) + 3) Composite B-tree on the foreign keys.
        //    PostgreSQL does NOT index FKs automatically. Without these,
        //    "orders of customer X" is a full scan, AND every
        //    DELETE FROM customers must scan delivery_orders to check the FK.
        //    Leading column = equality filter, second = sort column, so
        //    WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20 reads
        //    exactly 20 index entries.
        DB::statement('CREATE INDEX delivery_orders_customer_id_created_at_idx
            ON delivery_orders (customer_id, created_at)');
        DB::statement('CREATE INDEX delivery_orders_courier_id_created_at_idx
            ON delivery_orders (courier_id, created_at)');

        // 4) PARTIAL + COVERING B-tree for revenue / delivery reports.
        //    - WHERE status = 'delivered' -> only ~85% of rows today, but the
        //      real point: reports ONLY ever read delivered rows, and the
        //      status column no longer needs to be in the key.
        //    - INCLUDE (delivery_fee, courier_id) -> payload columns stored in
        //      the leaf pages but not part of the key (no ordering cost). The
        //      monthly revenue query can now be answered from the index alone:
        //      "Index Only Scan", zero heap fetches.
        //    Why not a plain index on (status)? 5 distinct values, 'delivered'
        //    is 85% of the table: terrible selectivity, the planner would
        //    (correctly) ignore it and seq-scan.
        DB::statement("CREATE INDEX delivery_orders_delivered_created_at_idx
            ON delivery_orders (created_at) INCLUDE (delivery_fee, courier_id)
            WHERE status = 'delivered'");

        // 5) PARTIAL GiST on pickup_location for pending orders only.
        //    Pending is ~2-8% of rows and every spatial search over orders is
        //    about pending ones ("orders waiting near me"). The partial index is
        //    a fraction of the size of a full one. It only gets used when the
        //    query's WHERE clause implies status = 'pending'.
        //    No index on delivery_location: no query filters on it.
        DB::statement("CREATE INDEX delivery_orders_pending_pickup_gist
            ON delivery_orders USING GIST (pickup_location)
            WHERE status = 'pending'");

        // 6) GiST on courier positions. R-tree-like structure of bounding
        //    boxes: answers "which points are inside this box / within
        //    this distance" (&&, ST_DWithin, ST_Intersects) and KNN (<->).
        //    Full (not partial) on purpose: ~55% of couriers are available,
        //    so a partial index would save little, and status flips
        //    available <-> busy on every assignment would move entries in
        //    and out of the index.
        DB::statement('CREATE INDEX couriers_current_location_gist
            ON couriers USING GIST (current_location)');

        // 7) FK index on history + ordering for "history of order X".
        DB::statement('CREATE INDEX delivery_order_status_history_order_id_created_at_idx
            ON delivery_order_status_history (order_id, created_at)');

        // 8) EXPRESSION index: WHERE lower(email) = lower(?) can only use an
        //    index built on exactly that expression. A plain index on email is
        //    useless once the column is wrapped in a function.
        DB::statement('CREATE INDEX customers_lower_email_idx
            ON customers (lower(email))');

        // Fresh statistics for the new expression index (lower(email)
        // gets its own stats only after ANALYZE).
        DB::statement('ANALYZE customers');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'delivery_orders_created_at_id_idx',
            'delivery_orders_customer_id_created_at_idx',
            'delivery_orders_courier_id_created_at_idx',
            'delivery_orders_delivered_created_at_idx',
            'delivery_orders_pending_pickup_gist',
            'couriers_current_location_gist',
            'delivery_order_status_history_order_id_created_at_idx',
            'customers_lower_email_idx',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
