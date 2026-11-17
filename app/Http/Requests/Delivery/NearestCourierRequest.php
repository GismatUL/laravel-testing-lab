<?php

namespace App\Http\Requests\Delivery;

use App\Support\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

class NearestCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lat'    => ['required', 'numeric', 'between:-90,90'],
            'lng'    => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['sometimes', 'integer', 'min:100', 'max:50000'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function point(): GeoPoint
    {
        return new GeoPoint((float) $this->input('lat'), (float) $this->input('lng'));
    }
}
