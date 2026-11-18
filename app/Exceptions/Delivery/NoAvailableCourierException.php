<?php

namespace App\Exceptions\Delivery;

class NoAvailableCourierException extends DeliveryException
{
    public static function withinRadius(int $orderId, int $radiusMeters): self
    {
        return new self("No available courier within {$radiusMeters} m of order #{$orderId} pickup location.");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'no_available_courier';
    }
}
