<?php

namespace App\Http\Resources\Delivery;

use App\Models\Delivery\Courier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Courier */
class CourierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'status'               => $this->status->value,
            'current_location'     => $this->point('current_location')?->toArray(),
            'distance_m'           => $this->when(isset($this->distance_m), fn () => round((float) $this->distance_m, 1)),
            'last_location_update' => $this->last_location_update?->toIso8601String(),
        ];
    }
}
