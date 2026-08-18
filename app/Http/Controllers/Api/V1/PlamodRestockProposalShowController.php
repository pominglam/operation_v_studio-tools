<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodRestockProposalShowRequest;
use App\Services\Plamod\PlamodRestockProposalService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockProposalShowController extends Controller
{
    public function __invoke(
        PlamodRestockProposalShowRequest $request,
        PlamodRestockProposalService $proposal,
    ): JsonResponse {
        $hideDismissed = $request->boolean('hide_dismissed', true);
        $onlyIncludedNew = $request->boolean('only_included_new', false);

        return response()->json([
            'ok' => true,
            'data' => $proposal->build($hideDismissed, $onlyIncludedNew),
        ]);
    }
}
