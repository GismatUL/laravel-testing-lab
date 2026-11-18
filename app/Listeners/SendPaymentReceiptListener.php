<?php

namespace App\Listeners;

use App\Events\PaymentProcessed;
use App\Jobs\SendOrderNotificationJob;

class SendPaymentReceiptListener
{
    public function handle(PaymentProcessed $event): void
    {
        $payment = $event->payment;
        $order   = $payment->order;

        SendOrderNotificationJob::dispatch(
            $order->id,
            "Payment of {$payment->amount} {$payment->currency} received for order {$order->order_number}.",
        );
    }
}
