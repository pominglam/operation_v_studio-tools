<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;

final class JobBatchCancelController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $batch = Bus::findBatch($id);
        if ($batch === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $batch->cancel();

        return response()->json([
            'ok' => true,
        ]);
    }
}


