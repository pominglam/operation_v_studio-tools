<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodPreordersInterestUpdateRequest;
use App\Services\Plamod\PlamodPreorderInterestService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersInterestUpdateController extends Controller
{
    public function __invoke(
        PlamodPreordersInterestUpdateRequest $request,
        PlamodPreorderInterestService $interest,
    ): JsonResponse {
        /** @var array<int, string> $skus */
        $skus = $request->validated('skus');
        $notInterested = (bool) $request->validated('not_interested');
        $result = $interest->setNotInterested(array_values($skus), $notInterested);

        return response()->json([
            'requested' => $result->requested,
            'updated' => $result->updated,
            'not_interested' => $result->notInterested,
        ]);
    }
}
