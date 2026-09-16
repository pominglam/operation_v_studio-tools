<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductTaxonomyVerificationIndexRequest;
use App\Http\Resources\Api\V1\ProductTaxonomyVerificationResource;
use App\Models\Product;
use App\Services\Products\ModelKitSeriesResolutionService;
use App\Services\Products\ProductTaxonomyReviewQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductTaxonomyVerificationIndexController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyReviewQueryService $query,
        private readonly ModelKitSeriesResolutionService $seriesResolution,
    ) {}

    public function __invoke(
        ProductTaxonomyVerificationIndexRequest $request,
    ): AnonymousResourceCollection {
        $paginator = $this->query->paginate(
            (int) ($request->validated('per_page') ?? 50),
            $request->reviewFilters(),
        );

        /** @var list<Product> $products */
        $products = $paginator->getCollection()
            ->map(static fn ($verification) => $verification->product)
            ->filter(static fn ($product): bool => $product instanceof Product)
            ->values()
            ->all();

        $resolutionsBySku = $this->seriesResolution->resolveBatch($products);

        foreach ($paginator->getCollection() as $verification) {
            $product = $verification->product;
            if (! $product instanceof Product) {
                continue;
            }

            $verification->loadedSeriesResolution = $resolutionsBySku[(string) $product->sku] ?? null;
        }

        return ProductTaxonomyVerificationResource::collection($paginator);
    }
}
