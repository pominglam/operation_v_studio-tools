<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductTaxonomyResearchRunResource;
use App\Services\Products\ProductTaxonomyReviewQueryService;

final class ProductTaxonomyResearchShowController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyReviewQueryService $query,
    ) {}

    public function __invoke(string $id): ProductTaxonomyResearchRunResource
    {
        return ProductTaxonomyResearchRunResource::make($this->query->researchRun($id));
    }
}
