# Task02 – Location-Based Courier Management (PostgreSQL + PostGIS)

A courier dispatch backend built inside *laravel-testing-lab* to practise PostgreSQL,
query optimisation, PostGIS and concurrency control.

> **Start with [`LEARNING_GUIDE.md`](LEARNING_GUIDE.md).** This README only covers setup and usage.

## What's inside

| Area | Where |
|---|---|
| Schema (PostGIS, CHECK constraints, partial unique index) | `database/migrations/2026_11_17_00000{1..5}_*.php` |
| Performance indexes (separate on purpose: you can roll them back) | `database/migrations/2026_11_17_000010_add_delivery_performance_indexes.php` |
| Bulk seeder (~100k orders in seconds, pure SQL) | `database/sql/delivery_seed.sql`, `database/seeders/DeliverySeeder.php` |
| Business logic | `app/Services/Delivery/*` |
| Concurrency-safe assignment | `app/Services/Delivery/CourierAssignmentService.php` |
| All learning SQL queries (advanced + optimisation) | `app/Services/Delivery/Sql/QueryCatalog.php` |
| HTTP layer | `app/Http/{Controllers/Api/V1,Requests,Resources}/Delivery/*`, `routes/api.php` |
| Tests (real PostgreSQL) | `tests/Feature/Delivery/*` |
| EXPLAIN ANALYZE before/after | `docs/delivery/explain-before.md`, `docs/delivery/explain-after.md` |

### Deviations from the task text (and why)

* **Table `delivery_orders`, not `orders`.** The project already has an e-commerce `orders` table.
  The history table is `delivery_order_status_history`.
* **URLs are `/api/v1/delivery/...`, not `/api/...`.** The project uses an `api/v1` prefix and
  `/api/v1/orders` is already taken by the shop.
* **Extra endpoints:** `GET /orders/{id}`, `PATCH /orders/{id}/status` (needed to finish an order and
  free the courier), `GET /orders/pending-in-area`, `GET /reports/top-couriers`.
* **No auth on these routes**, so they are easy to curl. In production they'd go behind `auth:sanctum` with abilities.
* **`delivery_fee` is computed** by PostGIS from the pickup→delivery distance (2.00 + 0.80/km).
* Laravel 11 / PHP 8.2 / PostgreSQL 17 + PostGIS 3.5, the versions already used in this project.

## Setup

```bash
# 1. Start the stack. The postgres service now uses the postgis/postgis:17-3.5-alpine image.
docker compose up -d app nginx postgres redis

# 2. Create the test database (first-time volumes get it from docker/postgres/init automatically)
docker exec laravel_postgres psql -U laravel -d laravel -c "CREATE DATABASE laravel_testing"

# 3. Migrate and seed (~40 s)
docker exec laravel_app php artisan migrate
docker exec laravel_app php artisan db:seed --class=DeliverySeeder
```

The seed is deterministic (`setseed(0.42)`), so you should see the same distribution as the docs:
20,151 customers (151 duplicated emails, 2,215 who never ordered), 10,000 couriers (5,514 available /
2,411 busy / 2,075 offline), 102,411 orders and 367,655 history rows, all around Baku (40.4093, 49.8671).

## API

Base URL `http://localhost:8000/api/v1/delivery`. Send `Accept: application/json`.

| Method | Path | Body / query |
|---|---|---|
| POST | `/orders` | `{"customer_id":5,"pickup":{"lat":40.4093,"lng":49.8671},"delivery":{"lat":40.3777,"lng":49.892}}` |
| GET | `/orders` | `status, customer_id, courier_id, from, to, per_page, cursor` (cursor pagination) |
| GET | `/orders/{id}` | includes courier and status history |
| POST | `/orders/{id}/assign` | optional `{"radius":5000}` → nearest available courier |
| PATCH | `/orders/{id}/status` | `{"status":"picked_up"\|"delivered"\|"cancelled"}` |
| GET | `/orders/pending-in-area` | box: `min_lat,min_lng,max_lat,max_lng`, or circle: `lat,lng,radius` |
| PATCH | `/couriers/{id}/location` | `{"lat":40.40,"lng":49.85}` |
| GET | `/couriers/nearest` | `lat, lng, radius=5000, limit=5` |
| GET | `/reports/daily` | `from, to` (default: last 30 days); counts and average delivery minutes |
| GET | `/reports/monthly` | `from, to` (default: last 12 months); revenue |
| GET | `/reports/top-couriers` | `limit=10` |

Errors: validation → 422; business-rule conflicts → 409 with `{"message","error"}` where `error` is
`no_available_courier`, `invalid_status_transition` or `courier_already_busy`.

Quick tour:

```bash
B=http://localhost:8000/api/v1/delivery; H='-H Accept:application/json -H Content-Type:application/json'
curl $H "$B/couriers/nearest?lat=40.4093&lng=49.8671&limit=3"
ID=$(curl -s $H -X POST $B/orders -d '{"customer_id":5,"pickup":{"lat":40.4093,"lng":49.8671},"delivery":{"lat":40.3777,"lng":49.892}}' | sed -E 's/.*"id":([0-9]+).*/\1/;q')
curl $H -X POST  $B/orders/$ID/assign
curl $H -X PATCH $B/orders/$ID/status -d '{"status":"picked_up"}'
curl $H -X PATCH $B/orders/$ID/status -d '{"status":"delivered"}'
curl $H $B/orders/$ID
```

## Learning commands

```bash
# List every learning query (11 advanced SQL exercises + 17 optimisation queries)
docker exec laravel_app php artisan delivery:sql

# Run one and print the rows
docker exec laravel_app php artisan delivery:sql courier_monthly_rank

# EXPLAIN (ANALYZE, BUFFERS) one
docker exec laravel_app php artisan delivery:sql nearest_courier_dwithin --explain

# Before/after experiment
docker exec laravel_app php artisan migrate:rollback --step=1      # drop performance indexes
docker exec laravel_app php artisan delivery:sql --group=optimization --explain --markdown > docs/delivery/explain-before.md
docker exec laravel_app php artisan migrate                         # add them back
docker exec laravel_app php artisan delivery:sql --group=optimization --explain --markdown > docs/delivery/explain-after.md

# Concurrency demos
docker exec laravel_app php artisan delivery:race-demo naive
docker exec laravel_app php artisan delivery:race-demo for-update
docker exec laravel_app php artisan delivery:race-demo skip-locked
docker exec laravel_app php artisan delivery:race-demo stress --processes=30 --couriers=10

# psql, for poking around yourself
docker exec -it laravel_postgres psql -U laravel -d laravel
```

## Tests

```bash
docker exec laravel_app php artisan test tests/Feature/Delivery
```

30 tests, on **PostgreSQL** in the `laravel_testing` database (`phpunit.xml` forces it). The base
test case refuses to run against any database whose name doesn't end in `_testing`. It checks this
*before* `migrate:fresh`, so your seeded dev data stays safe.
