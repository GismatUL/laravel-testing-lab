<?php

namespace Tests\Feature\Observability;

use App\Exceptions\Delivery\NoAvailableCourierException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\Delivery\DeliveryTestCase;

/**
 * What reaches the error tracker (Sentry) and the error log is decided by
 * Laravel's exception *reporting*. Expected business outcomes must not be
 * reported, real bugs must be.
 */
class ErrorReportingTest extends DeliveryTestCase
{
    public function test_expected_business_errors_are_not_reported(): void
    {
        Exceptions::fake();
        $order = $this->pendingOrderAt(self::LAT, self::LNG); // no couriers exist

        $this->postJson(self::API."/orders/{$order->id}/assign")->assertStatus(409);

        Exceptions::assertNotReported(NoAvailableCourierException::class);
    }

    public function test_unexpected_errors_are_reported(): void
    {
        Exceptions::fake();
        Route::get('/__boom', fn () => throw new RuntimeException('boom'));

        $this->getJson('/__boom')->assertStatus(500);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
    }
}
