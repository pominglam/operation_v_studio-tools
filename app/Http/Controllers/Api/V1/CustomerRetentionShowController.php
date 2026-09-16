<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerRetentionPersonResource;
use App\Services\Customers\CustomerRetentionReportService;
use Illuminate\Http\JsonResponse;

final class CustomerRetentionShowController extends Controller
{
    public function __invoke(string $personId, CustomerRetentionReportService $service): JsonResponse
    {
        $detail = $service->detail($personId);
        if ($detail === null) {
            abort(404);
        }

        return (new CustomerRetentionPersonResource($detail['person']))
            ->additional(['orders' => $detail['orders']])
            ->response();
    }
}
