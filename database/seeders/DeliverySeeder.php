<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * php artisan db:seed --class=DeliverySeeder
 *
 * Loads ~100k delivery orders with set-based SQL (see database/sql/delivery_seed.sql
 * for the explanation of why this is not done with Eloquent factories).
 */
class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        $started = microtime(true);

        DB::unprepared(file_get_contents(database_path('sql/delivery_seed.sql')));

        // ANALYZE: refresh planner statistics (row counts, most-common values,
        //   histograms). Right after a bulk load the planner would otherwise
        //   estimate with stale numbers and pick bad plans.
        // VACUUM: marks pages "all-visible" in the visibility map. Without it,
        //   an Index Only Scan still has to visit the heap for every row
        //   ("Heap Fetches: N" in EXPLAIN) and loses its advantage.
        // Autovacuum would do both eventually; we do it now so EXPLAIN output
        // is representative immediately.
        foreach (['customers', 'couriers', 'delivery_orders', 'delivery_order_status_history'] as $table) {
            DB::statement("VACUUM ANALYZE {$table}");
        }

        $counts = DB::selectOne('
            SELECT (SELECT count(*) FROM customers)                     AS customers,
                   (SELECT count(*) FROM couriers)                      AS couriers,
                   (SELECT count(*) FROM delivery_orders)               AS orders,
                   (SELECT count(*) FROM delivery_order_status_history) AS history
        ');

        $this->command?->info(sprintf(
            'Seeded %d customers, %d couriers, %d orders, %d history rows in %.1fs',
            $counts->customers,
            $counts->couriers,
            $counts->orders,
            $counts->history,
            microtime(true) - $started,
        ));
    }
}
