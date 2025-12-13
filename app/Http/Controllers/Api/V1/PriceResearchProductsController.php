<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductPriceResearchResource;
use App\Services\PriceResearch\PriceResearchQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PriceResearchProductsController extends Controller
{
    public function __construct(
        private readonly PriceResearchQueryService $query,
    ) {
    }

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        return ProductPriceResearchResource::collection(
            $this->query->paginateProductsWithQuotes($perPage),
        );
    }
}


