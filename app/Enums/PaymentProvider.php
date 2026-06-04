<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case Razorpay = 'razorpay';
    case Manual = 'manual';

    public function label(): string
    {
        return match($this) {
            self::Stripe   => 'Stripe',
            self::PayPal   => 'PayPal',
            self::Razorpay => 'Razorpay',
            self::Manual   => 'Manual',
        };
    }
}