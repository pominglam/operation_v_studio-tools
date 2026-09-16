<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomerRetentionIndexRequest;
use App\Http\Resources\Api\V1\CustomerRetentionPersonResource;
use App\Services\Customers\CustomerRetentionReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomerRetentionIndexController extends Controller
{
    public function __invoke(
        CustomerRetentionIndexRequest $request,
        CustomerRetentionReportService $service,
    ): AnonymousResourceCollection {
        $result = $service->paginate($request->filters());

        return CustomerRetentionPersonResource::collection($result['people'])->additional([
            'summary' => $result['summary'],
            'meta' => $result['meta'],
        ]);
    }
}
