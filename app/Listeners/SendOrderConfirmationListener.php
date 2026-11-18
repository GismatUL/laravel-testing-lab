<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Jobs\SendOrderNotificationJob;

class SendOrderConfirmationListener
{
    public function handle(OrderCreated $event): void
    {
        SendOrderNotificationJob::dispatch(
            $event->order->id,
            "Your order {$event->order->order_number} has been confirmed.",
        );
    }
}
