<?php

namespace App\Http\Requests\Delivery;

use App\Enums\Delivery\DeliveryOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeliveryOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // pending is the initial state; assigned is set only by /assign.
            'status' => [
                'required',
                Rule::enum(DeliveryOrderStatus::class)
                    ->except([DeliveryOrderStatus::Pending, DeliveryOrderStatus::Assigned]),
            ],
        ];
    }

    public function status(): DeliveryOrderStatus
    {
        return DeliveryOrderStatus::from($this->input('status'));
    }
}
