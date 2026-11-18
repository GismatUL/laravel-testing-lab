<?php

namespace App\Providers;

use App\Events\OrderCreated;
use App\Events\PaymentProcessed;
use App\Listeners\SendOrderConfirmationListener;
use App\Listeners\SendPaymentReceiptListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(OrderCreated::class, SendOrderConfirmationListener::class);
        Event::listen(PaymentProcessed::class, SendPaymentReceiptListener::class);
    }
}
