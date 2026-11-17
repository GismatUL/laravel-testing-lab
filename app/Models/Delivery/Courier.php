<?php

namespace App\Models\Delivery;

use App\Enums\Delivery\CourierStatus;
use App\Models\Delivery\Concerns\HasGeographyColumns;
use Database\Factories\Delivery\CourierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CourierStatus $status
 */
class Courier extends Model
{
    /** @use HasFactory<CourierFactory> */
    use HasFactory;
    use HasGeographyColumns;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'status', 'current_location', 'last_location_update'];

    protected $hidden = ['current_location'];

    protected function casts(): array
    {
        return [
            'status'               => CourierStatus::class,
            'last_location_update' => 'datetime',
            'created_at'           => 'datetime',
        ];
    }

    protected function geographyColumns(): array
    {
        return ['current_location'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    protected static function newFactory(): CourierFactory
    {
        return CourierFactory::new();
    }
}
