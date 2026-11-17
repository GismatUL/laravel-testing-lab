<?php

namespace App\Exceptions\Delivery;

/**
 * Thrown when the database's partial unique index
 * (delivery_orders_one_active_order_per_courier) rejects an assignment.
 * Under normal operation SELECT ... FOR UPDATE SKIP LOCKED prevents this;
 * seeing it means the last line of defence did its job.
 */
class CourierAlreadyBusyException extends DeliveryException
{
    public static function forCourier(int $courierId): self
    {
        return new self("Courier #{$courierId} already has an active order.");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'courier_already_busy';
    }
}
