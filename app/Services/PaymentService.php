<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Events\PaymentProcessed;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentService
{
    public function process(Order $order, ?string $idempotencyKey = null): Payment
    {
        $isNewPayment = false;

        $payment = DB::transaction(function () use ($order, $idempotencyKey, &$isNewPayment) {
            if ($idempotencyKey) {
                $existingPayment = Payment::where('idempotency_key', $idempotencyKey)->first();

                if ($existingPayment) {
                    return $existingPayment;
                }
            }

            $order = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === OrderStatus::Paid) {
                throw new RuntimeException('Order is already paid.');
            }

            $payment = Payment::create([
                'order_id'          => $order->id,
                'payment_number'    => 'PAY-' . now()->format('YmdHis') . '-' . Str::random(6),
                'provider'          => PaymentProvider::Mock->value,
                'status'            => PaymentStatus::Paid->value,
                'amount'            => $order->total,
                'currency'          => 'AZN',
                'idempotency_key'   => $idempotencyKey,
                'provider_response' => ['message' => 'Mock payment successful'],
                'paid_at'           => now(),
            ]);

            $order->update([
                'status'  => OrderStatus::Paid->value,
                'paid_at' => now(),
            ]);

            $isNewPayment = true;

            return $payment->fresh('order');
        });

        if ($isNewPayment) {
            PaymentProcessed::dispatch($payment);
        }

        return $payment;
    }
}
