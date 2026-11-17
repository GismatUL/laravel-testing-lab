<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Task03: Prometheus scrape endpoint. Outside the `web` group on
        // purpose: no session/cookies/CSRF for a machine-to-machine request.
        then: function () {
            Route::middleware(\App\Http\Middleware\EnsureInternalMetricsRequest::class)
                ->group(base_path('routes/metrics.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Task03: request count / latency / memory for every HTTP request.
        $middleware->append(\App\Http\Middleware\RecordHttpMetrics::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Task03: send every *reported* exception to Sentry (in addition to the
        // log). With an empty SENTRY_LARAVEL_DSN the SDK is a no-op.
        // Not reported (by design): validation errors, 404s, auth failures and
        // anything implementing ShouldntReport (e.g. App\Exceptions\Delivery\*).
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();
