<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodRestockDraftPurchaseOrderService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class PlamodRestockDraftPurchaseOrderCreateController extends Controller
{
    public function __invoke(PlamodRestockDraftPurchaseOrderService $drafts): JsonResponse
    {
        try {
            $result = $drafts->createDraft();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'data' => $result]);
    }
}
