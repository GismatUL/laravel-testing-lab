<?php

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\AssignDeliveryOrderRequest;
use App\Http\Requests\Delivery\ListDeliveryOrdersRequest;
use App\Http\Requests\Delivery\PendingInAreaRequest;
use App\Http\Requests\Delivery\StoreDeliveryOrderRequest;
use App\Http\Requests\Delivery\UpdateDeliveryOrderStatusRequest;
use App\Http\Resources\Delivery\DeliveryOrderResource;
use App\Services\Delivery\CourierAssignmentService;
use App\Services\Delivery\CourierService;
use App\Services\Delivery\DeliveryAreaService;
use App\Services\Delivery\DeliveryOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin controller: validate (FormRequest) -> delegate (Service) -> shape (Resource).
 * Business-rule failures are DeliveryException subclasses that render
 * themselves, so there is no try/catch here.
 */
class DeliveryOrderController extends Controller
{
    public function __construct(
        private readonly DeliveryOrderService $orders,
    ) {}

    public function index(ListDeliveryOrdersRequest $request): AnonymousResourceCollection
    {
        return DeliveryOrderResource::collection(
            $this->orders->list($request->filters(), (int) $request->input('per_page', 20))
        );
    }

    public function store(StoreDeliveryOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create(
            (int) $request->input('customer_id'),
            $request->pickup(),
            $request->dropoff(),
        );

        return DeliveryOrderResource::make($order)->response()->setStatusCode(201);
    }

    public function show(int $order): DeliveryOrderResource
    {
        return DeliveryOrderResource::make(
            $this->orders->find($order)->load(['statusHistory', 'courier' => fn ($q) => $q->withCoordinates()])
        );
    }

    public function assign(AssignDeliveryOrderRequest $request, int $order, CourierAssignmentService $assignment): DeliveryOrderResource
    {
        $assigned = $assignment->assignNearest(
            $order,
            (int) $request->input('radius', CourierService::DEFAULT_RADIUS_METERS),
        );

        return DeliveryOrderResource::make($assigned->load(['courier' => fn ($q) => $q->withCoordinates()]));
    }

    public function updateStatus(UpdateDeliveryOrderStatusRequest $request, int $order): DeliveryOrderResource
    {
        return DeliveryOrderResource::make($this->orders->transition($order, $request->status()));
    }

    public function pendingInArea(PendingInAreaRequest $request, DeliveryAreaService $area): AnonymousResourceCollection
    {
        $limit = (int) $request->input('limit', 500);

        $orders = $request->isCircle()
            ? $area->pendingWithinRadius($request->center(), (int) $request->input('radius', CourierService::DEFAULT_RADIUS_METERS), $limit)
            : $area->pendingInBox(
                (float) $request->input('min_lat'),
                (float) $request->input('min_lng'),
                (float) $request->input('max_lat'),
                (float) $request->input('max_lng'),
                $limit,
            );

        return DeliveryOrderResource::collection($orders);
    }
}
