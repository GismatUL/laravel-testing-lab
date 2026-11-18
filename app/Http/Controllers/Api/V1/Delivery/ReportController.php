<?php

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\ReportRangeRequest;
use App\Services\Delivery\DeliveryReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly DeliveryReportService $reports,
    ) {}

    public function daily(ReportRangeRequest $request): JsonResponse
    {
        [$from, $to] = $request->range('30 days');

        return response()->json(['from' => $from, 'to' => $to, 'data' => $this->reports->daily($from, $to)]);
    }

    public function monthly(ReportRangeRequest $request): JsonResponse
    {
        [$from, $to] = $request->range('12 months');

        return response()->json(['from' => $from, 'to' => $to, 'data' => $this->reports->monthly($from, $to)]);
    }

    public function topCouriers(ReportRangeRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->topCouriers((int) $request->input('limit', 10))]);
    }
}
