<?php

namespace App\Http\Requests\Delivery;

use App\Enums\Delivery\DeliveryOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDeliveryOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['sometimes', Rule::enum(DeliveryOrderStatus::class)],
            'customer_id' => ['sometimes', 'integer'],
            'courier_id'  => ['sometimes', 'integer'],
            'from'        => ['sometimes', 'date'],
            'to'          => ['sometimes', 'date', 'after:from'],
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor'      => ['sometimes', 'string'],
        ];
    }

    /** @return array{status?: string, customer_id?: int, courier_id?: int, from?: string, to?: string} */
    public function filters(): array
    {
        return $this->safe()->only(['status', 'customer_id', 'courier_id', 'from', 'to']);
    }
}
