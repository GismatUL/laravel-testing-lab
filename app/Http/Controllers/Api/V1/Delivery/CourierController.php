<?php

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\NearestCourierRequest;
use App\Http\Requests\Delivery\UpdateCourierLocationRequest;
use App\Http\Resources\Delivery\CourierResource;
use App\Models\Delivery\Courier;
use App\Services\Delivery\CourierService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CourierController extends Controller
{
    public function __construct(
        private readonly CourierService $couriers,
    ) {}

    public function updateLocation(UpdateCourierLocationRequest $request, Courier $courier): CourierResource
    {
        return CourierResource::make($this->couriers->updateLocation($courier, $request->point()));
    }

    public function nearest(NearestCourierRequest $request): AnonymousResourceCollection
    {
        return CourierResource::collection($this->couriers->findNearestAvailable(
            $request->point(),
            (int) $request->input('radius', CourierService::DEFAULT_RADIUS_METERS),
            (int) $request->input('limit', 5),
        ));
    }
}
