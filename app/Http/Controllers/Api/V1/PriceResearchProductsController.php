<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PriceResearchProductsIndexRequest;
use App\Http\Resources\Api\V1\ProductPriceResearchResource;
use App\Services\PriceResearch\PriceResearchQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PriceResearchProductsController extends Controller
{
    public function __construct(
        private readonly PriceResearchQueryService $query,
    ) {}

    public function __invoke(PriceResearchProductsIndexRequest $request): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validated('per_page') ?? 25);
        $perPage = max(1, min($perPage, 500));

        /** @var string|null $search */
        $search = $request->validated('search');
        /** @var string|null $sortBy */
        $sortBy = $request->validated('sort_by');
        /** @var string $sortDir */
        $sortDir = $request->validated('sort_dir') ?? 'desc';

        /** @var string|null $sellingPrice */
        $sellingPrice = $request->validated('selling_price');

        /** @var string|null $barcode */
        $barcode = $request->validated('barcode');

        /** @var array<int, string> $vendors */
        $vendors = $request->validated('vendors') ?? [];

        /** @var array<int, string> $freshness */
        $freshness = $request->validated('freshness') ?? [];
        /** @var array<int, string> $types */
        $types = $request->validated('types') ?? [];
        /** @var array<int, string> $quoteSites */
        $quoteSites = $request->validated('quote_sites') ?? [];
        /** @var array<int, string> $quoteStatuses */
        $quoteStatuses = $request->validated('quote_statuses') ?? [];
        /** @var array<int, string> $quoteAvailabilities */
        $quoteAvailabilities = $request->validated('quote_availabilities') ?? [];

        return ProductPriceResearchResource::collection(
            $this->query->paginateProductsWithQuotes(
                perPage: $perPage,
                search: $search,
                sortBy: $sortBy,
                sortDir: $sortDir,
                sellingPrice: $sellingPrice,
                barcode: $barcode,
                vendors: $vendors,
                freshness: $freshness,
                types: $types,
                quoteSites: $quoteSites,
                quoteStatuses: $quoteStatuses,
                quoteAvailabilities: $quoteAvailabilities,
            ),
        );
    }
}
