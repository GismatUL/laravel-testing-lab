# Observability Guide – laravel-testing-lab (Task03)

How professional teams monitor a Laravel application in production, learned
on a working lab: every tool below is installed (or deliberately *not*
installed, with the reason), configured in this repository and exercised by
ten reproducible production problems.

Companion document: [MONITORING_TEST_CHECKLIST.md](MONITORING_TEST_CHECKLIST.md)
(step-by-step verification of everything in this guide).

---

## Contents

0. [Quick start](#0-quick-start)
1. [Concepts: monitoring vs observability, metrics vs logs vs traces](#1-concepts)
2. [Architecture of this lab](#2-architecture-of-this-lab)
3. [Which tool answers which question (overlaps)](#3-which-tool-answers-which-question)
4. [Tools](#4-tools)
   - 4.1 [Laravel logging + centralized logging](#41-laravel-logging-and-centralized-logging)
   - 4.2 [Laravel Telescope](#42-laravel-telescope)
   - 4.3 [Laravel Pulse](#43-laravel-pulse)
   - 4.4 [Redis queues + Laravel Horizon](#44-redis-queues--laravel-horizon)
   - 4.5 [Sentry](#45-sentry)
   - 4.6 [Prometheus (+ exporters)](#46-prometheus)
   - 4.7 [Grafana](#47-grafana)
   - 4.8 [New Relic](#48-new-relic-documented-not-installed)
   - 4.9 [Datadog](#49-datadog-documented-not-installed)
   - 4.10 [OpenTelemetry](#410-opentelemetry-documented-not-installed)
   - 4.11 [RabbitMQ](#411-rabbitmq-documented-not-installed)
5. [The 10 lab scenarios: detect, investigate, fix](#5-the-10-lab-scenarios)
6. [Real problems hit while building this lab](#6-real-problems-hit-while-building-this-lab)
7. [From lab to production](#7-from-lab-to-production)
8. [Cross-cutting interview questions](#8-cross-cutting-interview-questions)

---

## 0. Quick start

```bash
docker compose up -d                          # app, nginx, postgres, redis, horizon, scheduler, pulse
make monitoring-up                            # + prometheus, grafana, exporters, cadvisor (profile "monitoring")
make lab s=                                   # list the 10 lab scenarios
make lab s=slow                               # run one (GET)
make lab m=POST s=jobs/backlog                # run one (POST)
```

**No `make` (plain Windows)?** Every `make lab ...` is just a curl with the token:

```powershell
# PowerShell
$T = ((Select-String '^LAB_TOKEN=' .env.docker).Line -split '=', 2)[1]
curl.exe -s -H "X-Lab-Token: $T" -H "Accept: application/json" "http://localhost:8000/api/v1/lab/slow"
curl.exe -s -X POST -H "X-Lab-Token: $T" -H "Accept: application/json" "http://localhost:8000/api/v1/lab/jobs/backlog"
docker compose --profile monitoring up -d                          # = make monitoring-up
docker exec laravel_nginx wget -qO- http://127.0.0.1:8080/metrics  # = make metrics
```

| What | URL | Access |
|---|---|---|
| Application | http://localhost:8000 | public |
| Telescope | http://localhost:8000/telescope | `local` env only (not even installed in production) |
| Pulse | http://localhost:8000/pulse | `local`, or e-mail in `OBSERVABILITY_ADMIN_EMAILS` |
| Horizon | http://localhost:8000/horizon | `local`, or e-mail in `OBSERVABILITY_ADMIN_EMAILS` |
| Sentry | https://sentry.io (cloud) | your account; set `SENTRY_LARAVEL_DSN` in `.env.docker` |
| Prometheus | http://127.0.0.1:9090 | localhost only |
| Grafana | http://127.0.0.1:3001 | `admin` / `admin` → forced change at first login |
| Laravel `/metrics` | `nginx:8080` (Docker network only) | `make metrics` to look at it |
| Lab endpoints | http://localhost:8000/api/v1/lab/* | `X-Lab-Token` header, `LAB_ENABLED=true`, never in production |

Branch history (one commit per phase):

```
bf809c4  phase 2  Telescope (local only) + Pulse, dashboard access rules
cdcd53a  phase 3  Horizon worker pools, scheduler, queue fixes
ab15a36           Docker: Postgres/PostGIS, Horizon/scheduler/pulse containers
846bf38  phase 4  Sentry
fd12085  phase 5  Prometheus metrics, exporters, Grafana dashboard
a82a2b2  phase 6  monitoring lab: 10 scenarios
```

---

## 1. Concepts

### 1.1 Monitoring vs observability

- **Monitoring** answers questions you knew to ask in advance: "is the error
  rate above 5 %?", "is the queue longer than 100?". Dashboards and alerts.
- **Observability** is the ability to answer questions you did *not* know you
  would need to ask: "why are only orders from Sumqayıt slow since 14:05?".
  It needs rich, correlated data (high-cardinality logs and traces) that you
  can slice after the fact.

Monitoring tells you **that** something is wrong; observability lets you find
out **why** without shipping new code.

### 1.2 The three pillars: metrics, logs, traces

| | Metrics | Logs | Traces |
|---|---|---|---|
| What | Numbers aggregated over time | Discrete events with text + context | One request's path through the system, as timed spans |
| Example | `laravel_http_requests_total{route,status}` = 14 302 | `local.ERROR: payment capture failed {"order_id":123}` | `POST /orders` 1.8 s = 3 SQL (40 ms) + Stripe call (1.6 s) + Redis (2 ms) |
| Cost | Cheap, fixed size (one series per label combination) | Grows with traffic; the most expensive at scale | Expensive; usually *sampled* (keep 5–20 %) |
| Good at | Trends, alerting, "how much / how often" | "What exactly happened to this request/user" | "Where did the time go", across services |
| Bad at | Explaining one specific request | Aggregates over millions of events | Long-term trends |
| In this lab | Prometheus + Grafana, Pulse, Horizon metrics | `storage/logs/laravel.log`, Telescope Logs | Sentry Performance, Telescope (per request, single service) |

**The investigation loop** a senior engineer runs:
**metric** (alert: p95 of `/orders` is 3 s) → **trace** (time is spent in the
SQL span) → **log / query detail** (the exact SQL and bindings for that
request) → fix → **metric** confirms recovery.

### 1.3 What to measure: Golden signals, RED, USE

- **Four golden signals** (Google SRE book): latency, traffic, errors, saturation.
- **RED** for request-driven services: **R**ate, **E**rrors, **D**uration.
  Our Grafana row "Golden signals" is exactly RED.
- **USE** for resources (CPU, memory, disk, worker pools): **U**tilisation,
  **S**aturation, **E**rrors. Example: PHP-FPM — utilisation = active
  workers / max children, saturation = `phpfpm_listen_queue` (requests waiting
  for a worker), errors = `max_children_reached`.

### 1.4 Words you will hear

| Term | Meaning |
|---|---|
| **Cardinality** | Number of distinct label combinations of a metric. `route="orders/{order}"` = 1 series; `path="/orders/123"` = one series per order → memory explosion. Never put IDs, e-mails or raw URLs in metric labels. |
| **Sampling** | Recording only a fraction (traces, Pulse entries) to control cost. Errors are usually kept at 100 %. |
| **Percentiles (p50/p95/p99)** | p95 = 1 s means 95 % of requests were faster than 1 s. Averages hide the slow tail; users feel the tail. |
| **SLI / SLO / SLA** | Indicator (measured: "% of requests < 300 ms"), Objective (internal target: 99 %), Agreement (contract with penalties). |
| **Error budget** | 100 % − SLO. With a 99.9 % SLO you may fail 43 min/month; spend it on risky deploys, stop when it is gone. |
| **Pull vs push** | Prometheus *pulls* (scrapes) targets; Sentry/Datadog/New Relic agents *push* to a backend. |
| **APM** | Application Performance Monitoring: traces + metrics + errors from inside the app, usually by an agent (New Relic, Datadog, Sentry Performance). |
| **Correlation ID** | One ID attached to every log line, span and job of a request so you can join them (`lab_run_id` in scenario 10). |

---

## 2. Architecture of this lab

```
                         host (your laptop)
   :8000 ─────────────┐        127.0.0.1:9090        127.0.0.1:3001
                      │              │                     │
┌─────────────────────┼──────────────┼─────────────────────┼──────── Docker network "laravel" ──┐
│                     ▼              ▼                     ▼                                    │
│   nginx :80  (public app)     prometheus ───────────► grafana                                   │
│   nginx :8080 (/metrics only, │   │ scrapes every 15 s   (dashboards provisioned from git)    │
│        not published)  ◄──────┘   ├──► php-fpm-exporter ──FastCGI /fpm-status──► app          │
│        │                          ├──► redis-exporter ───────────────────────► redis          │
│        ▼                          ├──► postgres-exporter ────────────────────► postgres       │
│   app (php-fpm) ──┐               ├──► cadvisor (per-container CPU / memory from cgroups)     │
│   horizon  ───────┼──► redis      └──► docker-socket-proxy (read-only: container names)       │
│   scheduler ──────┤    db0 app/queues/sessions, db1 cache, db3 Prometheus counters            │
│   pulse:check ────┘                                                                            │
│        └───────────► postgres (+ telescope_entries, pulse_* tables)                           │
│   app / horizon ──────────────────────────────────────────────► sentry.io (HTTPS, outbound)   │
└────────────────────────────────────────────────────────────────────────────────────────────────┘
```

| Container | Why it exists | Memory (measured) |
|---|---|---|
| `app` | php-fpm, serves HTTP | ~10–50 MB |
| `horizon` | queue workers (`supervisor-1` + `lab` pool) | ~250–290 MB |
| `scheduler` | `schedule:work` (horizon snapshot, pruning) | ~40 MB |
| `pulse` | `pulse:check` (Servers card) | ~40 MB |
| `prometheus` | metrics TSDB, 7 days / 1 GB retention | ~35–55 MB |
| `grafana` | dashboards | ~50–60 MB |
| exporters + cadvisor + proxy | translate Redis/PG/FPM/cgroups to metrics | ~10–25 MB each |

One process per container: each can be restarted, scaled, limited
(`mem_limit`) and monitored on its own. The metrics stack is an opt-in
Compose **profile** so a normal `docker compose up -d` stays light.

**Access rules (Task03: no public monitoring endpoints):**

| Endpoint | Protection |
|---|---|
| `/metrics` | Only on nginx port 8080, which is not published. That server block sets FastCGI param `METRICS_INTERNAL=1`; `EnsureInternalMetricsRequest` returns 404 otherwise. A client header becomes `HTTP_METRICS_INTERNAL`, so it cannot be forged. Port 80 also has `location = /metrics { return 404; }`. |
| Prometheus, Grafana | Bound to `127.0.0.1` only. Grafana: no anonymous access, no sign-up. |
| Exporters, cadvisor, socket proxy | No published ports at all. |
| Telescope | Not registered outside `local` (and a `require-dev` package: absent from production installs). |
| Pulse, Horizon | Gates `viewPulse` / `viewHorizon`: `local` env, or an allow-listed admin e-mail. |
| Docker API | Prometheus never touches `docker.sock`; it uses a proxy allowing only `GET /containers` and `GET /networks` (POST → 403). |
| Lab endpoints | 404 unless `LAB_ENABLED`, never in production, `X-Lab-Token` compared with `hash_equals`, rate-limited. |

---

## 3. Which tool answers which question

| Question | Telescope | Pulse | Horizon | Logs | Sentry | Prometheus + Grafana | New Relic / Datadog |
|---|---|---|---|---|---|---|---|
| Which SQL did *this* request run? | **best** | – | – | partial | spans | – | yes |
| Which endpoints are slow overall? | per request | **yes** | – | – | **yes** | **yes** (p95 by route) | **yes** |
| Error rate right now / alert me | – | count | – | – | **yes** (issues + alerts) | **yes** (alert rules) | **yes** |
| Full stack trace + who was affected | yes (local) | – | failed jobs | yes | **best** | – | yes |
| Is the queue backing up? | – | Queues card | **best** | – | – | **yes** (+ alert) | yes |
| Retry a failed job | – | – | **yes** | – | – | – | – |
| CPU / memory per container | – | per server | – | – | – | **yes** (cAdvisor) | **yes** |
| Redis / Postgres health | – | – | Redis only | – | – | **yes** (exporters) | **yes** (integrations) |
| Long-term trends (weeks) | no (pruned 48 h) | 7 days | 24 snapshots | retention | plan-dependent | **yes** | **yes** |
| Run in production? | **no** | yes (sampled) | yes | yes | yes | yes | yes (paid) |

**Overlaps and how to resolve them**

- *Telescope vs Pulse*: Telescope records **every** request in detail
  (debugger, development); Pulse stores **aggregates** cheap enough for
  production. Same family, different job.
- *Pulse vs Prometheus/Grafana*: Pulse is zero-config and Laravel-aware but
  keeps 7 days, has fixed cards and no alerting. Prometheus/Grafana is
  generic, alertable, long-term, and also covers Redis/Postgres/containers.
  Teams often keep Pulse for developers and Prometheus for on-call.
- *Horizon metrics vs Prometheus queue metrics*: Horizon is for operating the
  queue (retry, tags, pause); Prometheus is for alerting and correlation with
  everything else. We export queue size/duration to Prometheus for that reason.
- *Sentry vs New Relic/Datadog*: Sentry is error-first (with tracing added);
  New Relic/Datadog are platform-first (infra + APM + logs) with errors as one
  feature. Many companies run Sentry + Prometheus/Grafana instead of one
  expensive all-in-one platform.

**Recommendation used here**

- Local learning: Telescope + Pulse + Horizon + Sentry (free tier) +
  Prometheus/Grafana. All free, all runnable on a laptop.
- Small/medium production team: Pulse + Horizon + Sentry + Prometheus/Grafana
  (or Grafana Cloud) + centralized logs (Loki or a managed service).
- Large org / many services: OpenTelemetry instrumentation feeding either a
  vendor (Datadog, New Relic) or the Grafana stack (Mimir/Loki/Tempo).

---

## 4. Tools

Each section: **What · Why · Installation · Configuration · How to test ·
Use cases · Production issues · Interview Q&A.**

### 4.1 Laravel logging and centralized logging

**What.** Laravel's `Log` facade on top of Monolog: channels (`single`,
`daily`, `stack`, `stderr`, `syslog`, `slack`, ...) and eight PSR-3 levels:
`debug < info < notice < warning < error < critical < alert < emergency`.

**Why.** Logs are the narrative of what happened, with business context
(order id, courier id) metrics can't hold.

**Installation.** Built in.

**Configuration (this project).**
- `config/logging.php`: default channel `stack` → `single`
  (`storage/logs/laravel.log`), `LOG_LEVEL=debug` locally.
- Exceptions are logged automatically when *reported*. Business outcomes are
  not: `App\Exceptions\Delivery\DeliveryException` implements
  `ShouldntReport` (a "no courier within 5 km" 409 is not an error).
- `Log::withContext([...])` adds fields to every following line of the
  request on that channel (scenario 10 uses `lab_run_id`).
  `Log::shareContext()` does the same for all channels. To carry an ID into
  queued jobs use Laravel 11's `Context` facade
  (`Context::add('request_id', ...)`): it is added to every log line *and*
  serialized into jobs dispatched during the request.

**How to test.** `make lab m=POST s=logs` → `grep <lab_run_id> storage/logs/laravel.log`, or Telescope → Logs.

**Centralized logging concepts.**
- On many servers/containers you cannot `ssh && tail`. Ship logs to one place:
  - **ELK/OpenSearch** (Elasticsearch + Logstash/Beats + Kibana): full-text
    index of every field; powerful, heavy, expensive at scale.
  - **Grafana Loki** (+ Promtail/Grafana Alloy): indexes only *labels*
    (`app`, `env`, `level`), stores raw lines compressed in object storage,
    query with LogQL. Much cheaper; pairs with Prometheus/Grafana.
  - Managed: Datadog Logs, New Relic Logs, CloudWatch, Papertrail, Better Stack.
- In containers, log to **stdout/stderr** (`LOG_CHANNEL=stderr`) and let the
  platform collect; files inside containers disappear with the container.
- Use **structured JSON** (`'formatter' => Monolog\Formatter\JsonFormatter::class`)
  so fields are queryable instead of regex-parsed.
- Always include a **correlation/request ID**; never log secrets, tokens,
  passwords, card numbers or full personal data (GDPR).
- Decide **retention** per level (e.g. debug 3 days, error 90 days).

**Use cases.** Audit trails ("who cancelled order 42?"), debugging a single
customer's complaint, security investigations.

**Production issues.**
- `LOG_LEVEL=debug` in production → disk full, huge bills.
- `single` file never rotated → multi-GB file; use `daily` or stdout.
- Logging inside tight loops; logging whole models (PII + size).
- Logs without context ("Payment failed") → useless.
- File permission errors when CLI (root) and FPM (www-data) both write the file.

**Interview Q&A.**
- *Q: Difference between `error` and `critical`?* — `error`: an operation
  failed but the app works (one payment failed). `critical`: a component is
  unavailable (payment provider down for everyone). Alert routing usually
  differs (ticket vs page).
- *Q: Why not just use logs for everything?* — Aggregating millions of lines
  to compute a rate is slow and expensive; metrics are pre-aggregated and
  cheap. Logs explain, metrics detect.
- *Q: Log to files or stdout in Kubernetes/Docker?* — stdout/stderr; the
  container runtime and a collector (Fluent Bit, Alloy, Datadog Agent) ship it.
- *Q: How do you trace one request across HTTP and a queued job?* — Generate
  a request ID in middleware, `Context::add('request_id', $id)` (appears on
  every log line and travels inside dispatched jobs), return it in a response
  header, include it in Sentry tags.

---

### 4.2 Laravel Telescope

**What.** A debug assistant that records everything an app does: requests,
queries (with bindings and duration), exceptions, logs, jobs, mail,
notifications, cache, Redis commands, events, gates, scheduled tasks,
dumps — browsable per request at `/telescope`.

**Why.** Fastest way to answer "what did this request actually do?" while
developing: N+1 queries, duplicated queries, slow queries, unexpected events.

**Installation (done in phase 2).**
```bash
composer require laravel/telescope --dev
php artisan telescope:install && php artisan migrate
```
`composer.json → extra.laravel.dont-discover: ["laravel/telescope"]`
prevents auto-registration, so production (`composer install --no-dev`)
never boots it.

**Configuration (this project).**
- `app/Providers/ObservabilityServiceProvider.php` registers Telescope only
  when the class exists **and** the env is in
  `config('observability.telescope_environments')` (`['local']`).
- `app/Providers/TelescopeServiceProvider.php`:
  - filter: everything in `local`; elsewhere only exceptions, failed
    requests/jobs, scheduled tasks, monitored tags;
  - tags requests to `/api/v1/lab/*` with `lab`;
  - hides `password`, `token`, `_token` params and `authorization`,
    `cookie`, `x-csrf-token`, `x-lab-token` headers;
  - `viewTelescope` gate: allow-listed admin e-mails.
- Scheduler: `telescope:prune --hours=48` daily.
- Tests: `TELESCOPE_ENABLED=false` in `phpunit.xml`.

**How to test.** `make lab s=n-plus-one` → Telescope → Requests → the
request → *Queries* tab shows 101 queries, 50 identical
`select * from couriers where id = ?`. Telescope → Exceptions after
`make lab s=exception`.

**Use cases.** N+1 hunting, checking which events/listeners fire, inspecting
outgoing mail without sending it, seeing a job's payload.

**Production issues.**
- Enabled in production: every request writes several rows to
  `telescope_entries` → DB bloat, slower requests, and a dashboard full of
  secrets (request bodies, SQL bindings). Keep it `local`-only (as here) or,
  if you must, enable only exception/failed-job watchers with sampling.
- Forgetting `telescope:prune` → table grows to millions of rows.
- Gate left open (default allows only `local` — but a misconfigured
  `APP_ENV=local` on a server exposes everything).

**Interview Q&A.**
- *Q: Telescope or Pulse in production?* — Pulse. Telescope stores every
  entry in detail; Pulse stores sampled aggregates designed for production load.
- *Q: How would you find an N+1 with Telescope?* — Open the request, Queries
  tab, look for the same statement repeated N times with different bindings;
  fix with `with()`; confirm the count drops. Add `Model::preventLazyLoading()`
  in non-production to make it throw.
- *Q: Why `--dev` + `dont-discover`?* — So the package is not even installed
  in production, and when installed it only boots where we explicitly allow.

---

### 4.3 Laravel Pulse

**What.** A production-grade, low-overhead performance dashboard at `/pulse`:
Servers (CPU/mem/disk), Application Usage (top users), Exceptions, Queues,
Slow Requests, Slow Jobs, Slow Queries, Slow Outgoing Requests, Cache hit/miss.

**Why.** "At a glance" health for the whole team without running a metrics
stack; Laravel-aware (knows routes, jobs, users).

**Installation (phase 2).**
```bash
composer require laravel/pulse
php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"
php artisan migrate
```

**Configuration (this project).**
- `config/pulse.php`: thresholds — slow request/job/query/outgoing
  request = 1000 ms (`PULSE_SLOW_*_THRESHOLD`). Storage: the main Postgres
  DB (`pulse_entries`, `pulse_aggregates`, `pulse_values`).
- `pulse` container runs `php artisan pulse:check` (feeds the Servers card).
- Gate `viewPulse`: `local` env **or** an e-mail in
  `OBSERVABILITY_ADMIN_EMAILS` (our gate replaces Pulse's default, so the
  local rule had to be kept explicitly — we hit that 403).
- Dashboard layout: `resources/views/vendor/pulse/dashboard.blade.php`.
- Tests: `PULSE_ENABLED=false`.

**How to test.** `make lab s=slow` and `make lab s=expensive-sql` → Pulse →
Slow Requests / Slow Queries. `make lab s="cpu?seconds=8"` → Servers card.

**Use cases.** Weekly performance review ("top 10 slow endpoints"), spotting
a user hammering the API, checking cache hit ratio after a change.

**Production issues.**
- High traffic: every recorded entry is a DB write. Use
  `PULSE_INGEST_DRIVER=redis` + a `php artisan pulse:work` process to buffer,
  and sample rates (`PULSE_SLOW_REQUESTS_SAMPLE_RATE=0.1`, etc.).
- Storing Pulse in the same DB as the app adds load: use a separate DB
  connection (`PULSE_DB_CONNECTION`).
- `pulse:check` not running → empty Servers card (one per server, with a
  unique `PULSE_SERVER_NAME`).
- Only 7 days of history; no alerting.

**Interview Q&A.**
- *Q: How does Pulse stay cheap?* — Pre-aggregates into time buckets, samples,
  optional Redis ingest buffer, trims old data.
- *Q: Pulse shows a slow route, what next?* — Reproduce locally with
  Telescope (or look at a Sentry trace) to see *why* it is slow; Pulse says
  *what* is slow.

---

### 4.4 Redis queues + Laravel Horizon

**What.** Redis is the queue backend (lists + sorted sets per queue);
**Horizon** is a supervisor and dashboard for Redis queues: it starts and
balances worker processes from code config, records throughput/runtime,
shows recent/failed jobs, tags, and lets you retry.

**Why.** Queue problems are silent: users don't get e-mails, orders stay
"pending". Horizon makes the queue visible and its worker setup reproducible.

**Installation.** `composer require laravel/horizon && php artisan horizon:install`
(was already present); Redis via the `redis` container and the `phpredis`
extension (in `docker/php/Dockerfile`).

**Configuration (this project, phase 3).**
- `config/horizon.php` — two supervisors = two isolated worker pools
  (**bulkhead pattern**):
  - `supervisor-1`: connection `redis`, queues `['notifications','default']`
    (priority order), `timeout` 60, `maxTime` 3600 (recycle workers hourly),
    `maxJobs` 1000; production `maxProcesses` 10, local 2.
  - `lab` (local only): connection `redis-long`, queue `lab`, 1–2 workers,
    `timeout` 300, `memory` 192, `nice` 5. A flooded lab queue cannot starve
    real work.
- `config/queue.php`: `redis` (`retry_after` 90) and `redis-long`
  (`retry_after` 330). **Rule: job/worker `timeout` < `retry_after`**, or a
  still-running job is handed to a second worker and runs twice.
- `waits`: `LongWaitDetected` after 60 s (`redis:notifications`) / 300 s (`redis-long:lab`).
- `horizon` container: `stop_signal: SIGTERM`, `stop_grace_period: 70s`
  (lets running jobs finish), healthcheck `horizon:status`.
- Scheduler: `horizon:snapshot` every 5 minutes (feeds the metrics graphs),
  `queue:prune-failed --hours=168` daily.
- Gate `viewHorizon`: admin allow-list (Horizon's own local rule still applies).
- Bug fixed in phase 3: `SendOrderNotificationJob` declared
  `public string $queue`, which conflicts with the `Queueable` trait's
  untyped `$queue` → fatal error at class load; now `$this->onQueue(...)`.

**How to test.** `make lab m=POST s=jobs/backlog` → Horizon Dashboard: lab
queue jobs/wait climb, ~150 s to drain; `make lab m=POST s="jobs/failing?mode=permanent"`
→ Failed Jobs.

**Use cases.** E-mails, notifications, webhooks, exports, image processing;
priority queues; autoscaling workers on load.

**Production issues.**
- Deploy without `php artisan horizon:terminate` → workers keep running *old
  code* forever (they are long-lived processes).
- Memory leaks in long-running workers → `memory`/`maxJobs`/`maxTime` limits.
- `timeout` ≥ `retry_after` → duplicate execution.
- Non-idempotent jobs + retries → double charges. Make jobs idempotent
  (unique keys, `ShouldBeUnique`, checking state before acting).
- Redis `maxmemory` with an eviction policy → **jobs silently deleted**. Queue
  Redis must use `noeviction` (and ideally be separate from cache Redis).
- Failed jobs nobody looks at: alert on them (we do: `JobsFailing`).

**Interview Q&A.**
- *Q: `tries`, `backoff`, `timeout`, `retry_after` — explain.* — `tries`:
  max attempts. `backoff`: delay between attempts (array = per attempt).
  `timeout`: worker kills a job running longer. `retry_after`: when the queue
  considers a reserved job lost and releases it; must be > timeout.
- *Q: Why separate supervisors/queues?* — Isolation (bulkhead), priorities,
  different timeouts/memory per workload, independent scaling.
- *Q: How do you retry safely?* — Fix the cause first, make the job
  idempotent, then `queue:retry <id>` or Horizon's Retry; the failed payload
  is replayed.
- *Q: Horizon vs `queue:work` under Supervisor?* — Horizon is config-in-code
  for workers, auto-balancing, metrics and a UI; Supervisor+`queue:work` is the
  generic option (needed for non-Redis drivers like SQS/RabbitMQ).

---

### 4.5 Sentry

**What.** Error tracking and performance monitoring SaaS (also self-hostable).
Groups exceptions into *issues* with stack trace, request, user, breadcrumbs
(recent SQL/log/cache/queue events), release and environment; Performance
shows sampled traces with spans.

**Why.** You learn about bugs before users report them, with enough context
to fix them; alerts on *new* or *regressed* issues, not on every occurrence.

**Installation (phase 4).**
```bash
composer require sentry/sentry-laravel
php artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"
```
`bootstrap/app.php`:
```php
->withExceptions(function (Exceptions $exceptions) {
    \Sentry\Laravel\Integration::handles($exceptions);
})
```

**Configuration (this project).**
- `.env.docker`: `SENTRY_LARAVEL_DSN=` (empty = SDK is a no-op),
  `SENTRY_ENVIRONMENT=local`, `SENTRY_TRACES_SAMPLE_RATE=1.0` (lab only;
  production 0.05–0.2), `SENTRY_SEND_DEFAULT_PII=false`.
- `config/sentry.php`: SQL bindings off in breadcrumbs and spans (they can
  contain personal data).
- `phpunit.xml` forces an empty DSN: tests never send events.
- What reaches Sentry = what Laravel **reports**. Not reported by design:
  validation errors, 404s, auth failures, and `ShouldntReport` exceptions
  (`DeliveryException` business 409s). Test: `ErrorReportingTest`.

**How to test.**
1. Create a project on sentry.io (platform Laravel), put the DSN in `.env.docker`.
2. `docker compose up -d app horizon scheduler pulse` (re-reads env), then
   `docker exec laravel_app php artisan sentry:test`.
3. `make lab s=exception`, `make lab s="exception?type=query"`,
   `make lab m=POST s="jobs/failing?mode=permanent"` → Issues;
   `make lab s=slow` → Performance (a 3 s transaction).

**Use cases.** Release health ("did 2.4.0 introduce new errors?"), alerting
the owning team, seeing which users hit an error, linking a spike to a deploy.

**Production issues.**
- **Noise**: reporting expected business errors → thousands of events, quota
  burnt, real bugs drowned. Use `ShouldntReport`, `$dontReport`,
  `ignore_exceptions`, or `before_send`.
- **Quota/cost** spikes when a loop throws per item: set rate limits/spike
  protection in Sentry, sample `traces_sample_rate`.
- **PII leaks**: request bodies, SQL bindings, user e-mails. Keep
  `send_default_pii=false`, scrub fields.
- No `release`/`environment` set → can't tell which deploy broke things. Set
  `SENTRY_RELEASE` to the git SHA during deploy.
- DSN treated as a secret: it is only an ingestion key (can send, not read),
  but leaking it lets anyone spam your quota.

**Interview Q&A.**
- *Q: Why not alert on every exception?* — Alert fatigue. Group into issues;
  alert on new issue, regression, or frequency threshold.
- *Q: What is `traces_sample_rate`?* — The fraction of transactions traced.
  1.0 records everything (lab); in production 0.05–0.2 keeps cost bounded
  while still statistically representative. Errors are captured regardless.
- *Q: Sentry vs logs?* — Logs are a stream; Sentry deduplicates, groups,
  adds context, tracks first/last seen, assigns owners and alerts.

---

### 4.6 Prometheus

**What.** A time-series database + scraper + rule engine. It **pulls**
`/metrics` endpoints in a plain-text format every `scrape_interval`, stores
samples locally, evaluates recording and alert rules, and is queried with
**PromQL**.

**Why.** The de-facto standard for infrastructure and application metrics:
cheap, reliable, alertable, huge ecosystem of exporters.

**Installation (phase 5).** Docker image `prom/prometheus:v2.54.1` in the
`monitoring` profile; PHP client `promphp/prometheus_client_php`.

**Configuration (this project).**

*Laravel side*
- PHP-FPM has no shared memory between requests, so counters live in **Redis
  DB 3** (`config/metrics.php`, `App\Support\Metrics\Metrics`). If Redis is
  down, recording is skipped (one warning), the app keeps working.
- `App\Http\Middleware\RecordHttpMetrics` (global): per request, labelled by
  **route template** (not URL) to keep cardinality low; ignores
  `/metrics`, `/up`, horizon/telescope/pulse/livewire polling.
- Queue events in `ObservabilityServiceProvider`: `JobProcessing` →
  start time; `JobProcessed`/`JobFailed` → counter + duration.
- Gauges measured **at scrape time** (never stale): queue size per state
  (Redis LIST `queues:x` = pending, ZSETs `:delayed`, `:reserved`), failed_jobs rows.
- `routes/metrics.php` + `EnsureInternalMetricsRequest` (see access rules).

| Metric | Type | Labels |
|---|---|---|
| `laravel_http_requests_total` | counter | method, route, status |
| `laravel_http_request_duration_seconds` | histogram | method, route |
| `laravel_http_request_peak_memory_bytes` | histogram | route |
| `laravel_queue_jobs_total` | counter | connection, queue, job, status |
| `laravel_queue_job_duration_seconds` | histogram | queue, job |
| `laravel_queue_size` | gauge | connection, queue, state |
| `laravel_queue_failed_jobs` | gauge | – |

*Prometheus side* (`docker/prometheus/`)
- `prometheus.yml`: jobs `laravel` (nginx:8080), `php-fpm`, `redis`,
  `postgres`, `cadvisor`, `docker-containers`, `prometheus`. Retention 7 d / 1 GB.
- Exporters: `redis_exporter`, `postgres-exporter`, `php-fpm_exporter`
  (reads `pm.status_path=/fpm-status` from `docker/php/fpm-status.conf`), cAdvisor.
- `recording.yml`: `container:cpu_usage_cores:rate1m`,
  `container:memory_working_set_bytes` (join cAdvisor ids with names — see §6).
- `alerts.yml`: `LaravelDown`, `HighErrorRate` (>5 % 5xx for 2 m),
  `SlowRequestsP95` (>1 s for 5 m), `QueueBacklog` (>100 pending for 5 m),
  `JobsFailing`, `PhpFpmSaturated`, `ContainerHighMemory`.

**PromQL cheat sheet**
```promql
sum(rate(laravel_http_requests_total[5m]))                                   # requests/s
sum(rate(laravel_http_requests_total{status=~"5.."}[5m]))
  / sum(rate(laravel_http_requests_total[5m]))                               # error ratio
histogram_quantile(0.95, sum by (le, route) (
  rate(laravel_http_request_duration_seconds_bucket[5m])))                   # p95 per route
laravel_queue_size{state="pending"}                                          # backlog
increase(laravel_queue_jobs_total{status="failed"}[1h])                      # failures last hour
topk(5, container:memory_working_set_bytes)                                  # biggest containers
```
- `rate()` per-second average over the window, handles counter resets.
  `increase()` = rate × window. Never `rate()` a gauge.
- Histograms: percentiles are computed *at query time* from buckets, so they
  can be aggregated across instances (summaries cannot). Bucket boundaries
  should surround your SLO (we have 0.25/0.5/1 s).

**How to test.** http://127.0.0.1:9090/targets (all `up`); Graph tab with
the queries above; `/alerts` after `make lab s=exception` a few times
(`HighErrorRate` → pending → firing).

**Use cases.** SLO dashboards, capacity planning (CPU trend), alerting on
symptoms (error rate, latency) rather than causes.

**Production issues.**
- **Cardinality explosion** (user IDs/URLs in labels) → Prometheus OOM.
- Local storage is single-node and short-term: long-term/HA via Thanos,
  Cortex/Mimir, VictoriaMetrics, or a managed remote-write backend.
- Short-lived processes (cron, CLI) die before being scraped → Pushgateway or
  write to a shared store (as we do with Redis).
- Alert rules without `for:` → flapping pages. Alerts need Alertmanager
  (grouping, silencing, routing to Slack/PagerDuty) — not installed here.
- `/metrics` exposed publicly → leaks routes, traffic, error rates.

**Interview Q&A.**
- *Q: Pull vs push — why does Prometheus pull?* — The server controls the
  rate, a failed scrape is itself a signal (`up == 0`), targets stay simple;
  service discovery finds them. Push is used for short-lived jobs (Pushgateway).
- *Q: How do you get metrics out of PHP-FPM?* — Share state outside the
  process: Redis (our choice), APCu (per-server), or the StatsD/OTel route.
- *Q: Counter vs gauge vs histogram vs summary?* — Counter only goes up
  (requests); gauge goes up/down (queue size); histogram = bucketed counts,
  aggregatable percentiles; summary = client-computed quantiles, not aggregatable.
- *Q: Why `route` and not `path` as a label?* — Bounded cardinality.

---

### 4.7 Grafana

**What.** Visualization and alerting UI over many data sources (Prometheus,
Loki, Tempo, Postgres, Elasticsearch, cloud providers).

**Why.** One screen correlating app, queue, database and container metrics.

**Installation.** `grafana/grafana:11.2.0`, `127.0.0.1:3001`, persistent volume `grafana_data`.

**Configuration (as code).**
- `docker/grafana/provisioning/datasources/prometheus.yml` — the Prometheus
  data source (uid `prometheus`, `timeInterval: 15s` so `$__rate_interval` is right).
- `docker/grafana/provisioning/dashboards/dashboards.yml` + 
  `docker/grafana/dashboards/laravel-overview.json` — dashboard
  **Laravel – Overview** (18 panels in 4 rows): golden signals (req/s, 5xx ratio, p95,
  jobs waiting), requests by status, p50/p95/p99, slowest routes, 5xx by
  route; queue size, jobs/s by outcome, job duration p95; container CPU and
  memory, PHP-FPM workers, peak memory per request; Postgres connections and
  cache hit ratio, Redis memory/commands.
- Anonymous access and sign-up disabled; admin password from
  `GRAFANA_ADMIN_PASSWORD` (default `admin`, forced change).
- Workflow: edit in UI → *Export → JSON* → save over the file → commit.

**How to test.** Open the dashboard, run `make lab s="cpu?seconds=8"` and
watch *Container CPU* for `laravel_app` approach 1.0.

**Use cases.** On-call dashboards, release comparison, capacity reviews, TV
wall dashboards, business KPIs from SQL.

**Production issues.**
- Dashboards edited only in the UI and lost/drifted → provision from git.
- Default admin password / public Grafana → full data access; use SSO.
- Heavy queries over long ranges → recording rules.
- Too many dashboards nobody owns; start from RED/USE.

**Interview Q&A.**
- *Q: Grafana alerting vs Prometheus alerting?* — Prometheus rules +
  Alertmanager live next to the data and are code-reviewed; Grafana alerting
  can alert on any data source (Loki, SQL) with a UI. Pick one per signal to
  avoid duplicate pages.
- *Q: What's on your first dashboard for a new service?* — RED per endpoint,
  saturation of the worker pool, queue depth/age, dependency health (DB, cache).

---

### 4.8 New Relic (documented, not installed)

**What.** Commercial all-in-one observability platform: APM (auto-instrumented
traces of PHP transactions, DB, external calls), infrastructure, logs,
browser/mobile, synthetics, alerts, dashboards (NRQL).

**How it works with Laravel.** A PHP extension (`newrelic.so`) plus a local
daemon (`newrelic-daemon`) that buffers and sends data. The extension hooks
PHP internals, so most instrumentation needs no code changes; it recognizes
Laravel and names transactions after routes.

**Installation (what you would do).** Install the agent package in the PHP
image (or the official installer), set `newrelic.license` and
`newrelic.appname` in `newrelic.ini`, run the daemon (sidecar container or
in the same image), restart FPM.

**Why not installed here.** Requires an account and a license key, sends data
to an external SaaS, and its value is mostly in production-scale data; the
free tier exists but this lab already covers the same concepts with
Sentry + Prometheus/Grafana.

**Production issues.** Cost grows with data ingest and users; agent overhead
(few %); transaction naming wrong without framework support; must exclude
PII; agent version vs PHP version compatibility on upgrades.

**Interview Q&A.**
- *Q: Agent-based APM vs Prometheus?* — The agent auto-instruments code paths
  (traces, slow SQL, external calls) with almost no code; Prometheus needs
  explicit metrics but is free, portable and you control cardinality/cost.

### 4.9 Datadog (documented, not installed)

**What.** Commercial SaaS: infrastructure monitoring (per-host Agent),
APM (`dd-trace-php` extension), log management, RUM, synthetics, security,
800+ integrations (Redis, Postgres, Nginx, PHP-FPM out of the box).

**How it works.** The **Datadog Agent** runs per host/node (a container in
Compose/Kubernetes); it collects host/container metrics, tails logs, receives
traces from the tracer and DogStatsD custom metrics, and forwards everything.
`dd-trace-php` auto-instruments Laravel; logs and traces are correlated via
injected trace IDs.

**Why not installed here.** Paid (14-day trial), needs an API key and ships
data off the laptop; the concepts (agent, integrations, APM, log/trace
correlation) are explained here and practiced with open tools.

**Production issues.** Bill shock from custom-metric cardinality and log
volume (use exclusion filters, sampling); agent resource usage; unified
service tagging (`env`, `service`, `version`) needed for correlation.

**Interview Q&A.**
- *Q: Datadog/New Relic or Prometheus/Grafana/Sentry?* — Vendor: fastest to
  value, one UI, correlation built in, but cost scales with data and lock-in.
  Open stack: cheaper at scale and portable, but you operate it (or pay
  Grafana Cloud). OpenTelemetry reduces lock-in either way.

### 4.10 OpenTelemetry (documented, not installed)

**What.** A CNCF standard (APIs, SDKs, the OTLP protocol and the
**Collector**) for producing traces, metrics and logs in a vendor-neutral way.

**Why.** Instrument once, send anywhere: the Collector can export to Jaeger,
Tempo, Prometheus, Datadog, New Relic, Sentry... Switching vendors becomes a
config change, not a code rewrite.

**For PHP/Laravel.** `open-telemetry/sdk` + the `opentelemetry` PHP extension
for auto-instrumentation (+ Laravel instrumentation package), exporting OTLP to
a Collector sidecar. Trace context (`traceparent` header) propagates across
services and can be added to log lines and job payloads.

**Interview Q&A.** *Q: What is the Collector for?* — A local pipeline:
receive OTLP, batch, sample (tail-based sampling keeps all slow/error traces),
scrub PII, and fan out to one or more backends; apps stay simple.

### 4.11 RabbitMQ (documented, not installed)

**What.** A message broker implementing AMQP: producers publish to
**exchanges** (direct, topic, fanout, headers), which route to **queues**
via bindings; consumers **ack** messages; unacked messages are redelivered;
**dead-letter exchanges** catch rejected/expired messages; publisher confirms,
quorum queues for durability.

**Role vs Redis queues (this project uses Redis).**

| | Redis + Horizon | RabbitMQ |
|---|---|---|
| Model | Simple job queue (list per queue) | Routing broker (exchanges → queues) |
| Fan-out / pub-sub to many services | manual | native (fanout/topic exchanges) |
| Delivery guarantees | at-least-once via `retry_after` | acks, confirms, DLX, quorum queues |
| Polyglot consumers (Go, Python, Java) | awkward (Laravel payload format) | standard |
| Laravel tooling | first-class (Horizon UI) | `vladimir-yuldashev/laravel-queue-rabbitmq`, no Horizon |
| Ops | already running for cache/session | another stateful cluster to run |

**Why not installed.** Task03 says to include it only if not configured; the
project's queues are Laravel-only jobs with no cross-service routing, where
Redis + Horizon is simpler and observable. Choose RabbitMQ (or Kafka for event
streams/replay) when several services consume the same events, or when you
need routing, strict acks and dead-lettering across languages.

**Monitoring it (if you add it).** Management plugin UI (:15672) and the
built-in Prometheus plugin (`rabbitmq_prometheus`, :15692): queue depth,
unacked messages, consumer count, publish/deliver rates, memory/disk alarms.

**Interview Q&A.** *Q: What's a dead-letter exchange?* — Where messages go
after rejection, TTL expiry or max-length overflow, so poison messages don't
loop forever and can be inspected/replayed — the broker-level equivalent of
Laravel's `failed_jobs`.

---

## 5. The 10 lab scenarios

All under `/api/v1/lab` (see `app/Http/Controllers/Api/V1/Lab/LabController.php`).
Each JSON response lists where to look (`observe`). Numbers below were
measured on this lab (100 k orders, 10 k couriers).

### 1. Slow API request (3–5 s) — `make lab s=slow`
- **Detects:** Pulse *Slow Requests*; Grafana *p95 latency by route*;
  alert `SlowRequestsP95`; Sentry Performance; Telescope duration column.
- **Inspect:** p95/p99 (not average), which route, since when (deploy?),
  whether all routes are slow (infrastructure) or one (code).
- **Senior investigation:** open a trace (Sentry) or Telescope entry: is the
  time in SQL, an HTTP call, or PHP itself? Correlate with CPU/FPM saturation.
- **Fix:** timeouts on outgoing calls, move slow work to a queue, cache,
  fix the query; for dependencies use circuit breakers.

### 2. N+1 queries — `make lab s=n-plus-one` vs `s="n-plus-one?fixed=1"`
- **Measured:** **101 queries vs 3**, same JSON.
- **Detects:** Telescope → request → *Queries* (50 × `select * from couriers
  where id = ?`, 50 × customers). Sentry's performance-issue detection can also flag
  it as an "N+1 Query" from the DB spans.
- **Investigate:** count queries per request; look for identical statements
  with different bindings in a loop.
- **Fix:** eager loading `with(['courier:id,name','customer:id,name'])`;
  `Model::preventLazyLoading(! app()->isProduction())` to catch it in dev/tests;
  assert query counts in tests.

### 3. Expensive SQL — `make lab s=expensive-sql` vs `s="expensive-sql?fixed=1"`
- **Measured:** **~1.2–1.5 s vs 5–120 ms**. Slow: `ST_Distance(a,b) < 1000`
  (a computed value: no index, every pending row measured 20 times). Fast:
  `ST_DWithin(a,b,1000)` uses the partial GiST index
  `delivery_orders_pending_pickup_gist` (Task02).
- **Detects:** Telescope (query marked slow, full SQL), Pulse *Slow Queries*,
  Grafana p95 of the route, Postgres panels.
- **Investigate:** `EXPLAIN (ANALYZE, BUFFERS)` the SQL from the response:
  Seq Scan vs Index Scan, rows removed by filter, loops. `pg_stat_statements`
  in production for top queries by total time.
- **Fix:** index-aware predicates (sargable), right index (GiST for geography,
  composite/partial/covering B-tree), avoid functions on indexed columns,
  `statement_timeout` as a safety net (the endpoint sets 15 s).

### 4. Exceptions — `make lab s=exception` (`?type=query`, `?type=division`)
- **Detects:** Sentry Issues (grouped, with stack trace and breadcrumbs),
  Telescope Exceptions, Pulse Exceptions card, `laravel.log`, Grafana 5xx
  panels, alert `HighErrorRate`.
- **Investigate:** first-seen time vs deploys, affected users/routes,
  breadcrumbs leading to the error, frequency trend.
- **Fix:** fix the bug, add a regression test, mark the Sentry issue resolved
  in the next release (it reopens on regression).

### 5. Failed queue jobs — `make lab m=POST s="jobs/failing?mode=permanent"`
- **Behaviour:** `tries=3`, `backoff=[5,15]`; gateway "down" → 3 failures
  (~20 s) → `failed_jobs` + `failed()` logs "gave up". `mode=flaky` recovers
  on attempt 3.
- **Detects:** Horizon *Failed Jobs* (exception, attempts, payload),
  Grafana *Jobs/s by outcome*, alert `JobsFailing`, Sentry, log.
- **Investigate:** transient (timeouts → retries with backoff are enough) or
  permanent (bug, bad data, provider down)? Same job failing for all payloads
  or one?
- **Fix → retry:** `make lab m=POST s=jobs/failing/fix` ("deploy the fix"),
  then Horizon → Retry or `php artisan queue:retry <uuid>` → processed.
  Make jobs idempotent before retrying anything that has side effects.

### 6. Queue backlog (1000 jobs) — `make lab m=POST s=jobs/backlog`
- **Measured:** 1000 × 300 ms on 2 lab workers ≈ 150 s; Prometheus showed
  `laravel_queue_size{queue="lab",state="pending"} 980` right after.
- **Detects:** Horizon dashboard (jobs, wait time; `LongWaitDetected` after
  300 s), Grafana *Queue size* / *Jobs waiting*, alert `QueueBacklog`, Pulse Queues.
- **Investigate:** arrival rate vs processing rate (jobs/s), are workers busy
  or idle (stuck?), is one slow job type dominating, is the DB/API behind the
  jobs the real bottleneck.
- **Fix:** scale workers (`maxProcesses`, autoscaling), separate queues by
  priority (notice: `notifications` kept flowing — bulkhead), batch work,
  rate-limit producers.

### 7. Long-running job — `make lab m=POST s="jobs/long-running?seconds=90"`
- **Behaviour:** logs progress every 10 s; `seconds > 300` exceeds
  `$timeout` → worker process killed, job failed with
  `TimeoutExceededException` (`failOnTimeout = true`), Horizon spawns a new worker.
- **Detects:** Horizon (runtime, reserved), Grafana *Job duration p95*,
  Pulse Slow Jobs, logs.
- **Fix:** chunk the work into smaller jobs/batches (`Bus::batch`), make it
  resumable, give it its own queue with a larger timeout (and matching
  `retry_after`).

### 8. Memory-intensive operation — `make lab s="memory?mb=128"` / `?mb=300`
- **Measured:** 128 MB → peak 150 MB; 300 MB → fatal
  `Allowed memory size of 268435456 bytes exhausted` (a 500 that `try/catch`
  cannot catch).
- **Detects:** Grafana *Peak PHP memory per request* and *Container memory*,
  Telescope (memory per request), Sentry/Telescope/log for the fatal error;
  in workers, Horizon restarts a worker over its `memory` limit.
- **Fix:** stream/chunk (`lazy()`, `cursor()`, `chunkById()`, streamed
  downloads), don't load whole tables/files; never "fix" by raising
  `memory_limit` blindly.

### 9. High CPU — `make lab s="cpu?seconds=5"`
- **Measured:** one core busy for 5 s (~3.4 M SHA-256 rounds).
- **Detects:** Grafana *Container CPU* (`laravel_app` → ~1.0 core), Pulse
  Servers; run several in parallel → *PHP-FPM workers* "waiting for a
  worker" (listen queue) and alert `PhpFpmSaturated`.
- **Investigate:** which route (CPU vs latency correlation), profiler
  (Blackfire, Xdebug profiler, Datadog/Sentry profiling) for the hot function.
- **Fix:** better algorithm, cache results, move to a queue, more FPM workers
  only if there are CPU cores to back them.

### 10. Logs at every severity — `make lab m=POST s=logs`
- **Detects:** `storage/logs/laravel.log` (`grep <lab_run_id>`), Telescope Logs.
- **Learn:** levels → routing (debug/info for diagnosis, warning for
  degradation, error for failures, critical+ for paging); one correlation id
  on every line; logs are **not** exceptions (Pulse Exceptions stays empty).

---

## 6. Real problems hit while building this lab

These were not planned — they are exactly the kind of issue monitoring work
surfaces in real teams.

| Problem | Symptom | Cause | Fix |
|---|---|---|---|
| Tests used the dev Redis | Test jobs appeared as failed jobs in the real Horizon | Container env (`QUEUE_CONNECTION=redis`, `CACHE_STORE`, `SESSION_DRIVER`) beats `phpunit.xml` `<env>` | `force="true"` + `<server>` entries (Laravel reads `$_SERVER` first) |
| Tests wiped the dev DB (Task02) | Seeded data gone after `php artisan test` | Same env precedence for `DB_DATABASE` | `laravel_testing` forced + guard in `DeliveryTestCase` |
| Business 409s would flood Sentry | Every "no courier" logged as error | Exceptions are reported by default | `ShouldntReport` on `DeliveryException` |
| Pulse returned 403 locally | Dashboard blocked | Custom `viewPulse` gate replaced the default local rule | Keep `environment('local')` in the gate |
| Horizon would not start | `lab.minProcesses must be greater than 0` | Disabling a supervisor with 0 processes is invalid | Define `lab` only in the `local` environment |
| Job class fatal error | Job could not be dispatched | `public string $queue` clashes with `Queueable::$queue` | `$this->onQueue()` in the constructor |
| cAdvisor had no container names | Only `id="/"` series | Docker Desktop's containerd image store (`overlayfs` driver) unsupported by cAdvisor's Docker handler | Raw-cgroup mode + Prometheus Docker SD via read-only socket proxy + recording-rule join |
| 502 after recreating `app` | All requests 502 until nginx reload | nginx resolves `app:9000` once at start; new container = new IP | `nginx -s reload`; permanent: `resolver 127.0.0.11 valid=10s;` + `set $upstream app:9000; fastcgi_pass $upstream;` |
| 1–4 s on every request, even `/up` | High baseline latency in all graphs | Windows bind mount: PHP stats thousands of files per request | Options: `opcache.validate_timestamps=0`/`revalidate_freq` (restart on change), `vendor/` in a named volume, or work inside WSL2 filesystem |
| 128 MB allocation hit 256 MB limit | Fatal error earlier than expected | Zend MM: a 1 MB string + header doesn't fit twice in a 2 MB chunk → 2 MB each | Allocate 256 KB strings (documented in code) |
| `'exception'` route action broke routing | `Invalid route action: [exception]` | `class_exists('exception')` is true (global `Exception`) → treated as invokable class | Rename method to `throwException` |
| Query log counted twice | 14 instead of 3 queries in tests | `enableQueryLog()` keeps earlier entries in the same app | `flushQueryLog()` first |

---

## 7. From lab to production

| Area | Lab | Production |
|---|---|---|
| Telescope | on (`local`) | not installed (`--no-dev`) |
| Pulse | DB ingest, no sampling | `PULSE_INGEST_DRIVER=redis` + `pulse:work`, sample rates, separate DB connection, one `pulse:check` per server |
| Horizon | 2 + 2 workers, lab pool | sized `maxProcesses`, autoscaling, `horizon:terminate` on every deploy, notifications for long waits |
| Sentry | traces 100 %, no release | traces 5–20 %, `SENTRY_RELEASE=<git sha>`, alert rules per team, spike protection |
| Metrics | local Prometheus, 7 d | HA Prometheus / managed (Grafana Cloud, AMP) with remote write, long-term storage |
| Alerts | visible in `/alerts` | Alertmanager (or Grafana alerting) → Slack/PagerDuty, runbook link per alert |
| Grafana | admin/admin, localhost | SSO, roles, folder permissions, dashboards from git |
| Logs | file in container | JSON to stdout → Loki/ELK/vendor, retention per level |
| Secrets | `.env.docker` in git (lab only) | secret manager / CI variables; never in git |
| Access | localhost binding | private network, VPN/SSO proxy, IP allow-lists |
| Lab endpoints | enabled with token | **disabled** (code also refuses in `production`) |
| Redis | one instance, no persistence tuning | queue Redis `noeviction` + persistence; cache Redis separate with `allkeys-lru` |

---

## 8. Cross-cutting interview questions

- **Q: Users say the site is slow but dashboards are green. What do you do?**
  Check what the dashboards measure: averages hide tails (look at p99),
  server-side latency misses network/frontend (RUM), maybe only one tenant or
  region is affected (slice by label), maybe requests queue *before* PHP
  (FPM listen queue, load balancer). Then get one concrete slow request and
  trace it.
- **Q: How do you design alerts that don't wake people up for nothing?**
  Alert on symptoms users feel (error rate, latency, SLO burn rate), use
  `for:` windows, route by severity, every alert has an owner and a runbook,
  delete alerts nobody acts on.
- **Q: What is an SLO burn-rate alert?** Alert when the error budget is being
  consumed N times faster than allowed (e.g. 14× over 1 h = page; 1× over 3 d =
  ticket); catches both fast outages and slow degradation.
- **Q: A queue is growing. Root cause checklist?** Producer spike? Workers
  running (Horizon status)? Workers busy or idle? Job duration up (dependency
  slow)? Failures + retries amplifying load? Redis memory/eviction?
- **Q: How much does observability cost and how do you control it?** Logs and
  traces dominate cost: sample traces, drop debug logs in production, cap
  metric cardinality, set retention per data type, prefer metrics for
  high-volume questions.
- **Q: What would you monitor first on a new Laravel service?** RED per
  route, 5xx with Sentry, queue depth/age + failed jobs, DB connections and
  slow queries, FPM saturation, host CPU/memory/disk, and an uptime check of
  `/up` from outside.
- **Q: Metrics, logs, traces — if you could only have one?** For detection,
  metrics (cheap, alertable). For a microservice system's debugging, traces.
  Realistically: metrics + errors (Sentry) first, then logs, then tracing.
