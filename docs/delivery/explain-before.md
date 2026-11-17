### `list_latest` - List: newest 20 orders (default API listing)

```sql
SELECT id, customer_id, courier_id, status, delivery_fee, created_at
  FROM delivery_orders
 ORDER BY created_at DESC, id DESC
 LIMIT 20
```

```
Limit  (cost=6287.20..6289.53 rows=20 width=47) (actual time=29.188..35.802 rows=20 loops=1)
  Buffers: shared hit=3813
  ->  Gather Merge  (cost=6287.20..16244.46 rows=85342 width=47) (actual time=29.186..35.795 rows=20 loops=1)
        Workers Planned: 2
        Workers Launched: 2
        Buffers: shared hit=3813
        ->  Sort  (cost=5287.17..5393.85 rows=42671 width=47) (actual time=22.979..22.982 rows=16 loops=3)
              Sort Key: created_at DESC, id DESC
              Sort Method: top-N heapsort  Memory: 28kB
              Buffers: shared hit=3813
              Worker 0:  Sort Method: top-N heapsort  Memory: 28kB
              Worker 1:  Sort Method: top-N heapsort  Memory: 28kB
              ->  Parallel Seq Scan on delivery_orders  (cost=0.00..4151.71 rows=42671 width=47) (actual time=0.871..10.324 rows=34137 loops=3)
                    Buffers: shared hit=3725
Planning:
  Buffers: shared hit=109
Planning Time: 1.785 ms
Execution Time: 36.012 ms
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
Limit  (cost=5215.70..5215.75 rows=20 width=47) (actual time=22.083..22.089 rows=20 loops=1)
  Buffers: shared hit=3725
  ->  Sort  (cost=5215.70..5235.48 rows=7913 width=47) (actual time=22.081..22.083 rows=20 loops=1)
        Sort Key: created_at DESC, id DESC
        Sort Method: top-N heapsort  Memory: 27kB
        Buffers: shared hit=3725
        ->  Seq Scan on delivery_orders  (cost=0.00..5005.14 rows=7913 width=47) (actual time=1.355..18.958 rows=7997 loops=1)
              Filter: ((status)::text = 'pending'::text)
              Rows Removed by Filter: 94414
              Buffers: shared hit=3725
Planning:
  Buffers: shared hit=11
Planning Time: 0.352 ms
Execution Time: 22.138 ms
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
Limit  (cost=5005.22..5005.23 rows=6 width=39) (actual time=10.569..10.573 rows=8 loops=1)
  Buffers: shared hit=3725
  ->  Sort  (cost=5005.22..5005.23 rows=6 width=39) (actual time=10.567..10.568 rows=8 loops=1)
        Sort Key: created_at DESC
        Sort Method: quicksort  Memory: 25kB
        Buffers: shared hit=3725
        ->  Seq Scan on delivery_orders  (cost=0.00..5005.14 rows=6 width=39) (actual time=1.348..10.527 rows=8 loops=1)
              Filter: (customer_id = 4242)
              Rows Removed by Filter: 102403
              Buffers: shared hit=3725
Planning:
  Buffers: shared hit=5
Planning Time: 0.424 ms
Execution Time: 10.652 ms
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
Limit  (cost=5005.28..5005.30 rows=9 width=39) (actual time=10.990..10.995 rows=11 loops=1)
  Buffers: shared hit=3725
  ->  Sort  (cost=5005.28..5005.30 rows=9 width=39) (actual time=10.988..10.991 rows=11 loops=1)
        Sort Key: created_at DESC
        Sort Method: quicksort  Memory: 25kB
        Buffers: shared hit=3725
        ->  Seq Scan on delivery_orders  (cost=0.00..5005.14 rows=9 width=39) (actual time=1.491..10.947 rows=11 loops=1)
              Filter: (courier_id = 1234)
              Rows Removed by Filter: 102400
              Buffers: shared hit=3725
Planning Time: 0.094 ms
Execution Time: 11.150 ms
```

### `date_filter_non_sargable` - ANTI-PATTERN: function on the indexed column

```sql
SELECT count(*)
  FROM delivery_orders
 WHERE created_at::date = current_date - 10
```

```
Finalize Aggregate  (cost=5579.17..5579.18 rows=1 width=8) (actual time=20.120..24.558 rows=1 loops=1)
  Buffers: shared hit=3725
  ->  Gather  (cost=5578.96..5579.17 rows=2 width=8) (actual time=19.860..24.534 rows=3 loops=1)
        Workers Planned: 2
        Workers Launched: 2
        Buffers: shared hit=3725
        ->  Partial Aggregate  (cost=4578.96..4578.97 rows=1 width=8) (actual time=12.764..12.765 rows=1 loops=3)
              Buffers: shared hit=3725
              ->  Parallel Seq Scan on delivery_orders  (cost=0.00..4578.43 rows=213 width=0) (actual time=0.474..12.736 rows=80 loops=3)
                    Filter: ((created_at)::date = (CURRENT_DATE - 10))
                    Rows Removed by Filter: 34057
                    Buffers: shared hit=3725
Planning:
  Buffers: shared hit=12
Planning Time: 0.265 ms
Execution Time: 24.647 ms
```

### `date_filter_sargable` - FIX: half-open range on the bare column

```sql
SELECT count(*)
  FROM delivery_orders
 WHERE created_at >= current_date - 10
   AND created_at <  current_date - 9
```

```
Finalize Aggregate  (cost=5792.25..5792.26 rows=1 width=8) (actual time=23.883..28.616 rows=1 loops=1)
  Buffers: shared hit=3725
  ->  Gather  (cost=5792.04..5792.25 rows=2 width=8) (actual time=23.496..28.601 rows=3 loops=1)
        Workers Planned: 2
        Workers Launched: 2
        Buffers: shared hit=3725
        ->  Partial Aggregate  (cost=4792.04..4792.05 rows=1 width=8) (actual time=14.814..14.816 rows=1 loops=3)
              Buffers: shared hit=3725
              ->  Parallel Seq Scan on delivery_orders  (cost=0.00..4791.78 rows=103 width=0) (actual time=0.693..14.774 rows=80 loops=3)
                    Filter: ((created_at >= (CURRENT_DATE - 10)) AND (created_at < (CURRENT_DATE - 9)))
                    Rows Removed by Filter: 34057
                    Buffers: shared hit=3725
Planning:
  Buffers: shared hit=11
Planning Time: 0.345 ms
Execution Time: 28.693 ms
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
Limit  (cost=13379.45..13379.50 rows=20 width=25) (actual time=106.531..106.540 rows=20 loops=1)
  Buffers: shared hit=3725, temp read=415 written=521
  ->  Sort  (cost=13254.45..13510.48 rows=102411 width=25) (actual time=92.277..103.470 rows=50020 loops=1)
        Sort Key: created_at DESC, id DESC
        Sort Method: external merge  Disk: 4152kB
        Buffers: shared hit=3725, temp read=415 written=521
        ->  Seq Scan on delivery_orders  (cost=0.00..4749.11 rows=102411 width=25) (actual time=0.917..21.776 rows=102411 loops=1)
              Buffers: shared hit=3725
Planning Time: 0.074 ms
Execution Time: 108.641 ms
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
Limit  (cost=6101.06..6103.40 rows=20 width=25) (actual time=32.498..40.127 rows=20 loops=1)
  Buffers: shared hit=3755
  ->  Gather Merge  (cost=6101.06..10684.05 rows=39280 width=25) (actual time=32.495..40.120 rows=20 loops=1)
        Workers Planned: 2
        Workers Launched: 2
        Buffers: shared hit=3755
        ->  Sort  (cost=5101.04..5150.14 rows=19640 width=25) (actual time=24.195..24.198 rows=16 loops=3)
              Sort Key: created_at DESC, id DESC
              Sort Method: top-N heapsort  Memory: 27kB
              Buffers: shared hit=3755
              Worker 0:  Sort Method: top-N heapsort  Memory: 27kB
              Worker 1:  Sort Method: top-N heapsort  Memory: 27kB
              ->  Parallel Seq Scan on delivery_orders  (cost=0.00..4578.43 rows=19640 width=25) (actual time=0.411..18.571 rows=15612 loops=3)
                    Filter: (ROW(created_at, id) < ROW((now() - '180 days'::interval), 0))
                    Rows Removed by Filter: 18525
                    Buffers: shared hit=3725
Planning Time: 0.142 ms
Execution Time: 40.342 ms
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
Limit  (cost=148286.53..148286.54 rows=5 width=16) (actual time=60.887..60.890 rows=5 loops=1)
  Buffers: shared hit=364
  ->  Sort  (cost=148286.53..148291.12 rows=1838 width=16) (actual time=49.906..49.908 rows=5 loops=1)
        Sort Key: (st_distance(current_location, '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, true))
        Sort Method: top-N heapsort  Memory: 25kB
        Buffers: shared hit=364
        ->  Seq Scan on couriers  (cost=0.00..148256.00 rows=1838 width=16) (actual time=27.494..49.598 rows=615 loops=1)
              Filter: (((status)::text = 'available'::text) AND (st_distance(current_location, '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, true) < '5000'::double precision))
              Rows Removed by Filter: 9385
              Buffers: shared hit=361
Planning:
  Buffers: shared hit=69
Planning Time: 21.742 ms
JIT:
  Functions: 5
  Options: Inlining false, Optimization false, Expressions true, Deforming true
  Timing: Generation 0.751 ms (Deform 0.277 ms), Inlining 0.000 ms, Optimization 0.534 ms, Emission 10.482 ms, Total 11.767 ms
Execution Time: 104.148 ms
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
Limit  (cost=125268.51..125268.51 rows=1 width=16) (actual time=28.883..28.887 rows=5 loops=1)
  Buffers: shared hit=131
  ->  Sort  (cost=125268.51..125268.51 rows=1 width=16) (actual time=18.679..18.681 rows=5 loops=1)
        Sort Key: (st_distance(current_location, '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, true))
        Sort Method: top-N heapsort  Memory: 25kB
        Buffers: shared hit=131
        ->  Seq Scan on couriers  (cost=0.00..125268.50 rows=1 width=16) (actual time=0.094..18.469 rows=615 loops=1)
              Filter: (((status)::text = 'available'::text) AND st_dwithin(current_location, '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography, '5000'::double precision, true))
              Rows Removed by Filter: 9385
              Buffers: shared hit=131
Planning:
  Buffers: shared hit=3
Planning Time: 0.302 ms
JIT:
  Functions: 5
  Options: Inlining false, Optimization false, Expressions true, Deforming true
  Timing: Generation 0.887 ms (Deform 0.332 ms), Inlining 0.000 ms, Optimization 0.379 ms, Emission 9.833 ms, Total 11.099 ms
Execution Time: 29.960 ms
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
Limit  (cost=1726.09..1726.10 rows=5 width=16) (actual time=7.086..7.089 rows=5 loops=1)
  Buffers: shared hit=131
  ->  Sort  (cost=1726.09..1739.87 rows=5514 width=16) (actual time=7.084..7.085 rows=5 loops=1)
        Sort Key: ((current_location <-> '0101000020E6100000492EFF21FDEE48405C2041F163344440'::geography))
        Sort Method: top-N heapsort  Memory: 25kB
        Buffers: shared hit=131
        ->  Seq Scan on couriers  (cost=0.00..1634.50 rows=5514 width=16) (actual time=0.019..5.919 rows=5514 loops=1)
              Filter: ((status)::text = 'available'::text)
              Rows Removed by Filter: 4486
              Buffers: shared hit=131
Planning Time: 0.111 ms
Execution Time: 7.195 ms
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
Gather Merge  (cost=538656.41..538701.68 rows=388 width=16) (actual time=395.133..421.435 rows=218 loops=1)
  Workers Planned: 2
  Workers Launched: 2
  Buffers: shared hit=5119
  ->  Sort  (cost=537656.39..537656.87 rows=194 width=16) (actual time=319.390..319.400 rows=73 loops=3)
        Sort Key: created_at
        Sort Method: quicksort  Memory: 31kB
        Buffers: shared hit=5119
        Worker 0:  Sort Method: quicksort  Memory: 25kB
        Worker 1:  Sort Method: quicksort  Memory: 25kB
        ->  Parallel Seq Scan on delivery_orders  (cost=0.00..537649.02 rows=194 width=16) (actual time=277.454..318.863 rows=73 loops=3)
              Filter: (((status)::text = 'pending'::text) AND st_intersects(pickup_location, '0103000020E61000000100000005000000EC51B81E85EB484052B81E85EB314440EC51B81E85EB4840D7A3703D0A37444052B81E85EBF14840D7A3703D0A37444052B81E85EBF1484052B81E85EB314440EC51B81E85EB484052B81E85EB314440'::geography))
              Rows Removed by Filter: 34064
              Buffers: shared hit=5045
Planning:
  Buffers: shared hit=9
Planning Time: 1.100 ms
JIT:
  Functions: 12
  Options: Inlining true, Optimization true, Expressions true, Deforming true
  Timing: Generation 3.351 ms (Deform 1.975 ms), Inlining 463.954 ms, Optimization 153.052 ms, Emission 117.172 ms, Total 737.529 ms
Execution Time: 422.776 ms
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
GroupAggregate  (cost=9502.92..10645.76 rows=45124 width=48) (actual time=70.874..85.110 rows=7 loops=1)
  Group Key: (date_trunc('month'::text, created_at))
  Buffers: shared hit=3725
  ->  Sort  (cost=9502.92..9619.41 rows=46598 width=14) (actual time=69.378..74.029 rows=42580 loops=1)
        Sort Key: (date_trunc('month'::text, created_at))
        Sort Method: quicksort  Memory: 2867kB
        Buffers: shared hit=3725
        ->  Seq Scan on delivery_orders  (cost=0.00..5889.72 rows=46598 width=14) (actual time=1.149..57.083 rows=42580 loops=1)
              Filter: (((status)::text = 'delivered'::text) AND (created_at >= (now() - '6 mons'::interval)))
              Rows Removed by Filter: 59831
              Buffers: shared hit=3725
Planning:
  Buffers: shared hit=21
Planning Time: 0.652 ms
Execution Time: 85.680 ms
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
Nested Loop  (cost=5720.09..5798.96 rows=10 width=28) (actual time=56.859..56.886 rows=10 loops=1)
  Buffers: shared hit=3755
  ->  Limit  (cost=5719.80..5719.83 rows=10 width=16) (actual time=56.756..56.760 rows=10 loops=1)
        Buffers: shared hit=3725
        ->  Sort  (cost=5719.80..5742.78 rows=9189 width=16) (actual time=56.753..56.756 rows=10 loops=1)
              Sort Key: (count(*)) DESC
              Sort Method: top-N heapsort  Memory: 25kB
              Buffers: shared hit=3725
              ->  HashAggregate  (cost=5429.34..5521.23 rows=9189 width=16) (actual time=52.265..54.724 rows=9943 loops=1)
                    Group Key: delivery_orders.courier_id
                    Batches: 1  Memory Usage: 1169kB
                    Buffers: shared hit=3725
                    ->  Seq Scan on delivery_orders  (cost=0.00..5005.14 rows=84841 width=8) (actual time=1.017..23.192 rows=84799 loops=1)
                          Filter: ((status)::text = 'delivered'::text)
                          Rows Removed by Filter: 17612
                          Buffers: shared hit=3725
  ->  Index Scan using couriers_pkey on couriers c  (cost=0.29..7.90 rows=1 width=20) (actual time=0.011..0.011 rows=1 loops=10)
        Index Cond: (id = delivery_orders.courier_id)
        Buffers: shared hit=30
Planning:
  Buffers: shared hit=17
Planning Time: 0.594 ms
Execution Time: 57.642 ms
```

### `order_history` - Status history of one order (FK lookup)

```sql
SELECT old_status, new_status, created_at
  FROM delivery_order_status_history
 WHERE order_id = 50000
 ORDER BY created_at
```

```
Sort  (cost=6147.31..6147.32 rows=4 width=25) (actual time=77.606..91.259 rows=4 loops=1)
  Sort Key: created_at
  Sort Method: quicksort  Memory: 25kB
  Buffers: shared hit=3232
  ->  Gather  (cost=1000.00..6147.27 rows=4 width=25) (actual time=8.381..91.205 rows=4 loops=1)
        Workers Planned: 2
        Workers Launched: 2
        Buffers: shared hit=3232
        ->  Parallel Seq Scan on delivery_order_status_history  (cost=0.00..5146.87 rows=2 width=25) (actual time=2.633..17.276 rows=1 loops=3)
              Filter: (order_id = 50000)
              Rows Removed by Filter: 122550
              Buffers: shared hit=3232
Planning:
  Buffers: shared hit=28
Planning Time: 0.822 ms
Execution Time: 91.324 ms
```

### `customer_by_email` - Case-insensitive email lookup (expression index)

```sql
SELECT id, name, email
  FROM customers
 WHERE lower(email) = lower('Customer777@example.com')
```

```
Seq Scan on customers  (cost=0.00..521.26 rows=101 width=47) (actual time=1.155..20.257 rows=1 loops=1)
  Filter: (lower((email)::text) = 'customer777@example.com'::text)
  Rows Removed by Filter: 20150
  Buffers: shared hit=219
Planning:
  Buffers: shared hit=25
Planning Time: 0.720 ms
Execution Time: 20.282 ms
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
Finalize GroupAggregate  (cost=11316.70..13856.99 rows=17565 width=52) (actual time=147.879..162.631 rows=31 loops=1)
  Group Key: ((date_trunc('day'::text, o.created_at))::date)
  Buffers: shared hit=7061
  ->  Gather Merge  (cost=11316.70..13320.72 rows=14798 width=52) (actual time=147.722..162.442 rows=93 loops=1)
        Workers Planned: 2
        Workers Launched: 2
        Buffers: shared hit=7061
        ->  Partial GroupAggregate  (cost=10316.68..10612.64 rows=7399 width=52) (actual time=73.346..77.348 rows=31 loops=3)
              Group Key: ((date_trunc('day'::text, o.created_at))::date)
              Buffers: shared hit=7061
              ->  Sort  (cost=10316.68..10335.17 rows=7399 width=29) (actual time=73.249..73.923 rows=5956 loops=3)
                    Sort Key: ((date_trunc('day'::text, o.created_at))::date)
                    Sort Method: quicksort  Memory: 223kB
                    Buffers: shared hit=7061
                    Worker 0:  Sort Method: quicksort  Memory: 132kB
                    Worker 1:  Sort Method: quicksort  Memory: 926kB
                    ->  Parallel Hash Right Join  (cost=4564.23..9841.18 rows=7399 width=29) (actual time=44.971..69.251 rows=5956 loops=3)
                          Hash Cond: (h.order_id = o.id)
                          Buffers: shared hit=6987
                          ->  Parallel Seq Scan on delivery_order_status_history h  (cost=0.00..5146.87 rows=35458 width=16) (actual time=17.566..27.741 rows=28266 loops=3)
                                Filter: ((new_status)::text = 'delivered'::text)
                                Rows Removed by Filter: 94285
                                Buffers: shared hit=3232
                          ->  Parallel Hash  (cost=4471.75..4471.75 rows=7399 width=25) (actual time=26.774..26.775 rows=5956 loops=3)
                                Buckets: 32768  Batches: 1  Memory Usage: 1344kB
                                Buffers: shared hit=3725
                                ->  Parallel Seq Scan on delivery_orders o  (cost=0.00..4471.75 rows=7399 width=25) (actual time=1.578..64.896 rows=17868 loops=1)
                                      Filter: (created_at >= (now() - '30 days'::interval))
                                      Rows Removed by Filter: 84543
                                      Buffers: shared hit=3725
Planning:
  Buffers: shared hit=28
Planning Time: 1.286 ms
Execution Time: 163.065 ms
```

