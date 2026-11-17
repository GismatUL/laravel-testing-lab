<?php

namespace App\Http\Controllers\Api\V1\Lab;

use App\Exceptions\Lab\LabScenarioException;
use App\Http\Controllers\Controller;
use App\Jobs\Lab\LabFailingJob;
use App\Jobs\Lab\LabLongRunningJob;
use App\Jobs\Lab\LabSleepJob;
use App\Models\Delivery\DeliveryOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Monitoring lab (Task03 phase 6): ten deliberate production problems.
 *
 * Every response says what happened and where to look ("observe"), so the
 * endpoint doubles as documentation. The full walkthrough per scenario
 * (detect, investigate, fix) is in docs/observability/OBSERVABILITY_GUIDE.md.
 *
 * Guarded by EnsureLabEnabled + rate limits (routes/api.php). All numeric
 * inputs are clamped to config('lab.limits').
 */
class LabController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['scenarios' => [
            ['#' => 1,  'request' => 'GET  /api/v1/lab/slow?seconds=3'],
            ['#' => 2,  'request' => 'GET  /api/v1/lab/n-plus-one[?fixed=1]'],
            ['#' => 3,  'request' => 'GET  /api/v1/lab/expensive-sql[?fixed=1]'],
            ['#' => 4,  'request' => 'GET  /api/v1/lab/exception?type=runtime|query|division'],
            ['#' => 5,  'request' => 'POST /api/v1/lab/jobs/failing?mode=permanent|flaky  (then POST /api/v1/lab/jobs/failing/fix)'],
            ['#' => 6,  'request' => 'POST /api/v1/lab/jobs/backlog?count=1000&ms=300'],
            ['#' => 7,  'request' => 'POST /api/v1/lab/jobs/long-running?seconds=90'],
            ['#' => 8,  'request' => 'GET  /api/v1/lab/memory?mb=128'],
            ['#' => 9,  'request' => 'GET  /api/v1/lab/cpu?seconds=5'],
            ['#' => 10, 'request' => 'POST /api/v1/lab/logs'],
        ]]);
    }

    /** 1. Slow API request: 3–5 s of "waiting on a slow dependency". */
    public function slow(Request $request): JsonResponse
    {
        $seconds = $this->clamp($request, 'seconds', random_int(3, 5), 0, config('lab.limits.slow_seconds_max'));

        usleep((int) ($seconds * 1_000_000));

        return $this->result('slow-request', ['slept_seconds' => $seconds], [
            'Telescope > Requests: duration column',
            'Pulse > Slow Requests card (threshold 1000 ms)',
            'Grafana > "p95 latency by route": api/v1/lab/slow',
            'Sentry > Performance (when DSN is set)',
        ]);
    }

    /**
     * 2. N+1 queries: 1 query for 50 orders, then 2 more per order for the
     * lazy-loaded courier and customer = 101 queries. ?fixed=1 eager loads
     * them: 3 queries, same output.
     */
    public function nPlusOne(Request $request): JsonResponse
    {
        $fixed = $request->boolean('fixed');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $orders = DeliveryOrder::query()
            ->when($fixed, fn ($q) => $q->with(['courier:id,name', 'customer:id,name']))
            ->whereNotNull('courier_id')
            ->latest('id')
            ->limit(50)
            ->get();

        $rows = $orders->map(fn (DeliveryOrder $o) => [
            'order'    => $o->id,
            'courier'  => $o->courier?->name,   // lazy load = 1 query each (unless eager loaded)
            'customer' => $o->customer?->name,
        ]);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $this->result('n-plus-one', [
            'fixed'         => $fixed,
            'orders'        => $rows->count(),
            'queries_run'   => $queries,
            'sample'        => $rows->take(3),
        ], [
            'Telescope > Requests > this request > Queries tab (count + duplicates)',
            'Telescope > Queries: the same "select * from couriers where id = ?" 50 times',
            'Compare queries_run with and without ?fixed=1',
        ]);
    }

    /**
     * 3. Expensive SQL. "How many pending orders are within 1 km of each of
     * the 20 newest pending orders?" (a surge map).
     *   slow : ST_Distance(a, b) < 1000  -> a function result cannot use an
     *          index: every pending row is measured 20 times (~1.5 s).
     *   fixed: ST_DWithin(a, b, 1000)    -> uses the partial GiST index
     *          delivery_orders_pending_pickup_gist (~5 ms).
     */
    public function expensiveSql(Request $request): JsonResponse
    {
        $fixed = $request->boolean('fixed');
        $nearby = $fixed
            ? 'ST_DWithin(n.pickup_location, o.pickup_location, 1000)'
            : 'ST_Distance(n.pickup_location, o.pickup_location) < 1000';

        $sql = <<<SQL
            SELECT o.id,
                   (SELECT count(*) FROM delivery_orders n
                     WHERE n.status = 'pending' AND {$nearby}) AS pending_within_1km
              FROM delivery_orders o
             WHERE o.status = 'pending'
             ORDER BY o.created_at DESC
             LIMIT 20
            SQL;

        $started = microtime(true);
        $rows = DB::transaction(function () use ($sql) {
            // Safety net: the database itself aborts the query if it runs away.
            DB::statement('SET LOCAL statement_timeout = '.(int) config('lab.limits.sql_timeout_ms'));

            return DB::select($sql);
        });

        return $this->result('expensive-sql', [
            'fixed'       => $fixed,
            'duration_ms' => round((microtime(true) - $started) * 1000, 1),
            'rows'        => count($rows),
            'sql'         => preg_replace('/\s+/', ' ', trim($sql)),
        ], [
            'Telescope > Queries: marked "slow" (> 100 ms), shows the full SQL',
            'Pulse > Slow Queries card (threshold 1000 ms)',
            'Grafana > PostgreSQL panels; p95 latency of api/v1/lab/expensive-sql',
            'php artisan delivery:sql ... or EXPLAIN ANALYZE the "sql" above in psql',
        ]);
    }

    /** 4. Unhandled exceptions (real bugs): 500, logged and reported. */
    public function throwException(Request $request): never
    {
        match ($request->query('type', 'runtime')) {
            'query'    => DB::select('SELECT * FROM lab_table_that_does_not_exist'),
            'division' => intdiv(1, 0),
            default    => throw new LabScenarioException('Lab: simulated bug in order total calculation'),
        };

        throw new LabScenarioException('unreachable');
    }

    /** 5. Failed queue jobs (with retries and backoff). */
    public function failingJob(Request $request): JsonResponse
    {
        $mode = $request->query('mode') === 'flaky' ? 'flaky' : 'permanent';

        if ($mode === 'permanent') {
            Cache::put(LabFailingJob::GATEWAY_DOWN_KEY, true, now()->addHour());
        }

        LabFailingJob::dispatch($mode);

        return $this->result('failed-job', [
            'mode'    => $mode,
            'tries'   => 3,
            'backoff' => '5 s, then 15 s',
            'next'    => $mode === 'permanent'
                ? 'After ~20 s it is in Horizon > Failed Jobs. Fix with POST /api/v1/lab/jobs/failing/fix, then press Retry.'
                : 'Attempts 1 and 2 fail, attempt 3 succeeds: watch it in Horizon > Recent Jobs.',
        ], [
            'Horizon > Failed Jobs: exception, stack trace, attempts, Retry button',
            'Grafana > "Jobs / s by outcome" (failed), Prometheus alert JobsFailing',
            'Sentry > Issues (when DSN is set)',
            'storage/logs/laravel.log: "Lab payment job gave up"',
        ]);
    }

    /** 5b. "Deploy the fix": the gateway is back, retries will now succeed. */
    public function fixFailingJob(): JsonResponse
    {
        Cache::forget(LabFailingJob::GATEWAY_DOWN_KEY);

        return $this->result('failed-job-fixed', ['gateway' => 'up'], [
            'Horizon > Failed Jobs > Retry  (or: php artisan queue:retry all)',
        ]);
    }

    /** 6. Queue backlog: 1000 jobs pushed at once onto a 2-worker pool. */
    public function backlog(Request $request): JsonResponse
    {
        $count = (int) $this->clamp($request, 'count', 1000, 1, config('lab.limits.backlog_jobs_max'));
        $ms = (int) $this->clamp($request, 'ms', 300, 0, config('lab.limits.backlog_job_ms_max'));

        // One Redis round trip for the whole batch instead of $count pushes.
        Queue::connection(config('lab.queue_connection'))->bulk(
            array_fill(0, $count, new LabSleepJob($ms)),
            '',
            config('lab.queue'),
        );

        return $this->result('queue-backlog', [
            'jobs_pushed'          => $count,
            'ms_per_job'           => $ms,
            'estimated_drain_secs' => (int) ceil($count * $ms / 1000 / 2), // 2 lab workers
        ], [
            'Horizon > Dashboard: lab queue "Jobs" and "Wait" (LongWaitDetected after 300 s)',
            'Grafana > "Queue size" (redis-long lab pending), alert QueueBacklog (> 100 for 5 min)',
            'Note: the notifications/default queues keep working (separate pool)',
        ]);
    }

    /** 7. Long-running job. seconds > 300 demonstrates the timeout kill. */
    public function longRunningJob(Request $request): JsonResponse
    {
        $seconds = (int) $this->clamp($request, 'seconds', 90, 1, config('lab.limits.long_job_seconds_max'));

        LabLongRunningJob::dispatch($seconds);

        return $this->result('long-running-job', [
            'seconds'     => $seconds,
            'job_timeout' => 300,
            'expect'      => $seconds > 300 ? 'killed by timeout -> Failed Jobs' : 'completes',
        ], [
            'Horizon > Pending/Completed Jobs: runtime; Dashboard: max runtime per queue',
            'Grafana > "Job duration p95"',
            'storage/logs/laravel.log: progress every 10 s',
        ]);
    }

    /**
     * 8. Memory-intensive request: builds $mb MB in memory. Above the PHP
     * memory_limit (256M) PHP dies with a fatal "Allowed memory size
     * exhausted" error: an uncatchable 500.
     */
    public function memory(Request $request): JsonResponse
    {
        $mb = (int) $this->clamp($request, 'mb', 128, 1, config('lab.limits.memory_mb_max'));

        // 256 KB strings, 4 per MB. Not 1 MB strings: PHP's allocator works in
        // 2 MB chunks and a 1 MB string + header does not fit twice in one,
        // so 1 MB strings really cost 2 MB each (128 "MB" would hit 256M).
        $chunks = [];
        for ($i = 0; $i < $mb * 4; $i++) {
            $chunks[] = str_repeat(chr(65 + $i % 26), 256 * 1024 - 64);
        }
        $held = intdiv(count($chunks), 4);
        unset($chunks);

        return $this->result('memory', [
            'allocated_mb'  => $held,
            'peak_mb'       => round(memory_get_peak_usage(true) / 1048576, 1),
            'memory_limit'  => ini_get('memory_limit'),
        ], [
            'Telescope > Requests: memory column',
            'Grafana > "Peak PHP memory per request (p95)" and "Container memory" (laravel_app)',
            'Try ?mb=300 to exceed memory_limit: fatal error in log/Telescope/Sentry',
        ]);
    }

    /** 9. High CPU: hashes in a tight loop for N seconds (one core at 100%). */
    public function cpu(Request $request): JsonResponse
    {
        $seconds = $this->clamp($request, 'seconds', 5, 1, config('lab.limits.cpu_seconds_max'));
        $deadline = microtime(true) + $seconds;
        $hash = 'lab';
        $iterations = 0;

        while (microtime(true) < $deadline) {
            for ($i = 0; $i < 1000; $i++) {
                $hash = hash('sha256', $hash);
            }
            $iterations += 1000;
        }

        return $this->result('cpu', [
            'busy_seconds' => $seconds,
            'sha256_rounds' => $iterations,
        ], [
            'Grafana > "Container CPU": laravel_app close to 1.0 (one core)',
            'Pulse > Servers card: CPU %',
            'Grafana > "PHP-FPM workers": active goes up; run several in parallel to see "waiting for a worker"',
        ]);
    }

    /** 10. Logs at every PSR-3 severity, sharing one correlation id. */
    public function logs(): JsonResponse
    {
        $runId = (string) Str::uuid();
        // withContext: every following log line in this request carries run_id.
        Log::withContext(['lab_run_id' => $runId]);

        Log::debug('Lab: cache miss for courier list', ['key' => 'couriers:nearby:baku']);
        Log::info('Lab: order created', ['order_id' => 123, 'fee' => 4.50]);
        Log::notice('Lab: courier went offline during delivery', ['courier_id' => 7]);
        Log::warning('Lab: geocoder slow, using cached coordinates', ['latency_ms' => 2300]);
        Log::error('Lab: payment capture failed', ['order_id' => 123, 'gateway' => 'test']);
        Log::critical('Lab: no couriers available in the whole city', ['area' => 'Baku']);
        Log::alert('Lab: database replica lag above 60 s');
        Log::emergency('Lab: application cannot reach PostgreSQL');

        return $this->result('logs', [
            'lab_run_id' => $runId,
            'levels'     => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
        ], [
            "storage/logs/laravel.log: grep {$runId}",
            'Telescope > Logs: filter by level',
            'Pulse > Exceptions card does NOT show these: logs are not exceptions',
        ]);
    }

    private function clamp(Request $request, string $key, float $default, float $min, float $max): float
    {
        $value = $request->query($key);

        return is_numeric($value) ? max($min, min($max, (float) $value)) : $default;
    }

    private function result(string $scenario, array $data, array $observe): JsonResponse
    {
        return response()->json(['scenario' => $scenario, ...$data, 'observe' => $observe]);
    }
}
