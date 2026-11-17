<?php

/*
|--------------------------------------------------------------------------
| Monitoring lab (Task03 – phase 6)
|--------------------------------------------------------------------------
| Endpoints under /api/v1/lab/* that deliberately cause production-style
| problems (slow requests, N+1, failing jobs, memory/CPU spikes, ...) so
| they can be observed in Telescope, Pulse, Horizon, Sentry and Grafana.
|
| Safety rails (all enforced by App\Http\Middleware\EnsureLabEnabled):
|   - off unless LAB_ENABLED=true
|   - always off when APP_ENV=production, whatever LAB_ENABLED says
|   - every request needs the header  X-Lab-Token: <LAB_TOKEN>
|     (no token configured = lab off)
|   - rate limited; heavy scenarios have a much lower limit
|   - every input is clamped to the limits below
|   - jobs run on their own worker pool (redis-long:lab), see horizon.php
*/

return [

    'enabled' => (bool) env('LAB_ENABLED', false),

    'token' => env('LAB_TOKEN'),

    'rate_limits' => [
        'per_minute'       => 60, // light scenarios
        'heavy_per_minute' => 5,  // backlog, memory, cpu, expensive-sql
    ],

    'limits' => [
        'slow_seconds_max'     => 5,
        'backlog_jobs_max'     => 1000,
        'backlog_job_ms_max'   => 1000,
        // job timeout is 300 s; up to 360 lets you watch a timeout kill.
        'long_job_seconds_max' => 360,
        // php memory_limit is 256M; values above it demonstrate the fatal error.
        'memory_mb_max'        => 512,
        'cpu_seconds_max'      => 10,
        'sql_timeout_ms'       => 15000,
    ],

    // Lab jobs never share workers with real work (bulkhead pattern).
    'queue_connection' => 'redis-long',
    'queue'            => 'lab',

];
