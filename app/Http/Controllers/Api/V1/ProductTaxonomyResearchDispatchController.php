<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductTaxonomyResearchDispatchRequest;
use App\Http\Resources\Api\V1\ProductTaxonomyResearchRunResource;
use App\Services\Products\ProductTaxonomyResearchDispatchService;
use Illuminate\Http\JsonResponse;

final class ProductTaxonomyResearchDispatchController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyResearchDispatchService $dispatch,
    ) {}

    public function __invoke(ProductTaxonomyResearchDispatchRequest $request): JsonResponse
    {
        $run = $this->dispatch->dispatch((string) $request->validated('research_version'));

        return ProductTaxonomyResearchRunResource::make($run)
            ->response()
            ->setStatusCode(202);
    }
}
