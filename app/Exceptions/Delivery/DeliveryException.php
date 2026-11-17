<?php

namespace App\Exceptions\Delivery;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base class for expected business-rule failures in the courier module.
 *
 * Laravel automatically calls render() on exceptions that define it, so
 * controllers never need try/catch for these: the service throws, the
 * framework turns it into a consistent JSON error.
 *
 * ShouldntReport (Task03): "no courier within 5 km" is a normal 409 answer,
 * not a bug. Without this marker every such response would be written to the
 * error log and create a Sentry issue, drowning real errors in noise and
 * burning the error-tracking quota. Track business outcomes with metrics
 * (Prometheus counters) instead.
 */
abstract class DeliveryException extends RuntimeException implements ShouldntReport
{
    abstract public function status(): int;

    abstract public function errorCode(): string;

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error'   => $this->errorCode(),
        ], $this->status());
    }
}
