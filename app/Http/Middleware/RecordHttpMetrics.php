<?php

namespace App\Http\Middleware;

use App\Support\Metrics\Metrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records one counter + two histogram observations per HTTP request.
 *
 * The `route` label is the route *template* (api/v1/delivery/orders/{order}),
 * never the real path (…/orders/123): one time series per order id would be
 * a "cardinality explosion" that eats Prometheus memory. Unmatched paths
 * (404s, scanners) are folded into a single "unmatched" series.
 *
 * Runs as global middleware, so it also sees 500 responses: Laravel turns
 * exceptions into responses inside the pipeline before they reach us.
 */
class RecordHttpMetrics
{
    public function __construct(private readonly Metrics $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        $response = $next($request);

        if (config('metrics.enabled') && ! $request->is(...config('metrics.ignore_paths', []))) {
            $this->metrics->recordHttpRequest(
                $request->method(),
                $request->route()?->uri() ?? 'unmatched',
                $response->getStatusCode(),
                microtime(true) - $start,
                memory_get_peak_usage(true),
            );
        }

        return $response;
    }
}
