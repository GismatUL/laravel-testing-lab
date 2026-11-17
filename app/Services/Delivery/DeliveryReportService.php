<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\DB;

/**
 * Business requirements #8, #9, #10. Reporting is where raw SQL shines:
 * aggregates, FILTER clauses and date_trunc() are clearer in SQL than in a
 * chain of Eloquent calls, and we never want to hydrate 100k models in PHP
 * just to sum a column.
 *
 * Time zone note: date_trunc('day', timestamptz) truncates in the SESSION
 * time zone (Laravel's pgsql connection uses UTC by default). If the business
 * reports in Asia/Baku time you would write
 *   date_trunc('day', created_at AT TIME ZONE 'Asia/Baku')
 * and an index on created_at could no longer help the GROUP BY directly.
 */
class DeliveryReportService
{
    /**
     * #8 Daily statistics incl. average delivery time.
     *
     * Delivery time = delivered timestamp (from the history table) minus the
     * order creation time. Conditional aggregation (FILTER) computes several
     * counts in a SINGLE pass over the rows instead of one query per status.
     *
     * @return list<object>
     */
    public function daily(string $from, string $to): array
    {
        return DB::select(<<<'SQL'
            SELECT date_trunc('day', o.created_at)::date                         AS day,
                   count(*)                                                       AS total_orders,
                   count(*) FILTER (WHERE o.status = 'delivered')                 AS delivered,
                   count(*) FILTER (WHERE o.status = 'cancelled')                 AS cancelled,
                   count(*) FILTER (WHERE o.status IN ('pending', 'assigned', 'picked_up')) AS in_progress,
                   round(avg(EXTRACT(EPOCH FROM (h.created_at - o.created_at)) / 60)::numeric, 1)
                                                                                  AS avg_delivery_minutes
              FROM delivery_orders o
              LEFT JOIN delivery_order_status_history h
                     ON h.order_id = o.id
                    AND h.new_status = 'delivered'
             WHERE o.created_at >= ?
               AND o.created_at <  ?
             GROUP BY 1
             ORDER BY 1
        SQL, [$from, $to]);
    }

    /**
     * #9 Revenue per month (only delivered orders earn money).
     *
     * @return list<object>
     */
    public function monthly(string $from, string $to): array
    {
        return DB::select(<<<'SQL'
            SELECT to_char(date_trunc('month', created_at), 'YYYY-MM') AS month,
                   count(*)                                           AS delivered_orders,
                   sum(delivery_fee)                                  AS revenue,
                   round(avg(delivery_fee), 2)                        AS avg_fee
              FROM delivery_orders
             WHERE status = 'delivered'
               AND created_at >= ?
               AND created_at <  ?
             GROUP BY date_trunc('month', created_at)
             ORDER BY date_trunc('month', created_at)
        SQL, [$from, $to]);
    }

    /**
     * #10 Top N busiest couriers.
     *
     * Aggregate FIRST (in the derived table), JOIN after: we join 10 rows to
     * couriers instead of joining ~87k delivered orders and then grouping.
     *
     * @return list<object>
     */
    public function topCouriers(int $limit = 10): array
    {
        return DB::select(<<<'SQL'
            SELECT c.id, c.name, c.status, t.deliveries, t.revenue
              FROM (SELECT courier_id,
                           count(*)          AS deliveries,
                           sum(delivery_fee) AS revenue
                      FROM delivery_orders
                     WHERE status = 'delivered'
                     GROUP BY courier_id
                     ORDER BY deliveries DESC
                     LIMIT ?) t
              JOIN couriers c ON c.id = t.courier_id
             ORDER BY t.deliveries DESC, c.id
        SQL, [$limit]);
    }
}
