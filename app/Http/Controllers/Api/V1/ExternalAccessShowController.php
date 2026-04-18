<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Maintenance\ExternalAccessService;
use Illuminate\Http\JsonResponse;

final class ExternalAccessShowController extends Controller
{
    public function __invoke(ExternalAccessService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->status(),
        ]);
    }
}
