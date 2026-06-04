<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationLogFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'type',
        'channel',
        'recipient',
        'status',
        'message',
        'payload',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'type'      => NotificationType::class,
            'status'    => NotificationStatus::class,
            'payload'   => 'array',
            'sent_at'   => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
