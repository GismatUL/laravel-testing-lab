<?php

namespace App\Http\Requests\Delivery;

use App\Support\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCourierLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function point(): GeoPoint
    {
        return new GeoPoint((float) $this->input('lat'), (float) $this->input('lng'));
    }
}
