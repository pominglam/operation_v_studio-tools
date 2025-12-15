<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PriceResearchRunLogsIndexRequest;
use App\Http\Resources\Api\V1\PriceResearchRunLogResource;
use App\Services\PriceResearch\PriceResearchRunLogQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PriceResearchRunLogsController extends Controller
{
    public function __construct(
        private readonly PriceResearchRunLogQueryService $logs,
    ) {}

    public function __invoke(PriceResearchRunLogsIndexRequest $request, string $id): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validated('per_page') ?? 50);
        $perPage = max(1, min($perPage, 200));

        return PriceResearchRunLogResource::collection(
            $this->logs->paginateForRunUuid($id, $perPage),
        );
    }
}
