<?php

namespace App\Http\Requests\Delivery;

use App\Support\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'  => ['required', 'integer', 'exists:customers,id'],
            'pickup.lat'   => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng'   => ['required', 'numeric', 'between:-180,180'],
            'delivery.lat' => ['required', 'numeric', 'between:-90,90'],
            'delivery.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function pickup(): GeoPoint
    {
        return new GeoPoint((float) $this->input('pickup.lat'), (float) $this->input('pickup.lng'));
    }

    public function dropoff(): GeoPoint
    {
        return new GeoPoint((float) $this->input('delivery.lat'), (float) $this->input('delivery.lng'));
    }
}
