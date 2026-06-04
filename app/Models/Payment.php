<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_number',
        'provider',
        'status',
        'amount',
        'currency',
        'idempotency_key',
        'provider_response',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'provider'          => PaymentProvider::class,
            'status'            => PaymentStatus::class,
            'amount'            => 'decimal:2',
            'provider_response' => 'array',
            'paid_at'           => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
