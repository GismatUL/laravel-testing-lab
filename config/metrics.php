<?php

/*
|--------------------------------------------------------------------------
| Prometheus metrics (Task03 – phase 5)
|--------------------------------------------------------------------------
| PHP-FPM forgets everything after each request, so counters/histograms are
| accumulated in Redis and read back when Prometheus scrapes GET /metrics.
|
| /metrics is NOT public: it only answers requests that arrive on nginx's
| internal port 8080 (not published to the host, reachable only from the
| Docker network). See App\Http\Middleware\EnsureInternalMetricsRequest.
*/

return [

    'enabled' => (bool) env('METRICS_ENABLED', true),

    // "redis" (shared between all PHP processes) or "memory" (tests only:
    // values vanish at the end of the request).
    'storage' => env('METRICS_STORAGE', 'redis'),

    // Metric name prefix: laravel_http_requests_total, laravel_queue_size, ...
    'namespace' => 'laravel',

    'redis' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD') === 'null' ? null : env('REDIS_PASSWORD'),
        // Own database: never mixed with cache/queue keys, easy to wipe.
        'database' => (int) env('REDIS_METRICS_DB', 3),
        'timeout'  => 0.1,
        'read_timeout' => '1',
        'persistent_connections' => true,
        'prefix'   => 'laravel_metrics:',
    ],

    // Paths that are not "application traffic": dashboards polling themselves
    // every few seconds would dominate request-rate and latency graphs.
    'ignore_paths' => [
        'metrics', 'up', 'horizon*', 'telescope*', 'pulse*', 'livewire*', 'favicon.ico',
    ],

    // Queues whose backlog is exported as laravel_queue_size at scrape time.
    'queues' => [
        'redis'      => ['notifications', 'default'],
        'redis-long' => ['lab'],
    ],

    /*
     * Histogram buckets (upper bounds). Choose them around your SLO: with
     * a 300 ms target you need buckets near 0.25/0.5 to see the breach.
     */
    'buckets' => [
        'http_seconds' => [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
        'job_seconds'  => [0.05, 0.1, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300],
        'memory_bytes' => [4e6, 8e6, 16e6, 32e6, 64e6, 128e6, 256e6],
    ],

];
