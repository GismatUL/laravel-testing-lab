<?php

namespace App\Exceptions\Delivery;

use App\Enums\Delivery\DeliveryOrderStatus;

class InvalidStatusTransitionException extends DeliveryException
{
    public static function between(int $orderId, DeliveryOrderStatus $from, DeliveryOrderStatus $to): self
    {
        return new self("Order #{$orderId} cannot move from '{$from->value}' to '{$to->value}'.");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'invalid_status_transition';
    }
}
