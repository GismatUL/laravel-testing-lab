### `list_latest` - List: newest 20 orders (default API listing)

```sql
SELECT id, customer_id, courier_id, status, delivery_fee, created_at
  FROM delivery_orders
 ORDER BY created_at DESC, id DESC
 LIMIT 20
```

```
Limit  (cost=0.42..3.93 rows=20 width=47) (actual time=0.043..0.169 rows=20 loops=1)
  Buffers: shared hit=23
  ->  Index Scan Backward using delivery_orders_created_at_id_idx on delivery_orders  (cost=0.42..18006.58 rows=102411 width=47) (actual time=0.041..0.163 rows=20 loops=1)
        Buffers: shared hit=23
Planning:
  Buffers: shared hit=197
Planning Time: 3.295 ms
Execution Time: 0.356 ms
```

### `list_by_status` - List: newest 20 pending orders

```sql
SELECT id, customer_id, courier_id, status, delivery_fee, created_at
  FROM delivery_orders
 WHERE status = 'pending'
 ORDER BY created_at DESC, id DESC
 LIMIT 20
```

```
Limit  (cost=0.42..46.57 rows=20 width=47) (actual time=0.037..0.240 rows=20 loops=1)
  Buffers: shared hit=59
  ->  Index Scan Backward using delivery_orders_created_at_id_idx on delivery_orders  (cost=0.42..18262.61 rows=7913 width=47) (actual time=0.036..0.235 rows=20 loops=1)
        Filter: ((status)::text = 'pending'::text)
        Rows Removed by Filter: 36
        Buffers: shared hit=59
Planning:
  Buffers: shared hit=11
Planning Time: 0.287 ms
Execution Time: 0.282 ms
```

### `list_by_customer` - List: newest orders of one customer

```sql
SELECT id, courier_id, status, delivery_fee, created_at
  FROM delivery_orders
 WHERE customer_id = 4242
 ORDER BY created_at DESC
 LIMIT 20
```

```
Limit  (cost=27.89..27.91 rows=6 width=39) (actual time=0.217..0.220 rows=8 loops=1)
  Buffers: shared hit=17
  ->  Sort  (cost=27.89..27.91 rows=6 width=39) (actual time=0.215..0.217 rows=8 loops=1)
        Sort Key: created_at DESC
        Sort Method: quicksort  Memory: 25kB
        Buffers: shared hit=17
        ->  Bitmap Heap Scan on delivery_orders  (cost=4.46..27.82 rows=6 width=39) (actual time=0.099..0.143 rows=8 loops=1)
              Recheck Cond: (customer_id = 4242)
              Heap Blocks: exact=8
              Buffers: shared hit=14
              ->  Bitmap Index Scan on delivery_orders_customer_id_created_at_idx  (cost=0.00..4.46 rows=6 width=0) (actual time=0.077..0.078 rows=8 loops=1)
                    Index Cond: (customer_id = 4242)
                    Buffers: shared hit=6
Planning:
  Buffers: shared hit=5
Planning Time: 0.192 ms
Execution Time: 0.307 ms
```

### `list_by_courier` - List: newest orders of one courier

```sql
SELECT id, customer_id, status, delivery_fee, created_at
  FROM delivery_orders
 WHERE courier_id = 1234
 ORDER BY created_at DESC
 LIMIT 20
```

```
Limit  (cost=39.42..39.44 rows=9 width=39) (actual time=0.122..0.125 rows=11 loops=1)
  Buffers: shared hit=14
  ->  Sort  (cost=39.42..39.44 rows=9 width=39) (actual time=0.121..0.123 rows=11 loops=1)
        Sort Key: created_at DESC
        Sort Method: quicksort  Memory: 25kB
        Buffers: shared hit=14
        ->  Bitmap Heap Scan on delivery_orders  (cost=4.49..39.27 rows=9 width=39) (actual time=0.052..0.103 rows=11 loops=1)
              Recheck Cond: (courier_id = 1234)
              Heap Blocks: exact=11
              Buffers: shared hit=14
              ->  Bitmap Index Scan on delivery_orders_courier_id_created_at_idx  (cost=0.00..4.49 rows=9 width=0) (actual time=0.035..0.035 rows=11 loops=1)
                    Index Cond: (courier_id = 1234)
                    Buffers: shared hit=3
Planning Time: 0.109 ms
Execution Time: 0.201 ms
```

### `date_filter_non_sargable` - ANTI-PATTERN: function on the indexed column

```sql
SELECT count(*)
  FROM delivery_orders
 WHERE created_at::date = current_date - 10
```

```
Finalize Aggregate  (cost=3926.65..3926.66 rows=1 width=8) (actual time=16.550..20.861 rows=1 loops=1)
  Buffers: shared hit=391
  ->  Gather  (cost=3926.43..3926.64 rows=2 width=8) (actual time=16.318..20.850 rows=3 loops=1)
        Workers Planned: 2
        Workers Launched: 2
        Buffers: shared hit=391
        ->  Partial Aggregate  (cost=2926.43..2926.44 rows=1 width=8) (actual time=9.131..9.132 rows=1 loops=3)
              Buffers: shared hit=391
              ->  Parallel Index Only Scan using delivery_orders_courier_id_created_at_idx on delivery_orders  (cost=0.42..2925.90 rows=213 width=0) (actual time=0.306..9.109 rows=80 loops=3)
                    Filter: ((created_at)::date = (CURRENT_DATE - 10))
                    Rows Removed by Filter: 34057
                    Heap Fetches: 0
                    Buffers: shared hit=391
Planning:
  Buffers: shared hit=12
Planning Time: 0.287 ms
Execution Time: 20.967 ms
```

### `date_filter_sargable` - FIX: half-open range on the bare column

```sql
SELECT count(*)
  FROM delivery_orders
 WHERE created_at >= current_date - 10
   AND created_at <  current_date - 9
```

```
Aggregate  (cost=9.99..10.00 rows=1 width=8) (actual time=0.196..0.197 rows=1 loops=1)
  Buffers: shared hit=8
  ->  Index Only Scan using delivery_orders_created_at_id_idx on delivery_orders  (cost=0.43..9.37 rows=247 width=0) (actual time=0.134..0.172 rows=240 loops=1)
        Index Cond: ((created_at >= (CURRENT_DATE - 10)) AND (created_at < (CURRENT_DATE - 9)))
        Heap Fetches: 0
        Buffers: shared hit=8
Planning:
  Buffers: shared hit=11
Planning Time: 0.367 ms
Execution Time: 0.258 ms
```

### `offset_pagination` - ANTI-PATTERN: deep OFFSET pagination (page 2,501)

```sql
SELECT id, status, created_at
  FROM delivery_orders
 ORDER BY created_at DESC, id DESC
OFFSET 50000
 LIMIT 20
```

```
Limit  (cost=8791.54..8795.06 rows=20 width=25) (actual time=42.556..42.572 rows=20 loops=1)
  Buffers: shared hit=50378
  ->  Index Scan Backward using delivery_orders_created_at_id_idx on delivery_orders  (cost=0.42..18006.58 rows=102411 width=25) (actual time=0.058..39.486 rows=50020 loops=1)
        Buffers: shared hit=50378
Planning Time: 0.142 ms
Execution Time: 42.648 ms
```

### `keyset_pagination` - FIX: keyset (cursor) pagination - same depth

```sql
SELECT id, status, created_at
  FROM delivery_orders
 WHERE (created_at, id) < (now() - interval '180 days', 0)
 ORDER BY created_at DESC, id DESC
 LIMIT 20
```

```
Limit  (cost=0.42..7.40 rows=20 width=25) (actual time=0.034..0.062 rows=20 loops=1)
  Buffers: shared hit=23
  ->  Index Scan Backward using delivery_orders_created_at_id_idx on delivery_orders  (cost=0.42..16436.06 rows=47136 width=25) (actual time=0.033..0.059 rows=20 loops=1)
        Index Cond: (ROW(created_at, id) < ROW((now() - '180 days'::interval), 0))
        Buffers: shared hit=23
Planning:
  Buffers: shared hit=9
Planning Time: 0.396 ms
Execution Time: 0.161 ms
```

### `nearest_courier_distance_filter` - ANTI-PATTERN: ST_Distance(...) < radius in WHERE

```sql
SELECT id, ST_Distance(current_location, ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326)::geography) AS distance_m
  FROM couriers
 WHERE status = 'available'
   AND ST_Distance(current_location, ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326)::geography) < 5000
 ORDER BY distance_m
 LIMIT 5
```

```
Limit  (cost=148286.53..148286.54 rows=5 width=16) (actual time=63.942..63.946 rows=5 loops=1)
  Buffers: shared hit=364
  ->  Sort  (cost=148286.53..148291.12 rows=1838 width=16) (actual time=53.359..53.361 rows=5 loops=1)
        Sort Key: (st_distance(current_location, '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, true))
        Sort Method: top-N heapsort  Memory: 25kB
        Buffers: shared hit=364
        ->  Seq Scan on couriers  (cost=0.00..148256.00 rows=1838 width=16) (actual time=32.034..53.116 rows=615 loops=1)
              Filter: (((status)::text = 'available'::text) AND (st_distance(current_location, '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, true) < '5000'::double precision))
              Rows Removed by Filter: 9385
              Buffers: shared hit=361
Planning:
  Buffers: shared hit=82
Planning Time: 21.491 ms
JIT:
  Functions: 5
  Options: Inlining false, Optimization false, Expressions true, Deforming true
  Timing: Generation 1.116 ms (Deform 0.338 ms), Inlining 0.000 ms, Optimization 0.464 ms, Emission 10.148 ms, Total 11.729 ms
Execution Time: 109.661 ms
```

### `nearest_courier_dwithin` - FIX: ST_DWithin (index-aware radius search)

```sql
SELECT id, ST_Distance(current_location, ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326)::geography) AS distance_m
  FROM couriers
 WHERE status = 'available'
   AND ST_DWithin(current_location, ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326)::geography, 5000)
 ORDER BY distance_m
 LIMIT 5
```

```
Limit  (cost=25147.11..25147.11 rows=1 width=16) (actual time=6.605..6.608 rows=5 loops=1)
  Buffers: shared hit=157
  ->  Sort  (cost=25147.11..25147.11 rows=1 width=16) (actual time=6.602..6.604 rows=5 loops=1)
        Sort Key: (st_distance(current_location, '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, true))
        Sort Method: top-N heapsort  Memory: 25kB
        Buffers: shared hit=157
        ->  Bitmap Heap Scan on couriers  (cost=91.21..25147.10 rows=1 width=16) (actual time=0.652..6.381 rows=615 loops=1)
              Filter: (((status)::text = 'available'::text) AND st_dwithin(current_location, '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, '5000'::double precision, true))
              Rows Removed by Filter: 1378
              Heap Blocks: exact=131
              Buffers: shared hit=157
              ->  Bitmap Index Scan on couriers_current_location_gist  (cost=0.00..91.21 rows=1991 width=0) (actual time=0.574..0.574 rows=1993 loops=1)
                    Index Cond: (current_location && _st_expand('0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, '5000'::double precision))
                    Buffers: shared hit=26
Planning:
  Buffers: shared hit=26
Planning Time: 5.370 ms
Execution Time: 7.499 ms
```

### `nearest_courier_knn` - ALTERNATIVE: KNN ordering with <-> (no radius)

```sql
SELECT id, current_location <-> ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326)::geography AS distance_m
  FROM couriers
 WHERE status = 'available'
 ORDER BY current_location <-> ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326)::geography
 LIMIT 5
```

```
Limit  (cost=0.15..2.39 rows=5 width=16) (actual time=0.396..0.424 rows=5 loops=1)
  Buffers: shared hit=16
  ->  Index Scan using couriers_current_location_gist on couriers  (cost=0.15..2466.65 rows=5514 width=16) (actual time=0.395..0.422 rows=5 loops=1)
        Order By: (current_location <-> '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography)
        Filter: ((status)::text = 'available'::text)
        Rows Removed by Filter: 6
        Buffers: shared hit=16
Planning:
  Buffers: shared hit=3
Planning Time: 0.233 ms
Execution Time: 0.556 ms
```

### `pending_in_box` - Pending orders inside a map rectangle

```sql
SELECT id, created_at
  FROM delivery_orders
 WHERE status = 'pending'
   AND ST_Intersects(pickup_location,
                     ST_MakeEnvelope(49.84, 40.39, 49.89, 40.43, 4326)::geography)
 ORDER BY created_at
```

```
Sort  (cost=4672.89..4674.05 rows=466 width=16) (actual time=3.058..3.072 rows=218 loops=1)
  Sort Key: created_at
  Sort Method: quicksort  Memory: 31kB
  Buffers: shared hit=291
  ->  Bitmap Heap Scan on delivery_orders  (cost=14.50..4652.23 rows=466 width=16) (actual time=0.247..2.988 rows=218 loops=1)
        Recheck Cond: ((status)::text = 'pending'::text)
        Filter: st_intersects(pickup_location, '0103000020E61000000100000005000000EC51B81E85EB484052B81E85EB314440EC51B81E85EB4840D7A3703D0A37444052B81E85EBF14840D7A3703D0A37444052B81E85EBF1484052B81E85EB314440EC51B81E85EB484052B81E85EB314440'::geography)
        Rows Removed by Filter: 84
        Heap Blocks: exact=283
        Buffers: shared hit=291
        ->  Bitmap Index Scan on delivery_orders_pending_pickup_gist  (cost=0.00..14.38 rows=298 width=0) (actual time=0.186..0.187 rows=302 loops=1)
              Index Cond: (pickup_location && '0103000020E61000000100000005000000EC51B81E85EB484052B81E85EB314440EC51B81E85EB4840D7A3703D0A37444052B81E85EBF14840D7A3703D0A37444052B81E85EBF1484052B81E85EB314440EC51B81E85EB484052B81E85EB314440'::geography)
              Buffers: shared hit=8
Planning:
  Buffers: shared hit=9
Planning Time: 2.341 ms
Execution Time: 3.264 ms
```

### `monthly_revenue` - Monthly revenue (covering index -> Index Only Scan)

```sql
SELECT date_trunc('month', created_at) AS month,
       count(*)                        AS delivered_orders,
       sum(delivery_fee)               AS revenue
  FROM delivery_orders
 WHERE status = 'delivered'
   AND created_at >= now() - interval '6 months'
 GROUP BY 1
 ORDER BY 1
```

```
GroupAggregate  (cost=5473.59..6616.43 rows=45124 width=48) (actual time=27.616..39.182 rows=7 loops=1)
  Group Key: (date_trunc('month'::text, created_at))
  Buffers: shared hit=214
  ->  Sort  (cost=5473.59..5590.08 rows=46598 width=14) (actual time=26.615..29.565 rows=42580 loops=1)
        Sort Key: (date_trunc('month'::text, created_at))
        Sort Method: quicksort  Memory: 2867kB
        Buffers: shared hit=214
        ->  Index Only Scan using delivery_orders_delivered_created_at_idx on delivery_orders  (cost=0.42..1860.38 rows=46598 width=14) (actual time=0.031..17.648 rows=42580 loops=1)
              Index Cond: (created_at >= (now() - '6 mons'::interval))
              Heap Fetches: 0
              Buffers: shared hit=214
Planning:
  Buffers: shared hit=21
Planning Time: 0.576 ms
Execution Time: 39.578 ms
```

### `top_couriers` - Top 10 busiest couriers

```sql
SELECT c.id, c.name, t.deliveries
  FROM (SELECT courier_id, count(*) AS deliveries
          FROM delivery_orders
         WHERE status = 'delivered'
         GROUP BY courier_id
         ORDER BY deliveries DESC
         LIMIT 10) t
  JOIN couriers c ON c.id = t.courier_id
 ORDER BY t.deliveries DESC
```

```
Nested Loop  (cost=3675.77..3754.64 rows=10 width=28) (actual time=35.156..35.176 rows=10 loops=1)
  Buffers: shared hit=454
  ->  Limit  (cost=3675.49..3675.51 rows=10 width=16) (actual time=35.098..35.102 rows=10 loops=1)
        Buffers: shared hit=424
        ->  Sort  (cost=3675.49..3698.46 rows=9189 width=16) (actual time=35.096..35.098 rows=10 loops=1)
              Sort Key: (count(*)) DESC
              Sort Method: top-N heapsort  Memory: 25kB
              Buffers: shared hit=424
              ->  HashAggregate  (cost=3385.03..3476.92 rows=9189 width=16) (actual time=32.096..33.625 rows=9943 loops=1)
                    Group Key: delivery_orders.courier_id
                    Batches: 1  Memory Usage: 1169kB
                    Buffers: shared hit=421
                    ->  Index Only Scan using delivery_orders_delivered_created_at_idx on delivery_orders  (cost=0.42..2960.82 rows=84841 width=8) (actual time=0.037..12.595 rows=84799 loops=1)
                          Heap Fetches: 0
                          Buffers: shared hit=421
  ->  Index Scan using couriers_pkey on couriers c  (cost=0.29..7.90 rows=1 width=20) (actual time=0.006..0.006 rows=1 loops=10)
        Index Cond: (id = delivery_orders.courier_id)
        Buffers: shared hit=30
Planning:
  Buffers: shared hit=17
Planning Time: 0.568 ms
Execution Time: 35.684 ms
```

### `order_history` - Status history of one order (FK lookup)

```sql
SELECT old_status, new_status, created_at
  FROM delivery_order_status_history
 WHERE order_id = 50000
 ORDER BY created_at
```

```
Index Scan using delivery_order_status_history_order_id_created_at_idx on delivery_order_status_history  (cost=0.42..20.17 rows=4 width=25) (actual time=0.049..0.083 rows=4 loops=1)
  Index Cond: (order_id = 50000)
  Buffers: shared hit=7
Planning:
  Buffers: shared hit=43
Planning Time: 1.056 ms
Execution Time: 0.116 ms
```

### `customer_by_email` - Case-insensitive email lookup (expression index)

```sql
SELECT id, name, email
  FROM customers
 WHERE lower(email) = lower('Customer777@example.com')
```

```
Index Scan using customers_lower_email_idx on customers  (cost=0.29..8.30 rows=1 width=47) (actual time=0.047..0.048 rows=1 loops=1)
  Index Cond: (lower((email)::text) = 'customer777@example.com'::text)
  Buffers: shared hit=3
Planning:
  Buffers: shared hit=48
Planning Time: 1.314 ms
Execution Time: 0.090 ms
```

### `daily_report` - GET /reports/daily for the last 30 days

```sql
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
```

```
Finalize GroupAggregate  (cost=11113.49..13653.78 rows=17565 width=52) (actual time=96.414..107.235 rows=31 loops=1)
  Group Key: ((date_trunc('day'::text, o.created_at))::date)
  Buffers: shared hit=5212
  ->  Gather Merge  (cost=11113.49..13117.50 rows=14798 width=52) (actual time=96.285..107.096 rows=93 loops=1)
        Workers Planned: 2
        Workers Launched: 2
        Buffers: shared hit=5212
        ->  Partial GroupAggregate  (cost=10113.46..10409.42 rows=7399 width=52) (actual time=42.279..45.383 rows=31 loops=3)
              Group Key: ((date_trunc('day'::text, o.created_at))::date)
              Buffers: shared hit=5212
              ->  Sort  (cost=10113.46..10131.96 rows=7399 width=29) (actual time=42.200..42.679 rows=5956 loops=3)
                    Sort Key: ((date_trunc('day'::text, o.created_at))::date)
                    Sort Method: quicksort  Memory: 252kB
                    Buffers: shared hit=5212
                    Worker 0:  Sort Method: quicksort  Memory: 137kB
                    Worker 1:  Sort Method: quicksort  Memory: 892kB
                    ->  Parallel Hash Right Join  (cost=4361.02..9637.96 rows=7399 width=29) (actual time=19.067..39.126 rows=5956 loops=3)
                          Hash Cond: (h.order_id = o.id)
                          Buffers: shared hit=5196
                          ->  Parallel Seq Scan on delivery_order_status_history h  (cost=0.00..5146.87 rows=35458 width=16) (actual time=11.786..20.871 rows=28266 loops=3)
                                Filter: ((new_status)::text = 'delivered'::text)
                                Rows Removed by Filter: 94285
                                Buffers: shared hit=3232
                          ->  Parallel Hash  (cost=4268.53..4268.53 rows=7399 width=25) (actual time=6.747..6.748 rows=5956 loops=3)
                                Buckets: 32768  Batches: 1  Memory Usage: 1344kB
                                Buffers: shared hit=1934
                                ->  Parallel Bitmap Heap Scan on delivery_orders o  (cost=414.05..4268.53 rows=7399 width=25) (actual time=2.120..10.646 rows=17868 loops=1)
                                      Recheck Cond: (created_at >= (now() - '30 days'::interval))
                                      Heap Blocks: exact=1862
                                      Buffers: shared hit=1934
                                      ->  Bitmap Index Scan on delivery_orders_created_at_id_idx  (cost=0.00..409.61 rows=17758 width=0) (actual time=1.646..1.647 rows=17868 loops=1)
                                            Index Cond: (created_at >= (now() - '30 days'::interval))
                                            Buffers: shared hit=72
Planning:
  Buffers: shared hit=36
Planning Time: 1.077 ms
Execution Time: 107.642 ms
```

