<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /metrics is answered only for requests that came through nginx's
 * internal listener (port 8080, not published to the host). That server
 * block sets the FastCGI parameter METRICS_INTERNAL=1.
 *
 * A client cannot forge it: HTTP headers reach PHP as HTTP_* variables
 * (a "Metrics-Internal" header becomes HTTP_METRICS_INTERNAL), never as a
 * bare METRICS_INTERNAL server variable.
 *
 * Everyone else gets a 404, so the endpoint's existence is not revealed.
 * Metrics leak route names, traffic volume and error rates: useful
 * reconnaissance for an attacker.
 */
class EnsureInternalMetricsRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->server('METRICS_INTERNAL') === '1', 404);

        return $next($request);
    }
}
