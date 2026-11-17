<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatekeeper for /api/v1/lab/* (see config/lab.php).
 *
 * Every refusal is a plain 404: a scanner cannot tell "lab disabled" from
 * "wrong token" from "no such URL", so the endpoints' existence leaks nothing.
 * hash_equals() compares in constant time (no timing side channel).
 */
class EnsureLabEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('lab.token');

        $allowed = config('lab.enabled')
            && ! app()->environment('production')
            && $token !== ''
            && hash_equals($token, (string) $request->header('X-Lab-Token'));

        abort_unless($allowed, 404);

        return $next($request);
    }
}
