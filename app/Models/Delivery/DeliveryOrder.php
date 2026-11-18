<?php

namespace App\Models\Delivery;

use App\Enums\Delivery\DeliveryOrderStatus;
use App\Models\Delivery\Concerns\HasGeographyColumns;
use Database\Factories\Delivery\DeliveryOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property DeliveryOrderStatus $status
 */
class DeliveryOrder extends Model
{
    /** @use HasFactory<DeliveryOrderFactory> */
    use HasFactory;
    use HasGeographyColumns;

    protected $fillable = [
        'customer_id',
        'courier_id',
        'status',
        'pickup_location',
        'delivery_location',
        'delivery_fee',
    ];

    protected $hidden = ['pickup_location', 'delivery_location'];

    protected function casts(): array
    {
        return [
            'status'       => DeliveryOrderStatus::class,
            'delivery_fee' => 'decimal:2',
        ];
    }

    protected function geographyColumns(): array
    {
        return ['pickup_location', 'delivery_location'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(DeliveryOrderStatusHistory::class, 'order_id')->orderBy('id');
    }

    /**
     * Filters for GET /delivery/orders. Each filter is written so that it is
     * "sargable" (Search ARGument ABLE): the column stays bare on the left side
     * of the comparison so a B-tree index on it can be used.
     *
     *   GOOD: created_at >= '2026-01-01' AND created_at < '2026-02-01'
     *   BAD:  DATE(created_at) = '2026-01-01'   -- function on column => no index
     *
     * @param  array{status?: string, customer_id?: int, courier_id?: int, from?: string, to?: string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $v) => $q->where('customer_id', $v))
            ->when($filters['courier_id'] ?? null, fn (Builder $q, $v) => $q->where('courier_id', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '<', $v));
    }

    protected static function newFactory(): DeliveryOrderFactory
    {
        return DeliveryOrderFactory::new();
    }
}
