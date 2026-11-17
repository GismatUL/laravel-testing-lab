<?php

namespace App\Exceptions\Delivery;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base class for expected business-rule failures in the courier module.
 *
 * Laravel automatically calls render() on exceptions that define it, so
 * controllers never need try/catch for these: the service throws, the
 * framework turns it into a consistent JSON error.
 */
abstract class DeliveryException extends RuntimeException
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
