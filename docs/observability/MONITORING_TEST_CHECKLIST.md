# Monitoring Test Checklist (Task03)

Work through it top to bottom after `docker compose up -d && make monitoring-up`.
Each line: **action → expected result**. Tick the box when you have seen it
yourself. Explanations are in [OBSERVABILITY_GUIDE.md](OBSERVABILITY_GUIDE.md).

Shortcuts used below:
```bash
make lab s=<path>              # GET  /api/v1/lab/<path> with the X-Lab-Token header
make lab m=POST s=<path>       # POST
make metrics                   # print Laravel /metrics from inside the Docker network
```
Without `make` (Windows PowerShell), see "No make?" in the guide's
[Quick start](OBSERVABILITY_GUIDE.md#0-quick-start): each `make lab` is a
`curl.exe` call with the `X-Lab-Token` header.

---

## A. Stack is up

- [ ] `docker ps` → `laravel_app`, `nginx`, `postgres`, `redis`, `horizon` (healthy), `scheduler`, `pulse` are Up.
- [ ] With the profile: `prometheus`, `grafana`, `redis_exporter`, `postgres_exporter`, `php_fpm_exporter`, `cadvisor`, `docker_socket_proxy` are Up.
- [ ] `docker exec laravel_app php artisan horizon:status` → "Horizon is running."
- [ ] http://127.0.0.1:9090/targets → every target **UP** (laravel, php-fpm, redis, postgres, cadvisor, docker-containers, prometheus).
- [ ] `docker exec laravel_app php artisan test` → only the 2 known pre-existing failures (AuthTest logout, CartTest empty cart).

## B. Security / access restrictions

- [ ] `curl -i http://localhost:8000/metrics` → **404**.
- [ ] `curl -i -H "Metrics-Internal: 1" http://localhost:8000/metrics` → **404** (header can't forge it).
- [ ] `curl -m 3 http://localhost:8080/metrics` → connection refused (port not published).
- [ ] `make metrics` → Prometheus text (`# TYPE laravel_http_requests_total counter`).
- [ ] `curl -i http://localhost:8000/api/v1/lab/slow` (no token) → **404**.
- [ ] Same with `-H "X-Lab-Token: wrong"` → **404**.
- [ ] Run `make lab s="cpu?seconds=1"` 6 times within a minute → the 6th returns **429**.
- [ ] `docker compose port prometheus 9090` → `127.0.0.1:9090` (not `0.0.0.0`). Same for grafana `3000` → `127.0.0.1:3001`.
- [ ] `docker exec laravel_prometheus wget -qO- --post-data= http://docker-socket-proxy:2375/containers/laravel_app/stop` → **403 Forbidden**.
- [ ] Grafana in a private window → login page (no anonymous access); first login with `admin/admin` asks for a new password.
- [ ] Telescope is not loaded outside `local`: covered by `DashboardAccessTest`.

## C. Laravel tools

**Telescope** (http://localhost:8000/telescope)
- [ ] `make lab s=n-plus-one` → Requests → that request → Queries: **101** queries, duplicates highlighted.
- [ ] `make lab s="n-plus-one?fixed=1"` → **3** queries.
- [ ] Requests tab: lab requests carry the tag `lab`.
- [ ] Request headers of a lab call show `x-lab-token` as `********` (hidden).
- [ ] `make lab s=exception` → Exceptions tab shows `LabScenarioException` with stack trace.

**Pulse** (http://localhost:8000/pulse)
- [ ] Servers card shows CPU/memory (fed by the `pulse` container).
- [ ] `make lab s=slow` → Slow Requests lists `GET /api/v1/lab/slow` (> 1000 ms).
- [ ] `make lab s=expensive-sql` → Slow Queries shows the `ST_Distance` query.
- [ ] `make lab s=exception` → Exceptions card counts it.

**Horizon** (http://localhost:8000/horizon)
- [ ] Dashboard: supervisors `supervisor-1` and `lab` running.
- [ ] `make lab m=POST s=jobs/backlog` → lab queue ~1000 jobs, wait time rising; drains in ~150 s; the `notifications` queue still processes jobs meanwhile.
- [ ] `make lab m=POST s="jobs/failing?mode=flaky"` → attempts 1–2 fail, attempt 3 completes (Recent Jobs).
- [ ] `make lab m=POST s="jobs/failing?mode=permanent"` → after ~20 s in **Failed Jobs** with `503 Service Unavailable`, 3 attempts.
- [ ] `make lab m=POST s=jobs/failing/fix` → Retry in Horizon → job completes; log shows "Lab payment charged".
- [ ] `make lab m=POST s="jobs/long-running?seconds=40"` → reserved ~40 s, log shows progress every 10 s, then completed.
- [ ] *(optional, 5+ min)* `seconds=320` → killed at 300 s, failed with `TimeoutExceededException`.

**Logs**
- [ ] `make lab m=POST s=logs` → note `lab_run_id`; `grep <id> storage/logs/laravel.log` → 8 lines, debug … emergency.
- [ ] Telescope → Logs shows the same 8 entries with levels.

## D. Sentry (needs `SENTRY_LARAVEL_DSN` in `.env.docker`)

- [ ] `docker compose up -d app horizon scheduler pulse` then `docker exec laravel_nginx nginx -s reload`.
- [ ] `docker exec laravel_app php artisan sentry:test` → event appears in Sentry Issues.
- [ ] `make lab s=exception` → issue `LabScenarioException` with request + breadcrumbs.
- [ ] `make lab s="exception?type=query"` → separate issue `QueryException`.
- [ ] Permanent failing job → issue from the queue worker.
- [ ] Create an order with no courier nearby (`POST /api/v1/delivery/orders/{id}/assign` → 409) → **no** Sentry issue (ShouldntReport).
- [ ] `make lab s=slow` → Performance shows a ~3 s transaction.

## E. Prometheus

- [ ] Graph: `sum(rate(laravel_http_requests_total[5m]))` > 0 after some traffic.
- [ ] `laravel_http_requests_total` uses route templates (`api/v1/delivery/orders/{order}`), never IDs; unknown URLs → `route="unmatched"`.
- [ ] `laravel_queue_size{queue="lab",state="pending"}` ≈ 1000 right after the backlog scenario, then falls to 0.
- [ ] `laravel_queue_jobs_total{status="failed"}` increases after the permanent failing job.
- [ ] `container:memory_working_set_bytes` returns one series per `laravel_*` container **with a `name` label**.
- [ ] Status → Rules: recording rules `container:*` evaluate without errors.
- [ ] Alerts: run `make lab s=exception` ~10 times → `HighErrorRate` **pending** → **firing** after 2 min.
- [ ] After the backlog: `QueueBacklog` goes pending (fires only if > 100 pending for 5 min — use `ms=1000` to see it fire).

## F. Grafana — dashboard "Laravel – Overview"

- [ ] Dashboards → Laravel → Laravel – Overview loads, no red panel errors.
- [ ] Requests / s and requests by status move when you generate traffic.
- [ ] Error rate stat turns orange/red after exception scenarios.
- [ ] *p95 latency by route* shows `api/v1/lab/slow` at ~3–5 s.
- [ ] *5xx / s by route* shows `api/v1/lab/exception`.
- [ ] *Queue size* shows the backlog curve; *Jobs / s by outcome* shows processed and failed.
- [ ] *Job duration p95* shows `LabLongRunningJob` ≈ its seconds.
- [ ] `make lab s="cpu?seconds=8"` → *Container CPU*: `laravel_app` near 1.0.
- [ ] `make lab s="memory?mb=128"` → *Peak PHP memory per request* for `api/v1/lab/memory` ≈ 150 MB.
- [ ] Postgres connections, cache hit ratio and Redis panels show data.

## G. Scenario summary (one tick per scenario: seen in at least two tools)

| # | Scenario | Command | Tools where you saw it | ✓ |
|---|---|---|---|---|
| 1 | Slow request | `make lab s=slow` | Pulse, Grafana p95, Telescope, Sentry | [ ] |
| 2 | N+1 | `make lab s=n-plus-one` | Telescope (101 vs 3) | [ ] |
| 3 | Expensive SQL | `make lab s=expensive-sql` | Telescope, Pulse Slow Queries, Grafana | [ ] |
| 4 | Exception | `make lab s=exception` | Sentry, Telescope, Pulse, Grafana 5xx, alert | [ ] |
| 5 | Failed job | `make lab m=POST s="jobs/failing?mode=permanent"` | Horizon, Grafana, alert, Sentry | [ ] |
| 6 | Backlog | `make lab m=POST s=jobs/backlog` | Horizon, Grafana queue size, Prometheus | [ ] |
| 7 | Long-running job | `make lab m=POST s="jobs/long-running?seconds=90"` | Horizon, Grafana job duration, logs | [ ] |
| 8 | Memory | `make lab s="memory?mb=128"` / `mb=300` | Grafana memory, Telescope, Sentry (fatal) | [ ] |
| 9 | CPU | `make lab s="cpu?seconds=5"` | Grafana CPU, Pulse Servers, FPM panel | [ ] |
| 10 | Logs | `make lab m=POST s=logs` | laravel.log, Telescope Logs | [ ] |

## H. Clean up after testing

- [ ] `docker exec laravel_app php artisan horizon:clear redis-long --queue=lab` (only if a backlog is still pending).
- [ ] `docker exec laravel_app php artisan queue:flush` removes **all** failed jobs — or forget only lab ones by uuid (`queue:forget <uuid>`).
- [ ] `make monitoring-down` to free ~150 MB RAM when not studying metrics.
