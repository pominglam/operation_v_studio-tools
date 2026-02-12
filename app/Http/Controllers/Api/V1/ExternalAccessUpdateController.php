<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExternalAccessUpdateRequest;
use App\Services\Maintenance\ExternalAccessService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ExternalAccessUpdateController extends Controller
{
    public function __invoke(ExternalAccessUpdateRequest $request, ExternalAccessService $service): JsonResponse
    {
        $enabled = (bool) $request->validated('enabled');

        try {
            return response()->json(['data' => $service->setEnabled($enabled)]);
        } catch (HttpExceptionInterface $e) {
            return response()->json([
                'data' => null,
                'error' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }
}

