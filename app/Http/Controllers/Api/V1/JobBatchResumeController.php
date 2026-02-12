<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Jobs\JobBatchResumeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class JobBatchResumeController extends Controller
{
    public function __invoke(string $id, JobBatchResumeService $service): JsonResponse
    {
        try {
            $result = $service->resume($id);
            return response()->json(['ok' => true, 'data' => $result]);
        } catch (HttpExceptionInterface $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }
}

