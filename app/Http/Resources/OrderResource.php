<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'order_number' => $this->order_number,
            'status'       => $this->status,
            'subtotal'     => $this->subtotal,
            'total'        => $this->total,
            'paid_at'      => $this->paid_at,
            'items'        => OrderItemResource::collection($this->whenLoaded('items')),
            'payment'      => new PaymentResource($this->whenLoaded('payment')),
            'created_at'   => $this->created_at,
        ];
    }
}
