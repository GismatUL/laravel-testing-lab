<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Task03: send every *reported* exception to Sentry (in addition to the
        // log). With an empty SENTRY_LARAVEL_DSN the SDK is a no-op.
        // Not reported (by design): validation errors, 404s, auth failures and
        // anything implementing ShouldntReport (e.g. App\Exceptions\Delivery\*).
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();
