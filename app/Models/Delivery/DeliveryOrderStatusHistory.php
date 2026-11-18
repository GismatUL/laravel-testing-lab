<?php

namespace App\Models\Delivery;

use App\Enums\Delivery\DeliveryOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'delivery_order_status_history';

    protected $fillable = ['order_id', 'old_status', 'new_status'];

    protected function casts(): array
    {
        return [
            'old_status' => DeliveryOrderStatus::class,
            'new_status' => DeliveryOrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'order_id');
    }
}
