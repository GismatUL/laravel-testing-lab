-- =============================================================================
-- Task02 bulk seed: ~20k customers, 10k couriers, ~102k orders, ~350k history
-- =============================================================================
-- Why raw SQL and not Eloquent factories?
--   Factories do one INSERT per row (+ PHP object hydration). 100k orders with
--   history would be ~450k round-trips and take minutes. A set-based
--   INSERT ... SELECT FROM generate_series() runs entirely inside PostgreSQL
--   and finishes in a few seconds. Rule of thumb: think in SETS, not loops.
--
-- All coordinates are around Baku (lat 40.4093, lng 49.8671).
-- REMEMBER: ST_MakePoint(longitude, latitude)  -- X first, then Y!
-- =============================================================================

-- Deterministic random(): the same seed produces the same dataset, so the
-- EXPLAIN numbers in docs/ are reproducible on your machine.
SELECT setseed(0.42);

TRUNCATE delivery_order_status_history, delivery_orders, couriers, customers
    RESTART IDENTITY CASCADE;

-- -----------------------------------------------------------------------------
-- 1. Customers (20,000). Customers 18,001..20,000 will never place an order
--    (exercise: "customers who have never placed an order").
-- -----------------------------------------------------------------------------
INSERT INTO customers (name, email, created_at)
SELECT 'Customer ' || g,
       'customer' || g || '@example.com',
       now() - random() * interval '400 days'
FROM generate_series(1, 20000) AS g;

-- 151 duplicate customers (same email, different letter case) to practise
-- "find duplicate customer records based on email".
INSERT INTO customers (name, email, created_at)
SELECT 'Customer ' || g || ' (dup)',
       upper('customer' || g || '@example.com'),
       now() - random() * interval '30 days'
FROM generate_series(1, 20000, 133) AS g;      -- every 133rd customer

-- -----------------------------------------------------------------------------
-- 2. Couriers (10,000): ~55% available, ~25% busy, ~20% offline.
--    Spread over roughly a 26 km x 25 km box around the city centre.
-- -----------------------------------------------------------------------------
INSERT INTO couriers (name, status, current_location, last_location_update, created_at)
SELECT 'Courier ' || g,
       CASE WHEN r < 0.55 THEN 'available'
            WHEN r < 0.80 THEN 'busy'
            ELSE 'offline' END,
       ST_SetSRID(ST_MakePoint(49.8671 + (random() - 0.5) * 0.30,      -- lng (X)
                               40.4093 + (random() - 0.5) * 0.24),     -- lat (Y)
                  4326)::geography,
       now() - random() * interval '10 minutes',
       now() - random() * interval '2 years'
FROM (SELECT g, random() AS r FROM generate_series(1, 10000) AS g) s;

-- -----------------------------------------------------------------------------
-- 3. Historical orders (100,000): 85% delivered, 7% cancelled, 8% pending.
--    Courier ids are skewed with power(random(), 2) so a few couriers are much
--    busier than others (makes "top 10 busiest couriers" meaningful).
-- -----------------------------------------------------------------------------
INSERT INTO delivery_orders
    (customer_id, courier_id, status, pickup_location, delivery_location,
     delivery_fee, created_at, updated_at)
SELECT 1 + floor(random() * 18000)::int,
       CASE WHEN status = 'delivered' THEN 1 + floor(power(random(), 2) * 10000)::int END,
       status,
       ST_SetSRID(ST_MakePoint(plng, plat), 4326)::geography,
       ST_SetSRID(ST_MakePoint(plng + (random() - 0.5) * 0.08,
                               plat + (random() - 0.5) * 0.06), 4326)::geography,
       round((2 + random() * 18)::numeric, 2),
       created_at,
       created_at
FROM (
    SELECT CASE WHEN r < 0.85 THEN 'delivered'
                WHEN r < 0.92 THEN 'cancelled'
                ELSE 'pending' END AS status,
           -- pending orders are recent; finished ones span the last year
           CASE WHEN r < 0.92 THEN now() - random() * interval '365 days'
                ELSE now() - random() * interval '2 hours' END AS created_at,
           49.8671 + (random() - 0.5) * 0.30 AS plng,
           40.4093 + (random() - 0.5) * 0.24 AS plat
    FROM (SELECT random() AS r FROM generate_series(1, 100000)) x
) o;

-- -----------------------------------------------------------------------------
-- 4. One ACTIVE order per busy courier (respects the partial unique index
--    delivery_orders_one_active_order_per_courier).
-- -----------------------------------------------------------------------------
INSERT INTO delivery_orders
    (customer_id, courier_id, status, pickup_location, delivery_location,
     delivery_fee, created_at, updated_at)
SELECT 1 + floor(random() * 18000)::int,
       c.id,
       CASE WHEN random() < 0.5 THEN 'assigned' ELSE 'picked_up' END,
       c.current_location,
       ST_SetSRID(ST_MakePoint(ST_X(c.current_location::geometry) + (random() - 0.5) * 0.08,
                               ST_Y(c.current_location::geometry) + (random() - 0.5) * 0.06),
                  4326)::geography,
       round((2 + random() * 18)::numeric, 2),
       now() - random() * interval '30 minutes',
       now()
FROM couriers c
WHERE c.status = 'busy';

-- -----------------------------------------------------------------------------
-- 5. Status history. Timings are generated once per order in a CTE so that
--    all rows of one order share the same timeline.
-- -----------------------------------------------------------------------------
WITH t AS (
    SELECT id, status, created_at,
           created_at + (2  + random() * 8)  * interval '1 minute' AS t_assigned,
           (5  + random() * 15) * interval '1 minute'               AS d_pick,
           (10 + random() * 40) * interval '1 minute'               AS d_deliver,
           (1  + random() * 20) * interval '1 minute'               AS d_cancel
    FROM delivery_orders
)
INSERT INTO delivery_order_status_history (order_id, old_status, new_status, created_at)
-- every order starts as pending
SELECT id, NULL, 'pending', created_at FROM t
UNION ALL
SELECT id, 'pending', 'assigned', t_assigned FROM t
 WHERE status IN ('assigned', 'picked_up', 'delivered')
UNION ALL
SELECT id, 'assigned', 'picked_up', t_assigned + d_pick FROM t
 WHERE status IN ('picked_up', 'delivered')
UNION ALL
SELECT id, 'picked_up', 'delivered', t_assigned + d_pick + d_deliver FROM t
 WHERE status = 'delivered'
UNION ALL
SELECT id, 'pending', 'cancelled', created_at + d_cancel FROM t
 WHERE status = 'cancelled';

-- Keep updated_at consistent with the last transition.
UPDATE delivery_orders o
   SET updated_at = h.last_at
  FROM (SELECT order_id, max(created_at) AS last_at
          FROM delivery_order_status_history GROUP BY order_id) h
 WHERE h.order_id = o.id;

-- -----------------------------------------------------------------------------
-- 6. VACUUM ANALYZE runs AFTER this script (see DeliverySeeder.php) because
--    VACUUM cannot run inside a transaction block, and a multi-statement
--    string sent in one round-trip is executed as one implicit transaction.
-- -----------------------------------------------------------------------------
