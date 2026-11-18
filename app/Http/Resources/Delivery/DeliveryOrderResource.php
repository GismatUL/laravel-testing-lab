<?php

namespace App\Http\Resources\Delivery;

use App\Models\Delivery\DeliveryOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeliveryOrder */
class DeliveryOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'customer_id'       => $this->customer_id,
            'courier_id'        => $this->courier_id,
            'status'            => $this->status->value,
            'pickup_location'   => $this->point('pickup_location')?->toArray(),
            'delivery_location' => $this->point('delivery_location')?->toArray(),
            'delivery_fee'      => $this->delivery_fee,
            'distance_m'        => $this->when(isset($this->distance_m), fn () => round((float) $this->distance_m, 1)),
            'courier'           => CourierResource::make($this->whenLoaded('courier')),
            'status_history'    => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'old_status' => $h->old_status?->value,
                'new_status' => $h->new_status->value,
                'created_at' => $h->created_at?->toIso8601String(),
            ])),
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
