<?php

namespace App\Enums\Delivery;

/**
 * Order lifecycle (a small state machine):
 *
 *   pending ──► assigned ──► picked_up ──► delivered
 *      │            │
 *      └────────────┴──────► cancelled
 */
enum DeliveryOrderStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending   => [self::Assigned, self::Cancelled],
            self::Assigned  => [self::PickedUp, self::Cancelled],
            self::PickedUp  => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Statuses in which the courier is occupied by this order. */
    public function isActive(): bool
    {
        return in_array($this, [self::Assigned, self::PickedUp], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }
}
