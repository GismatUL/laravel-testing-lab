# Learning Guide: PostgreSQL, Query Optimisation and PostGIS

*Task02, the courier management system. Every number in this guide was measured on the seeded dataset
(102,411 orders, 10,000 couriers) inside the project's Docker stack. Your timings will vary a bit,
but the plan shapes should match.*

**Suggested reading order (≈2 hours):**

1. §1 PostGIS fundamentals (20 min). Then run `php artisan delivery:sql nearest_courier_dwithin --explain`.
2. §2 Schema decisions (10 min).
3. §3 How to read EXPLAIN ANALYZE (15 min).
4. §4 Indexing and optimisation (30 min). Do the before/after experiment yourself.
5. §5 Advanced SQL (20 min). Run each query with `delivery:sql <key>`.
6. §6 Transactions and concurrency (20 min). Run all four `delivery:race-demo` scenarios.
7. §7 Testing and §8 interview questions.

---

## 1. PostGIS fundamentals

### 1.1 What PostGIS adds

PostgreSQL is extensible. `CREATE EXTENSION postgis` (our first migration) installs:

* **Types:** `geometry` and `geography`.
* **Functions:** around 400 `ST_*` functions (Spatial Type).
* **Index support:** GiST operator classes, so spatial predicates can use an index.

### 1.2 `geometry` vs `geography`: the most important decision

| | `geometry` | `geography` |
|---|---|---|
| Model | flat Cartesian plane | the Earth's spheroid (WGS84) |
| Units with SRID 4326 | **degrees** | **metres** |
| Maths | fast, simple | slower but correct anywhere on Earth |
| Functions | all ~400 | a subset (enough for distance and containment) |
| Typical use | projected local data, complex GIS analysis | GPS points, "within N metres" anywhere |

We use `geography(Point, 4326)` because couriers send GPS coordinates and the business speaks in
metres ("5 km radius"). With geometry in SRID 4326, `ST_DWithin(a, b, 5000)` would mean
*5000 degrees*, which covers the whole planet. And a degree of longitude shrinks towards the poles
(≈85 km at Baku's latitude, ≈111 km at the equator), so degree-based radii are not even circles.

### 1.3 SRID 4326

An SRID (Spatial Reference ID) says which coordinate system a number pair belongs to.
**4326 = WGS84**, what GPS and every phone use. `ST_SetSRID(point, 4326)` tags a point as WGS84 and
does no conversion. (`ST_Transform` converts between systems.)

### 1.4 Longitude first!

```sql
ST_MakePoint(x, y)  -- x = LONGITUDE, y = LATITUDE
ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326)::geography   -- Baku ✔
ST_SetSRID(ST_MakePoint(40.4093, 49.8671), 4326)::geography   -- southern Russia ✘
```

Humans say "lat, lng". GeoJSON, PostGIS, WKT and maths say "x, y" = "lng, lat". This is the most common
PostGIS bug, and it fails silently: you get a valid point in the wrong country. Our defence:

* `App\Support\GeoPoint` is the **only** place that turns `(lat, lng)` into SQL. Its
  `GeoPoint::SQL` constant plus `bindings()` always emits `[lng, lat]`.
* `CreateDeliveryOrderTest::test_coordinates_are_stored_longitude_first` asserts
  `ST_X = lng` and `ST_Y = lat`.

### 1.5 The core functions used in this project

| Function | What it does | Where |
|---|---|---|
| `ST_MakePoint(lng, lat)` | builds a geometry point (no SRID) | everywhere |
| `ST_SetSRID(g, 4326)` | tags it as WGS84 | everywhere |
| `::geography` | cast, so maths happens on the spheroid in metres | everywhere |
| `ST_Distance(a, b)` | exact distance in metres | sorting, fee calculation |
| `ST_DWithin(a, b, r)` | "is the distance ≤ r?", **index-aware** | nearest courier, radius search |
| `ST_MakeEnvelope(xmin, ymin, xmax, ymax, 4326)` | rectangle polygon | pending orders in a map area |
| `ST_Intersects(a, b)` | do they touch or overlap? index-aware | point-in-rectangle |
| `ST_X(g::geometry)`, `ST_Y(g::geometry)` | read lng/lat back | `HasGeographyColumns` trait |
| `a <-> b` | KNN distance operator, can be served by the GiST index in ORDER BY | alternative nearest query |

### 1.6 Why `ST_DWithin` and never `ST_Distance(...) < r`

Same question, same answer, very different execution. These are the real plans after indexing:

```
-- ST_Distance(current_location, :p) < 5000          (nearest_courier_distance_filter)
Seq Scan on couriers  (actual rows=615)
  Filter: (status = 'available' AND st_distance(current_location, p) < 5000)
  Rows Removed by Filter: 9385                       <- 10,000 spheroid distance computations
Execution Time: ~90 ms

-- ST_DWithin(current_location, :p, 5000)            (nearest_courier_dwithin)
Bitmap Heap Scan on couriers  (actual rows=615)
  Filter: (status = 'available' AND st_dwithin(current_location, p, 5000))
  Rows Removed by Filter: 1378
  ->  Bitmap Index Scan on couriers_current_location_gist  (actual rows=1993)
        Index Cond: (current_location && _st_expand(p, 5000))   <- the trick
Execution Time: ~7-20 ms
```

`ST_DWithin` is defined so that PostGIS adds an **index condition**:
`current_location && _st_expand(point, 5000)`. In words: "the courier's bounding box overlaps a box
grown 5 km around the point". The GiST index answers that box test cheaply (1,993 candidates). The
exact spheroid distance then runs only on those candidates. `ST_Distance` is just a function, and a
B-tree or GiST index can't help with "function(column) < constant".

> **Interview line:** *"ST_DWithin is index-aware because it expands into a bounding-box `&&` test plus
> an exact recheck. ST_Distance in a WHERE clause forces a sequential scan."*

### 1.7 The geometry-cast trap

```sql
SELECT id FROM couriers
WHERE ST_DWithin(current_location::geometry, ST_SetSRID(ST_MakePoint(49.8671, 40.4093), 4326), 0.045);
-- Seq Scan on couriers ... Rows Removed by Filter: 9099
```

Two bugs in one line:

1. The cast `current_location::geometry` produces a *different expression* from the indexed column,
   so the geography GiST index can't be used: Seq Scan.
2. The radius is now in **degrees**. 0.045° is ≈5 km north–south but only ≈3.8 km east–west here.
   You've drawn an ellipse, not a circle.

### 1.8 GiST in one paragraph

A B-tree needs a total order (a < b < c), and 2-D points have none. **GiST** (Generalised Search Tree)
stores **bounding boxes** in a balanced tree, similar to an R-tree: each internal node holds the box
that encloses all of its children. A search only descends into nodes whose box overlaps the query
box. The index answers `&&` (box overlap) and, in `ORDER BY`, `<->` (KNN). Because boxes are
approximate, every hit is **rechecked** against the real geometry. That's why the plans show both
`Index Cond` (the box test) and `Filter` (the exact test).

### 1.9 KNN alternative: `ORDER BY location <-> point LIMIT k`

```
Limit  ->  Index Scan using couriers_current_location_gist
             Order By: (current_location <-> p)          -- 0.5 ms
```

The GiST index returns rows **already ordered by distance**, so there's no sort and no radius
needed. The catch: without `ST_DWithin` there is no radius limit. If the 5 nearest couriers are
40 km away, you still get them. Production pattern: `WHERE ST_DWithin(...) ORDER BY <-> LIMIT k`.
The radius is the business rule and `<->` provides the ordering.

---

## 2. Schema design decisions

```
customers 1──* delivery_orders *──1 couriers
                     │
                     └──* delivery_order_status_history
```

| Decision | Why | Alternative and trade-off |
|---|---|---|
| `status varchar + CHECK` | allowed values are easy to change (drop and re-add the constraint) | Native `ENUM` type: 4 bytes and type-safe, but you can't remove values and adding one is a DDL change |
| `geography(Point,4326)` | GPS data, metres, correct everywhere | `geometry` + `ST_Transform` to a local metric projection: faster, more functions, more complexity |
| **Partial UNIQUE index** `(courier_id) WHERE status IN ('assigned','picked_up')` | The database guarantees at most one active order per courier, whatever the PHP code does | A `current_order_id` column on couriers. That needs to be kept in sync and still needs locking |
| CHECK `status IN ('pending','cancelled') OR courier_id IS NOT NULL` | an assigned/delivered order without a courier is impossible | application-only validation, which can be bypassed |
| CHECK `delivery_fee >= 0` | cheap invariant | none |
| `timestamptz` (`timestampTz`) | stores an absolute instant, so no time-zone ambiguity | `timestamp` without zone, a classic source of bugs with DST and multi-region data |
| `created_at NOT NULL` | every report groups by it; NULLs would silently disappear | Laravel's default `timestamps()` is nullable |
| `customers.email` not unique | exercise #7 needs duplicates | real system: `UNIQUE INDEX ON customers (lower(email))` |
| History table written in the same transaction as the status change | atomic audit trail | `AFTER UPDATE` trigger: can't be bypassed, but hidden from PHP developers |
| FKs without indexes in the first migration | to show that **PostgreSQL does not index FKs automatically** (MySQL/InnoDB does) | n/a, see §4 |

---

## 3. Reading EXPLAIN ANALYZE

```sql
EXPLAIN                      -- the plan + estimates, does NOT run the query
EXPLAIN ANALYZE              -- RUNS the query, adds actual times and rows
EXPLAIN (ANALYZE, BUFFERS)   -- + how many 8 kB pages were touched (shared hit = from RAM)
```

> ⚠ `EXPLAIN ANALYZE DELETE ...` really deletes. Wrap writes in `BEGIN; ... ROLLBACK;`.

Anatomy of one line:

```
Index Scan Backward using delivery_orders_created_at_id_idx on delivery_orders
   (cost=0.42..18262.61 rows=7913 width=47) (actual time=0.040..0.230 rows=20 loops=1)
    ^startup ^total     ^estimated rows       ^first row  ^last row  ^real  ^times executed
```

* **cost** is in abstract units (≈ one sequential page read = 1.0). Use it only to compare plans
  of the *same* query.
* **rows (estimated) vs rows (actual)** is the most useful diagnostic. A big mismatch means the
  planner is guessing, so it may pick the wrong join or scan type. Fixes: `ANALYZE`, a higher
  `default_statistics_target`, extended statistics (`CREATE STATISTICS`).
  *Our example:* `nearest_courier_dwithin` estimated `rows=1` but got `rows=615`. Spatial
  selectivity is hard to estimate. It didn't matter here, but on a join it could.
* **actual time** is per loop, in ms. Multiply by `loops` for nested-loop inner sides.
* Read the tree **bottom-up, most indented first**. Children feed parents.
* **Rows Removed by Filter** shows work done for nothing. A large value is a hint that an index
  (or a better one) is missing.

### Scan types you'll see

| Node | Meaning | When the planner picks it |
|---|---|---|
| **Seq Scan** | read every page of the table | no usable index, or the query returns a large fraction of the table |
| **Index Scan** | walk the index, fetch each row from the heap | few rows, or the ORDER BY matches the index order (with LIMIT) |
| **Index Only Scan** | answer from the index alone (`Heap Fetches: 0`) | every needed column is in the index **and** pages are all-visible (VACUUM) |
| **Bitmap Index Scan + Bitmap Heap Scan** | collect matching row locations into a bitmap, then read heap pages **in physical order** | the "medium" range, or combining several indexes (BitmapAnd/BitmapOr) |
| **Parallel Seq Scan / Gather** | several workers split the table | large scans |

Selectivity demo (a temporary index on `status` inside `BEGIN … ROLLBACK`), using the real
distribution from `pg_stats`:

| `WHERE status = …` | fraction of rows | plan chosen |
|---|---|---|
| `'picked_up'` | 1.2 % | **Index Scan** (0.9 ms) |
| `'cancelled'` | 7.0 % | **Bitmap Heap Scan** (4.5 ms) |
| `'delivered'`, `SELECT *` | 83 % | **Seq Scan**: the index is ignored, correctly (21 ms) |
| `'delivered'`, `SELECT count(*)` | 83 % | **Index Only Scan**: the index is smaller than the table, nothing else needed |

That's the planner's cost model at work. Random heap fetches through an index are expensive
(`random_page_cost = 4`, vs `seq_page_cost = 1`). Past a few percent of the table, reading
everything sequentially wins.

You can inspect what the planner knows:

```sql
SELECT attname, n_distinct, most_common_vals, most_common_freqs, correlation
FROM pg_stats WHERE tablename = 'delivery_orders';
-- status: n_distinct=5, MCV {delivered,pending,cancelled,picked_up,assigned}
--                          {0.831,   0.075,  0.070,    0.013,    0.011}
-- created_at: correlation 0.047  (physical order ≠ time order; see BRIN below)
```

---

## 4. Indexing and optimisation

### 4.1 The experiment

The first five migrations create **no performance indexes**. Migration `…000010` adds all of them,
so you can toggle them:

```bash
php artisan migrate:rollback --step=1
php artisan delivery:sql --group=optimization --explain
php artisan migrate
php artisan delivery:sql --group=optimization --explain
```

Results captured in `explain-before.md` / `explain-after.md`:

| Query | Before | After | What changed |
|---|---:|---:|---|
| `list_latest` (newest 20) | 36.0 ms | **0.36 ms** | Parallel Seq Scan + top-N sort → Index Scan Backward + Limit |
| `list_by_status` (pending) | 22.1 ms | **0.28 ms** | same index, filter on the fly (36 rows skipped) |
| `list_by_customer` | 10.7 ms | **0.31 ms** | Seq Scan → Index Scan on `(customer_id, created_at)` |
| `list_by_courier` | 11.2 ms | **0.20 ms** | Seq Scan → Index Scan on `(courier_id, created_at)` |
| `date_filter_sargable` | 28.7 ms | **0.26 ms** | Seq Scan → Index Only Scan with `Index Cond` |
| `date_filter_non_sargable` | 24.6 ms | 21.0 ms | **no real improvement.** See 4.4 |
| `offset_pagination` (OFFSET 50000) | 108.6 ms | 42.6 ms | still walks 50,020 index entries |
| `keyset_pagination` (same depth) | 40.3 ms | **0.16 ms** | seeks straight to the position |
| `nearest_courier_distance_filter` | 104 ms | 110 ms | **no change.** ST_Distance can't use an index |
| `nearest_courier_dwithin` | 30.0 ms | **7.5 ms** | Bitmap Index Scan on GiST |
| `nearest_courier_knn` | 7.2 ms | **0.56 ms** | GiST returns rows already ordered by `<->` |
| `pending_in_box` | 423 ms | **3.3 ms** | Seq Scan → partial GiST (pending rows only) |
| `monthly_revenue` | 85.7 ms | 39.6 ms | Index **Only** Scan on the partial covering index, `Heap Fetches: 0` |
| `top_couriers` | 57.6 ms | 35.7 ms | Index Only Scan (courier_id is in INCLUDE) |
| `order_history` | 91.3 ms | **0.12 ms** | Seq Scan of 367k rows → index lookup |
| `customer_by_email` | 20.3 ms | **0.09 ms** | expression index on `lower(email)` |
| `daily_report` | 163 ms | 108 ms | history table is still seq-scanned. See 4.6 |

### 4.2 Every index, and why it exists

| Index | Type | Serves | Notes |
|---|---|---|---|
| `delivery_orders (created_at, id)` | B-tree composite | default listing, keyset pagination, date ranges | Scanned **backward** for `ORDER BY created_at DESC, id DESC`, so no DESC index is needed. `id` makes the order total, which cursors need |
| `delivery_orders (customer_id, created_at)` | B-tree composite | "orders of customer X", **NOT EXISTS** anti-join, FK checks on `DELETE customers` | Column order rule: **equality columns first, then range/sort columns** |
| `delivery_orders (courier_id, created_at)` | B-tree composite | "orders of courier X", FK `ON DELETE SET NULL` | |
| `delivery_orders (created_at) INCLUDE (delivery_fee, courier_id) WHERE status='delivered'` | B-tree **partial + covering** | revenue, top couriers, delivery reports | `INCLUDE` columns sit in the leaf pages only, not in the key. That's enough for an Index Only Scan |
| `delivery_orders USING GIST (pickup_location) WHERE status='pending'` | **partial GiST** | pending-orders-in-area | 1.0 MB vs 7.2 MB for a full GiST on the same column (measured). Only used when the query says `status = 'pending'` |
| `couriers USING GIST (current_location)` | GiST | nearest courier, KNN | full (not partial), see 4.5 |
| `delivery_order_status_history (order_id, created_at)` | B-tree composite | history of one order, `DISTINCT ON (order_id)` | the FK was unindexed: 91 ms → 0.12 ms |
| `customers (lower(email))` | **expression** | case-insensitive lookup | `WHERE lower(email) = …` can only use an index on that exact expression |
| `delivery_orders (courier_id) WHERE status IN ('assigned','picked_up')` UNIQUE | partial unique | **correctness**, not speed | created in the table migration, not the optimisation one |

**Deliberately *not* created:**

* `delivery_orders (status)` alone: 5 distinct values, 83% `delivered`. It's useless for the common
  queries (see the selectivity table in §3), and it would cost write time on every status change.
* `delivery_orders USING GIST (delivery_location)`: no query filters on it.
* `delivery_orders (status, created_at)`: the `(created_at, id)` index + filter already serves
  "newest pending", because pending is 7.5% of rows and LIMIT 20 stops after ~56 index entries.
  Measure first, add later.
* **BRIN** on `created_at`: BRIN stores min/max per block range and only works when physical order
  follows the column (`correlation` near ±1). Our seed inserted random dates (`correlation = 0.047`),
  so BRIN would be useless. In a real append-only orders table, correlation is ~1.0 and a BRIN index
  is a few kB instead of 6 MB.

### 4.3 The cost side

```
table delivery_orders .................. 29 MB
its 8 indexes (incl. PK) ............... ~34 MB    <- more than the table itself
delivery_order_status_history_..._idx .. 12 MB
```

Every index:

* takes disk space and space in `shared_buffers` (RAM), crowding out other hot pages
* must be updated on **every INSERT**, and on every UPDATE that changes an indexed column
* blocks **HOT updates**. Normally an UPDATE that touches no indexed column can write the new row
  version on the same page without touching any index (a "Heap-Only Tuple"). Our courier status
  flip is cheap because `status` isn't in any couriers index. That was one reason to keep the
  couriers GiST index full rather than partial on `status = 'available'`: a column in a partial
  index's `WHERE` clause counts as "indexed" for HOT purposes.

Workload matters: `couriers.current_location` is written on every GPS ping, maybe every 5 seconds
per courier. Each ping updates the GiST index. 10k couriers × 12 pings/min = 2,000 index updates per
second. That's a real trade-off you should be able to talk about.

### 4.4 Sargability ("Search ARGument ABLE")

```sql
WHERE created_at::date = current_date - 10                -- function on column
-- Parallel Index Only Scan ... Filter: ((created_at)::date = …)  Rows Removed by Filter: 34057 (×3)
WHERE created_at >= current_date - 10 AND created_at < current_date - 9
-- Index Only Scan ... Index Cond: (created_at >= … AND created_at < …)   0.26 ms
```

Look carefully at the first plan. It *does* say "Index Only Scan", but there's no `Index Cond`,
only a `Filter`. PostgreSQL read the **whole** index because it's narrower than the table, then
applied the function to every entry. "The index appears in the plan" is not the same as "the index
is used to seek". Always look for `Index Cond`.

Other non-sargable patterns: `WHERE lower(email) = ?` without an expression index,
`WHERE id + 1 = ?`, `WHERE col::text = ?`, `LIKE '%term'`, and casting geography to geometry (§1.7).

Also note the **half-open range** `[from, to)` used throughout the API (`ReportRangeRequest`).
`BETWEEN '2026-05-01' AND '2026-05-31 23:59:59'` loses everything in the last second and breaks
with fractional timestamps.

### 4.5 Partial indexes: when they're used

The planner uses a partial index only if it can **prove** from the query's WHERE clause that the
index predicate holds. `WHERE status = 'pending' AND ST_Intersects(...)` → yes.
`WHERE status = ? AND ...` with a bound parameter: yes with a custom plan (PDO's unnamed prepared
statements are planned with the actual values), no if the statement becomes a generic plan.

Why partial for pending orders but full for couriers?

* Pending orders: 7.5% of the table, and every spatial query about orders is about pending ones.
  The index is ~7× smaller (1.0 MB vs 7.2 MB).
* Couriers: 55% available, so a partial index saves little, and status flips would move entries in
  and out of it on every assignment. A full index is simpler and serves admin queries ("all couriers
  in this area") too.

### 4.6 When an index is *correctly* not used

`daily_report` joins ~18k orders from the last 30 days to their `delivered` history rows. After
indexing, the history side is still a **Parallel Seq Scan** joined with a **Hash Join**:

```
Parallel Hash Right Join  (Hash Cond: h.order_id = o.id)
  -> Parallel Seq Scan on delivery_order_status_history h  Filter: new_status = 'delivered'
  -> Parallel Hash -> Index Scan using delivery_orders_created_at_id_idx on delivery_orders o
```

18k random index probes into a 367k-row table cost more than one sequential pass plus a hash
table. The planner is right. Options if this report became hot:

* **Denormalise:** add `delivered_at` to `delivery_orders` (set in the same transaction). The report
  then needs no join at all. This is the usual production answer.
* A **materialised view** refreshed periodically, or a daily rollup table.
* A partial covering index `ON history (order_id) INCLUDE (created_at) WHERE new_status='delivered'`
  could enable a merge join. Measure before adding it.

### 4.7 OFFSET vs keyset pagination

```
OFFSET 50000 LIMIT 20  -> Index Scan Backward ... (actual rows=50020)  42 ms
keyset                 -> Index Scan Backward ... Index Cond: ROW(created_at, id) < ROW(…)
                                                  (actual rows=20)      0.16 ms
```

OFFSET still produces and throws away 50,000 rows, so page N costs O(N). Keyset uses the last seen
`(created_at, id)` as an index seek position, so every page costs the same. It also behaves well
when new rows are inserted while the user is paging (no duplicates or skipped rows). Laravel:
`->cursorPaginate()` (used by `GET /orders`). The trade-off: no "jump to page 37" and no total
count, which is usually fine for APIs and infinite scroll.

---

## 5. Advanced SQL exercises

Run any of these with `php artisan delivery:sql <key>` (add `-e` for the plan).
Source: `App\Services\Delivery\Sql\QueryCatalog::advanced()`.

### #1 `top_customers`: top 5 by spending
`JOIN` + `GROUP BY c.id` + `ORDER BY sum DESC LIMIT 5`. Since PostgreSQL 9.1 you can select
`c.name, c.email` while grouping only by `c.id`, because they're *functionally dependent* on the
primary key. (MySQL with `ONLY_FULL_GROUP_BY` accepts this as well. Older MySQL silently picked
arbitrary values for non-grouped columns.)

Laravel:
```php
DB::table('delivery_orders as o')
    ->join('customers as c', 'c.id', '=', 'o.customer_id')
    ->where('o.status', 'delivered')
    ->groupBy('c.id')
    ->select('c.id', 'c.name', 'c.email')
    ->selectRaw('count(*) as delivered_orders, sum(o.delivery_fee) as total_spent')
    ->orderByDesc('total_spent')->orderBy('c.id')
    ->limit(5)->get();
```

### #2 `customers_without_orders`: anti-join
```sql
WHERE NOT EXISTS (SELECT 1 FROM delivery_orders o WHERE o.customer_id = c.id)
```
Plan: **Merge Anti Join** using `customers_pkey` and an *Index Only Scan* on
`(customer_id, created_at)`. Three ways to write an anti-join:

| Form | Verdict |
|---|---|
| `NOT EXISTS (…)` | ✅ correct, planned as an Anti Join |
| `LEFT JOIN … WHERE o.id IS NULL` | ✅ same plan in PostgreSQL |
| `WHERE c.id NOT IN (SELECT customer_id FROM …)` | ❌ **if the subquery returns a single NULL, the result is empty**, because `x NOT IN (…, NULL)` is NULL, never true. It's also often planned badly (hashed subplan) |

Laravel: `Customer::query()->whereDoesntHave('orders')->get()` generates `NOT EXISTS`.

### #3 `couriers_above_average`
A CTE computes deliveries per courier, then compares against `(SELECT avg(deliveries) FROM per_courier)`.
Ask yourself (and in interviews, say it out loud): *the average over which population?* This query
averages over couriers **with at least one** delivery. Including couriers with zero deliveries
needs `couriers LEFT JOIN per_courier` with `coalesce(deliveries, 0)`.

### #4 `daily_delivery_stats`: GROUP BY
`count`, `sum`, `avg`, and `count(DISTINCT courier_id)` grouped by `created_at::date`. The `::date`
cast is fine here (it's in the SELECT/GROUP BY, not the WHERE), and the WHERE uses a sargable
`created_at >= …`.

### #5 `courier_monthly_rank`: window functions
```sql
ROW_NUMBER() OVER w, RANK() OVER w, DENSE_RANK() OVER w
WINDOW w AS (PARTITION BY month ORDER BY deliveries DESC)
```
For deliveries 50, 40, 40, 30: ROW_NUMBER = 1,2,3,4 · RANK = 1,2,2,4 · DENSE_RANK = 1,2,2,3.
A window function computes over a "window" of related rows **without collapsing them** (unlike
GROUP BY). Window functions are evaluated *after* WHERE/GROUP BY, which is why you can't filter
`WHERE rank <= 3` in the same SELECT and we wrap it in a CTE.

### #6 `latest_status_per_order`: greatest-N-per-group
```sql
SELECT DISTINCT ON (order_id) order_id, new_status, created_at
FROM delivery_order_status_history
ORDER BY order_id, created_at DESC, id DESC;
```
`DISTINCT ON` is PostgreSQL-specific: it keeps the first row of each `order_id` group according to
ORDER BY. The plan uses the `(order_id, created_at)` index plus an **Incremental Sort** (the data is
already sorted by `order_id`, so only the small groups get sorted on the remaining keys). Alternatives:

* `ROW_NUMBER() OVER (PARTITION BY order_id ORDER BY created_at DESC) = 1`: portable.
* `LEFT JOIN LATERAL (SELECT … WHERE h.order_id = o.id ORDER BY created_at DESC LIMIT 1)`: best when
  you need the latest status for *a few* orders. One index probe each.
* Denormalise: `delivery_orders.status` *is* the latest status. In real code you'd just read that.
  History is for audit.

### #7 `duplicate_customer_emails`
`GROUP BY lower(email) HAVING count(*) > 1` with `array_agg(id ORDER BY id)`. The `lower()` matters:
the seed's duplicates differ in case. Next step in a real system: merge the duplicates, then
`CREATE UNIQUE INDEX ON customers (lower(email))` (or use the `citext` type).

### #8 `courier_performance_cte`: CTEs for readability
Four named steps (`recent → delivery_minutes → volume → speed`) instead of nested subqueries.
Since **PostgreSQL 12**, a CTE that is referenced once and has no side effects is **inlined**: the
optimiser treats it like a subquery. Before 12, CTEs were always materialised ("optimisation fences").
You can force either behaviour with `WITH x AS MATERIALIZED (…)` / `NOT MATERIALIZED`. `recent` is
referenced twice here, so PostgreSQL materialises it once. That's useful.

### #9 `status_counts`: conditional aggregation
```sql
count(*) FILTER (WHERE status = 'delivered')               -- PostgreSQL / SQL standard
sum(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)      -- portable (MySQL etc.)
```
One pass over the rows produces every count. The naive alternative, one query per status, scans
the table N times.

### #10 `big_spenders_join` vs `big_spenders_exists`
Question: "customers with at least one delivered order over 19.50".

* **JOIN** multiplies rows: one customer with 3 such orders appears 3 times. So you need `DISTINCT`,
  which is an extra **Sort + Unique** over 2,232 joined rows → 2,077 customers.
* **EXISTS** is a *semi-join*: it stops at the first match per customer and never duplicates. Here
  PostgreSQL turned it into `HashAggregate` (dedupe the customer_ids first) → `Hash Join`. Both take
  ~21-23 ms on this data.

Rules of thumb: use **EXISTS** when you only need to *test* for related rows. Use **JOIN** when you
need *columns* from the related table. Modern PostgreSQL often produces similar plans, but EXISTS
states the intent and can't create duplicates by accident. (`ReportTest::test_every_catalog_query_runs`
asserts both return identical results.)

---

## 6. Transactions and concurrency

### 6.1 The race

```
Request A (order 1)                       Request B (order 2)
SELECT nearest available -> courier 7
                                          SELECT nearest available -> courier 7
UPDATE couriers SET busy WHERE id=7
UPDATE order 1 SET courier_id=7; COMMIT
                                          UPDATE couriers SET busy WHERE id=7
                                          UPDATE order 2 SET courier_id=7   ← double assignment
```

A transaction alone does **not** prevent this. Under READ COMMITTED, plain SELECTs take no locks.
You can watch it happen: `php artisan delivery:race-demo naive`:

```
[A] SELECT nearest available (no lock)          -> courier 10001
[B] SELECT nearest available (no lock)          -> courier 10001  <-- same courier!
[A] UPDATE courier busy + order 102412 assigned; COMMIT
[B] UPDATE order 102413 -> courier 10001  FAILED: SQLSTATE 23505
   ... duplicate key value violates unique constraint "delivery_orders_one_active_order_per_courier"
```

The partial unique index caught it. Without it, courier 10001 would now have two orders.

### 6.2 The fix: three layers (`CourierAssignmentService`)

```php
DB::transaction(function () {
    // 1. lock the ORDER row: concurrent assigns of the same order queue up here,
    //    and the second one sees status='assigned' and stops (409).
    $order = DeliveryOrder::lockForUpdate()->findOrFail($id);

    // 2. lock the nearest courier that nobody else is locking right now
    SELECT c.id ... WHERE c.status='available' AND ST_DWithin(...)
    ORDER BY distance LIMIT 1
    FOR UPDATE OF c SKIP LOCKED;

    UPDATE couriers SET status='busy' ...; UPDATE delivery_orders ...; INSERT history ...;
}, attempts: 3);   // retried automatically on deadlock (40P01) / serialization failure (40001)

// 3. partial UNIQUE index = the last line of defence
```

`php artisan delivery:race-demo skip-locked`:
```
[A] SELECT ... FOR UPDATE SKIP LOCKED  -> locks courier 10005
[B] SELECT ... FOR UPDATE SKIP LOCKED  -> skips 10005, locks courier 10006
No waiting, no errors, no double assignment.
```

`php artisan delivery:race-demo stress --processes=30 --couriers=10` runs 30 real OS processes
(`pcntl_fork`), each calling the real service at the same time:
```
| assigned OK | no courier (409) | unexpected errors | assigned orders | distinct couriers |
| 10          | 20               | 0                 | 10              | 10                |
```

### 6.3 Row lock flavours

| Clause | Behaviour when the row is locked by someone else |
|---|---|
| `FOR UPDATE` | **wait**. When the lock is released under READ COMMITTED, PostgreSQL re-evaluates the WHERE on the newest row version ("EvalPlanQual"). If it no longer matches (courier is now busy), the row is skipped and the next candidate is tried |
| `FOR UPDATE NOWAIT` | error `55P03` immediately |
| `FOR UPDATE SKIP LOCKED` | pretend the row isn't there. Ideal for "take any free resource" / job queues |
| `FOR NO KEY UPDATE` | weaker. Doesn't block concurrent inserts of FK-referencing rows (`FOR KEY SHARE`). Plain `UPDATE` of non-key columns takes this one |
| `FOR SHARE` | others can read-lock too, nobody can update |

`delivery:race-demo for-update` shows the waiting. We set `lock_timeout = 2s` so the demo can't hang:
```
[A] SELECT ... FOR UPDATE   -> locks courier 10003
[B] SELECT ... FOR UPDATE   -> waiting for A's row lock ...
[B] gave up after 2.1s: lock_not_available (55P03)
```
Plain `FOR UPDATE` is *correct*, but it serialises every request near a popular courier. SKIP
LOCKED keeps throughput up.

`FOR UPDATE OF c` locks only the `couriers` rows, not the joined `delivery_orders` row (which we
already locked in step 1).

### 6.4 Isolation levels in PostgreSQL

| Level | Dirty read | Non-repeatable read | Phantom | Serialization anomaly | In PostgreSQL |
|---|---|---|---|---|---|
| Read Uncommitted | (allowed by the standard) | | | | **behaves as Read Committed**. PG never shows uncommitted data |
| **Read Committed** (default) | ✗ | possible | possible | possible | each *statement* sees a fresh snapshot |
| Repeatable Read | ✗ | ✗ | ✗ (in PG) | possible | one snapshot for the whole *transaction*; concurrent update of a row you modify → error `40001` |
| Serializable | ✗ | ✗ | ✗ | ✗ | SSI: tracks read/write dependencies and aborts one transaction with `40001` if the result couldn't come from some serial order |

Could we use SERIALIZABLE instead of explicit locks? Yes: two transactions that both read
"courier 7 available" and both write would conflict, and one gets `40001`. You then **must retry**
(Laravel's `DB::transaction(..., attempts: 3)` does this for 40001/40P01). Trade-offs: no
lock-ordering thinking needed, but there are more aborts under contention, and SKIP LOCKED-style
"take the next one" behaviour isn't available. For a hot "grab a free resource" path, explicit
`FOR UPDATE SKIP LOCKED` under READ COMMITTED is the standard answer.

Laravel: `DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')` as the first statement
inside `DB::transaction()`.

### 6.5 Deadlocks

```
T1: locks order 1 ... then wants courier 7
T2: locks courier 7 ... then wants order 1       → cycle → deadlock
```

PostgreSQL checks for cycles after `deadlock_timeout` (1 s), kills one transaction with `40P01`,
and the other proceeds. How to minimise them:

1. **Same lock order everywhere.** Both `CourierAssignmentService` and
   `DeliveryOrderService::transition` lock *order first, then courier*. That's the main rule.
2. **Short transactions.** No HTTP calls, emails or queue dispatches inside them (dispatch
   `afterCommit`).
3. **Lock everything you need up front**, e.g. `SELECT … WHERE id IN (…) ORDER BY id FOR UPDATE`.
4. **SKIP LOCKED / NOWAIT** where waiting isn't required.
5. **Retry** on `40P01` (Laravel's `attempts`). A deadlock is a transient error, not a bug report.

### 6.6 Other approaches you should be able to compare

| Approach | Pros | Cons |
|---|---|---|
| `SELECT … FOR UPDATE SKIP LOCKED` (used) | exact, no extra infra, high throughput | needs care with lock order |
| Optimistic locking (`version` column, `UPDATE … WHERE id=? AND version=?`, check affected rows) | no locks held | retry storms under contention; doesn't help *choosing* a free courier |
| Conditional update `UPDATE couriers SET status='busy' WHERE id=? AND status='available'` and check affected rows = 1 | simple, atomic | still need a retry loop to pick the next courier |
| `pg_advisory_xact_lock(courier_id)` | lock arbitrary keys, not rows | easy to forget, invisible in the schema |
| Redis lock (`Cache::lock`) | works across databases | a second system that can disagree with the DB; TTL edge cases. The DB is already the source of truth |
| Single queue worker per zone | no concurrency at all | throughput limit, latency |

---

## 7. Testing strategy

* **Real PostgreSQL, not SQLite.** SQLite has no geography type, no `ST_*`, no GiST, no `SKIP LOCKED`,
  and different partial index and CHECK semantics. A green SQLite run would prove nothing.
* A separate database **`laravel_testing`**. `phpunit.xml` forces it with both `<env>` and `<server>`
  (Laravel reads `$_SERVER` first, and the Docker container already exports `DB_DATABASE=laravel`).
  `DeliveryTestCase::beforeRefreshingDatabase()` refuses to run unless the database name ends in
  `_testing`. That check runs **before** `migrate:fresh`, so a misconfiguration can't wipe dev data.
* **RefreshDatabase** (one transaction per test, rolled back) for normal tests.
* **Concurrency tests can't use RefreshDatabase.** A second connection can't see uncommitted rows.
  `ConcurrentAssignmentTest` uses `DatabaseTruncation`, opens a second PDO connection (= a second
  PHP-FPM worker), holds a row lock there and asserts the service skips it without blocking.
  Gotcha found while writing it: `DatabaseTruncation` truncates *before* each test, so the last
  test's committed rows leaked into later tests. Fixed with an explicit TRUNCATE in `tearDown`.
* Spatial assertions use real distances: at 40.4° N, 1° of latitude ≈ 111,034 m, so a courier at
  +0.044° (≈4,885 m) is inside a 5 km radius and one at +0.046° (≈5,108 m) is outside.

---

## 8. Interview question bank

**PostGIS**
1. *geometry vs geography?* Plane and degrees vs spheroid and metres. Pick geography for GPS +
   metre radii; geometry + a projected SRID for heavy local analysis.
2. *Why does `ST_DWithin` use an index and `ST_Distance < r` doesn't?* (§1.6)
3. *What does SRID 4326 mean?* WGS84 lon/lat, what GPS uses.
4. *How does a GiST index work, and why is there a recheck?* (§1.8)
5. *Nearest-N without a radius?* KNN `ORDER BY geom <-> point LIMIT n`.
6. *A colleague's points are in the wrong country. What happened?* Lat/lng swapped in `ST_MakePoint`.

**Indexes and the planner**
7. *Does PostgreSQL index foreign keys automatically?* No. Also explain why it matters for joins and for DELETE on the parent.
8. *Composite index column order?* Equality first, then range/sort; a leftmost prefix can be used.
9. *Partial index: when is it used?* When the query WHERE implies the index predicate.
10. *What is a covering index, and why `INCLUDE`?* Index-only scans; INCLUDE columns don't affect ordering or uniqueness and don't bloat upper levels.
11. *Index-only scan but `Heap Fetches` is high?* The visibility map isn't set. VACUUM.
12. *Why did the planner ignore my index?* Low selectivity, stale stats, non-sargable predicate, type mismatch/cast, a small table, a parameterised generic plan with a partial index.
13. *Bitmap heap scan: when and why?* Medium selectivity; reads heap pages in physical order; can combine indexes.
14. *Cost of indexes?* Write amplification, lost HOT updates, RAM, disk, longer VACUUM.
15. *OFFSET vs keyset?* (§4.7)
16. *What do `rows=1` estimated vs `rows=615` actual tell you?* Misestimation. Fix with ANALYZE, statistics target, extended stats.
17. *How do you add an index to a 500 GB table in production?* `CREATE INDEX CONCURRENTLY` (no write lock, can't run in a transaction, check for an INVALID index on failure).

**SQL**
18. *NOT IN vs NOT EXISTS?* The NULL trap (§5 #2).
19. *RANK vs DENSE_RANK vs ROW_NUMBER?* (§5 #5)
20. *Latest row per group, three ways.* (§5 #6)
21. *Are CTEs optimisation fences?* Not since PG 12 (unless MATERIALIZED or referenced multiple times).
22. *WHERE vs HAVING?* Row filter before grouping vs group filter after.

**Concurrency**
23. *Does a transaction prevent the double-assignment race?* No, not under READ COMMITTED without locks or constraints.
24. *FOR UPDATE vs SKIP LOCKED vs NOWAIT.* (§6.3)
25. *PostgreSQL isolation levels and their anomalies.* Also: Read Uncommitted = Read Committed in PG. (§6.4)
26. *What is a deadlock, how does PG resolve it, how do you prevent it?* (§6.5)
27. *Why also a unique constraint if you already lock?* Defence in depth: other code paths, manual SQL, future bugs.
28. *Optimistic vs pessimistic locking: when to use which?* (§6.6)

---

## 9. Exercises for you

1. Add `delivered_at` to `delivery_orders` (set it in `DeliveryOrderService::transition`), rewrite
   `daily_report` without the history join, and compare plans.
2. Change `CourierService::findNearestAvailable` to `ORDER BY current_location <-> point` while
   keeping `ST_DWithin`. Check the plan.
3. Drop `customers_lower_email_idx`, run `customer_by_email -e`, and explain the plan you see.
4. In psql, `DROP INDEX delivery_orders_one_active_order_per_courier;`, run
   `delivery:race-demo naive` and watch the double assignment succeed. Then **recreate the index**
   with the statement from migration `…000004`. (It can't be done inside `BEGIN … ROLLBACK`, because
   the demo uses other connections that wouldn't see your uncommitted DROP.)
5. Wrap the assignment in `SERIALIZABLE` without `FOR UPDATE` and run the stress demo. Count the
   `40001` retries.
