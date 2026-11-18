<?php

namespace App\Http\Requests\Delivery;

use App\Support\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Either a bounding box (min_lat, min_lng, max_lat, max_lng)
 * or a circle (lat, lng, radius).
 */
class PendingInAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_lat' => ['required_without:lat', 'numeric', 'between:-90,90'],
            'min_lng' => ['required_without:lat', 'numeric', 'between:-180,180'],
            'max_lat' => ['required_without:lat', 'numeric', 'between:-90,90', 'gte:min_lat'],
            'max_lng' => ['required_without:lat', 'numeric', 'between:-180,180', 'gte:min_lng'],
            'lat'     => ['required_without:min_lat', 'numeric', 'between:-90,90'],
            'lng'     => ['required_with:lat', 'numeric', 'between:-180,180'],
            'radius'  => ['sometimes', 'integer', 'min:100', 'max:50000'],
            'limit'   => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function isCircle(): bool
    {
        return $this->filled('lat');
    }

    public function center(): GeoPoint
    {
        return new GeoPoint((float) $this->input('lat'), (float) $this->input('lng'));
    }
}
