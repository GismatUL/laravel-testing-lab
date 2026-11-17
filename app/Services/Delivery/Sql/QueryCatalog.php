<?php

namespace App\Services\Delivery\Sql;

/**
 * Every raw SQL query used for learning, in one place, so that
 *
 *   php artisan delivery:sql                      -> lists them
 *   php artisan delivery:sql top_customers        -> runs one, prints rows
 *   php artisan delivery:sql top_customers -e     -> EXPLAIN (ANALYZE, BUFFERS)
 *   php artisan delivery:sql --group=optimization --explain --markdown
 *                                                 -> before/after report
 *
 * Values are inlined as literals (not bindings) on purpose: EXPLAIN output is
 * then exactly what you would see when pasting the query into psql.
 *
 * Group "advanced"     = Task02 section 5 (Advanced SQL exercises).
 * Group "optimization" = Task02 section 4 (queries whose plans change when
 *                        the performance-index migration is applied).
 */
final class QueryCatalog
{
    /** @return array<string, array{group: string, title: string, sql: string}> */
    public static function all(): array
    {
        return self::advanced() + self::optimization();
    }

    /** @return array{group: string, title: string, sql: string}|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, array{group: string, title: string, sql: string}> */
    public static function advanced(): array
    {
        $q = fn (string $title, string $sql) => ['group' => 'advanced', 'title' => $title, 'sql' => trim($sql)];

        return [
            'top_customers' => $q('#1 Top 5 customers by total spending', <<<'SQL'
                SELECT c.id, c.name, c.email,
                       count(*)            AS delivered_orders,
                       sum(o.delivery_fee) AS total_spent
                  FROM delivery_orders o
                  JOIN customers c ON c.id = o.customer_id
                 WHERE o.status = 'delivered'
                 GROUP BY c.id              -- c.name/c.email are functionally dependent on the PK
                 ORDER BY total_spent DESC, c.id
                 LIMIT 5
                SQL),

            'customers_without_orders' => $q('#2 Customers who never placed an order (anti-join)', <<<'SQL'
                SELECT c.id, c.name, c.email
                  FROM customers c
                 WHERE NOT EXISTS (SELECT 1 FROM delivery_orders o WHERE o.customer_id = c.id)
                 ORDER BY c.id
                 LIMIT 20
                SQL),

            'couriers_above_average' => $q('#3 Couriers whose delivery count is above the average', <<<'SQL'
                WITH per_courier AS (
                    SELECT courier_id, count(*) AS deliveries
                      FROM delivery_orders
                     WHERE status = 'delivered'
                     GROUP BY courier_id
                )
                SELECT c.id, c.name, p.deliveries,
                       round((SELECT avg(deliveries) FROM per_courier), 2) AS average
                  FROM per_courier p
                  JOIN couriers c ON c.id = p.courier_id
                 WHERE p.deliveries > (SELECT avg(deliveries) FROM per_courier)
                 ORDER BY p.deliveries DESC, c.id
                 LIMIT 20
                SQL),

            'daily_delivery_stats' => $q('#4 Daily delivery statistics (GROUP BY)', <<<'SQL'
                SELECT created_at::date                AS day,
                       count(*)                        AS deliveries,
                       sum(delivery_fee)               AS revenue,
                       round(avg(delivery_fee), 2)     AS avg_fee,
                       count(DISTINCT courier_id)      AS active_couriers
                  FROM delivery_orders
                 WHERE status = 'delivered'
                   AND created_at >= current_date - 14
                 GROUP BY created_at::date
                 ORDER BY day
                SQL),

            'courier_monthly_rank' => $q('#5 Rank couriers by monthly delivery count (window functions)', <<<'SQL'
                WITH monthly AS (
                    SELECT date_trunc('month', created_at)::date AS month,
                           courier_id,
                           count(*) AS deliveries
                      FROM delivery_orders
                     WHERE status = 'delivered'
                       AND created_at >= date_trunc('month', now()) - interval '2 months'
                     GROUP BY 1, 2
                ), ranked AS (
                    SELECT month, courier_id, deliveries,
                           ROW_NUMBER() OVER w AS row_number,  -- 1,2,3,4 (ties broken arbitrarily)
                           RANK()       OVER w AS rank,        -- 1,2,2,4 (gaps after ties)
                           DENSE_RANK() OVER w AS dense_rank   -- 1,2,2,3 (no gaps)
                      FROM monthly
                    WINDOW w AS (PARTITION BY month ORDER BY deliveries DESC)
                )
                SELECT * FROM ranked
                 WHERE rank <= 3
                 ORDER BY month, rank, courier_id
                SQL),

            'latest_status_per_order' => $q('#6 Latest status of every order (DISTINCT ON)', <<<'SQL'
                SELECT DISTINCT ON (order_id)
                       order_id, new_status AS latest_status, created_at AS changed_at
                  FROM delivery_order_status_history
                 ORDER BY order_id, created_at DESC, id DESC
                 LIMIT 20
                SQL),

            'duplicate_customer_emails' => $q('#7 Duplicate customers by email (case-insensitive)', <<<'SQL'
                SELECT lower(email)              AS email,
                       count(*)                  AS copies,
                       array_agg(id ORDER BY id) AS customer_ids
                  FROM customers
                 GROUP BY lower(email)
                HAVING count(*) > 1
                 ORDER BY copies DESC, email
                 LIMIT 20
                SQL),

            'courier_performance_cte' => $q('#8 Courier performance report, last 30 days (CTEs)', <<<'SQL'
                WITH recent AS (            -- step 1: the slice of data we care about
                    SELECT id, courier_id, status, delivery_fee, created_at
                      FROM delivery_orders
                     WHERE created_at >= now() - interval '30 days'
                       AND courier_id IS NOT NULL
                ), delivery_minutes AS (    -- step 2: how long each delivery took
                    SELECT r.courier_id,
                           EXTRACT(EPOCH FROM (h.created_at - r.created_at)) / 60 AS minutes
                      FROM recent r
                      JOIN delivery_order_status_history h
                        ON h.order_id = r.id AND h.new_status = 'delivered'
                ), volume AS (              -- step 3: volume & revenue per courier
                    SELECT courier_id, count(*) AS deliveries, sum(delivery_fee) AS revenue
                      FROM recent
                     WHERE status = 'delivered'
                     GROUP BY courier_id
                ), speed AS (               -- step 4: speed per courier
                    SELECT courier_id, round(avg(minutes)::numeric, 1) AS avg_minutes
                      FROM delivery_minutes
                     GROUP BY courier_id
                )
                SELECT c.id, c.name, v.deliveries, v.revenue, s.avg_minutes
                  FROM volume v
                  JOIN speed s    USING (courier_id)
                  JOIN couriers c ON c.id = v.courier_id
                 ORDER BY v.deliveries DESC, s.avg_minutes
                 LIMIT 10
                SQL),

            'status_counts' => $q('#9 Conditional aggregation: delivered / pending / cancelled per month', <<<'SQL'
                SELECT to_char(date_trunc('month', created_at), 'YYYY-MM')         AS month,
                       count(*)                                                    AS total,
                       count(*) FILTER (WHERE status = 'delivered')                AS delivered,
                       count(*) FILTER (WHERE status = 'pending')                  AS pending,
                       count(*) FILTER (WHERE status = 'cancelled')                AS cancelled,
                       -- the portable (MySQL-compatible) spelling of the same idea:
                       sum(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)       AS cancelled_case,
                       round(100.0 * count(*) FILTER (WHERE status = 'cancelled') / count(*), 2)
                                                                                   AS cancel_rate_pct
                  FROM delivery_orders
                 WHERE created_at >= date_trunc('month', now()) - interval '5 months'
                 GROUP BY date_trunc('month', created_at)
                 ORDER BY 1
                SQL),

            'big_spenders_join' => $q('#10a Customers with a delivered order > 19.50 (JOIN + DISTINCT)', <<<'SQL'
                SELECT DISTINCT c.id, c.name
                  FROM customers c
                  JOIN delivery_orders o ON o.customer_id = c.id
                 WHERE o.status = 'delivered'
                   AND o.delivery_fee > 19.50
                 ORDER BY c.id
                SQL),

            'big_spenders_exists' => $q('#10b Same question with EXISTS (semi-join)', <<<'SQL'
                SELECT c.id, c.name
                  FROM customers c
                 WHERE EXISTS (SELECT 1
                                 FROM delivery_orders o
                                WHERE o.customer_id = c.id
                                  AND o.status = 'delivered'
                                  AND o.delivery_fee > 19.50)
                 ORDER BY c.id
                SQL),
        ];
    }

    /** @return array<string, array{group: string, title: string, sql: string}> */
    public static function optimization(): array
    {
        $q = fn (string $title, string $sql) => ['group' => 'optimization', 'title' => $title, 'sql' => trim($sql)];
        $baku = "ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326)::geography";

        return [
            'list_latest' => $q('List: newest 20 orders (default API listing)', <<<'SQL'
                SELECT id, customer_id, courier_id, status, delivery_fee, created_at
                  FROM delivery_orders
                 ORDER BY created_at DESC, id DESC
                 LIMIT 20
                SQL),

            'list_by_status' => $q('List: newest 20 pending orders', <<<'SQL'
                SELECT id, customer_id, courier_id, status, delivery_fee, created_at
                  FROM delivery_orders
                 WHERE status = 'pending'
                 ORDER BY created_at DESC, id DESC
                 LIMIT 20
                SQL),

            'list_by_customer' => $q('List: newest orders of one customer', <<<'SQL'
                SELECT id, courier_id, status, delivery_fee, created_at
                  FROM delivery_orders
                 WHERE customer_id = 4242
                 ORDER BY created_at DESC
                 LIMIT 20
                SQL),

            'list_by_courier' => $q('List: newest orders of one courier', <<<'SQL'
                SELECT id, customer_id, status, delivery_fee, created_at
                  FROM delivery_orders
                 WHERE courier_id = 1234
                 ORDER BY created_at DESC
                 LIMIT 20
                SQL),

            'date_filter_non_sargable' => $q('ANTI-PATTERN: function on the indexed column', <<<'SQL'
                SELECT count(*)
                  FROM delivery_orders
                 WHERE created_at::date = current_date - 10
                SQL),

            'date_filter_sargable' => $q('FIX: half-open range on the bare column', <<<'SQL'
                SELECT count(*)
                  FROM delivery_orders
                 WHERE created_at >= current_date - 10
                   AND created_at <  current_date - 9
                SQL),

            'offset_pagination' => $q('ANTI-PATTERN: deep OFFSET pagination (page 2,501)', <<<'SQL'
                SELECT id, status, created_at
                  FROM delivery_orders
                 ORDER BY created_at DESC, id DESC
                OFFSET 50000
                 LIMIT 20
                SQL),

            'keyset_pagination' => $q('FIX: keyset (cursor) pagination - same depth', <<<'SQL'
                SELECT id, status, created_at
                  FROM delivery_orders
                 WHERE (created_at, id) < (now() - interval '180 days', 0)
                 ORDER BY created_at DESC, id DESC
                 LIMIT 20
                SQL),

            'nearest_courier_distance_filter' => $q('ANTI-PATTERN: ST_Distance(...) < radius in WHERE', <<<SQL
                SELECT id, ST_Distance(current_location, {$baku}) AS distance_m
                  FROM couriers
                 WHERE status = 'available'
                   AND ST_Distance(current_location, {$baku}) < 5000
                 ORDER BY distance_m
                 LIMIT 5
                SQL),

            'nearest_courier_dwithin' => $q('FIX: ST_DWithin (index-aware radius search)', <<<SQL
                SELECT id, ST_Distance(current_location, {$baku}) AS distance_m
                  FROM couriers
                 WHERE status = 'available'
                   AND ST_DWithin(current_location, {$baku}, 5000)
                 ORDER BY distance_m
                 LIMIT 5
                SQL),

            'nearest_courier_knn' => $q('ALTERNATIVE: KNN ordering with <-> (no radius)', <<<SQL
                SELECT id, current_location <-> {$baku} AS distance_m
                  FROM couriers
                 WHERE status = 'available'
                 ORDER BY current_location <-> {$baku}
                 LIMIT 5
                SQL),

            'pending_in_box' => $q('Pending orders inside a map rectangle', <<<'SQL'
                SELECT id, created_at
                  FROM delivery_orders
                 WHERE status = 'pending'
                   AND ST_Intersects(pickup_location,
                                     ST_MakeEnvelope(49.84, 40.39, 49.89, 40.43, 4326)::geography)
                 ORDER BY created_at
                SQL),

            'monthly_revenue' => $q('Monthly revenue (covering index -> Index Only Scan)', <<<'SQL'
                SELECT date_trunc('month', created_at) AS month,
                       count(*)                        AS delivered_orders,
                       sum(delivery_fee)               AS revenue
                  FROM delivery_orders
                 WHERE status = 'delivered'
                   AND created_at >= now() - interval '6 months'
                 GROUP BY 1
                 ORDER BY 1
                SQL),

            'top_couriers' => $q('Top 10 busiest couriers', <<<'SQL'
                SELECT c.id, c.name, t.deliveries
                  FROM (SELECT courier_id, count(*) AS deliveries
                          FROM delivery_orders
                         WHERE status = 'delivered'
                         GROUP BY courier_id
                         ORDER BY deliveries DESC
                         LIMIT 10) t
                  JOIN couriers c ON c.id = t.courier_id
                 ORDER BY t.deliveries DESC
                SQL),

            'order_history' => $q('Status history of one order (FK lookup)', <<<'SQL'
                SELECT old_status, new_status, created_at
                  FROM delivery_order_status_history
                 WHERE order_id = 50000
                 ORDER BY created_at
                SQL),

            'customer_by_email' => $q('Case-insensitive email lookup (expression index)', <<<'SQL'
                SELECT id, name, email
                  FROM customers
                 WHERE lower(email) = lower('Customer777@example.com')
                SQL),

            'daily_report' => $q('GET /reports/daily for the last 30 days', <<<'SQL'
                SELECT date_trunc('day', o.created_at)::date             AS day,
                       count(*)                                          AS total_orders,
                       count(*) FILTER (WHERE o.status = 'delivered')    AS delivered,
                       round(avg(EXTRACT(EPOCH FROM (h.created_at - o.created_at)) / 60)::numeric, 1)
                                                                         AS avg_delivery_minutes
                  FROM delivery_orders o
                  LEFT JOIN delivery_order_status_history h
                         ON h.order_id = o.id AND h.new_status = 'delivered'
                 WHERE o.created_at >= now() - interval '30 days'
                 GROUP BY 1
                 ORDER BY 1
                SQL),
        ];
    }
}
