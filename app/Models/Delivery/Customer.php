<?php

namespace App\Models\Delivery;

use Database\Factories\Delivery\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public const UPDATED_AT = null; // table has no updated_at

    protected $fillable = ['name', 'email'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
