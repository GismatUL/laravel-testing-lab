<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'payment_number' => $this->payment_number,
            'provider'       => $this->provider,
            'status'         => $this->status,
            'amount'         => $this->amount,
            'currency'       => $this->currency,
            'paid_at'        => $this->paid_at,
            'created_at'     => $this->created_at,
        ];
    }
}
