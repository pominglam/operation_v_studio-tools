<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductTaxonomyVerificationIndexRequest;
use App\Http\Resources\Api\V1\ProductTaxonomyVerificationResource;
use App\Services\Products\ProductTaxonomyReviewQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductTaxonomyVerificationIndexController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyReviewQueryService $query,
    ) {}

    public function __invoke(
        ProductTaxonomyVerificationIndexRequest $request,
    ): AnonymousResourceCollection {
        return ProductTaxonomyVerificationResource::collection(
            $this->query->paginate(
                (int) ($request->validated('per_page') ?? 50),
                $request->reviewFilters(),
            ),
        );
    }
}
