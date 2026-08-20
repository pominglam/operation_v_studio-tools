<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsIndexRequest;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\Exceptions\DuplicateSkuException;
use App\Services\Products\ProductCreateService;
use App\Services\Products\ProductsQueryService;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductsController extends Controller
{
    public function __construct(
        private readonly ProductsQueryService $products,
        private readonly ProductCreateService $creator,
        private readonly ProductUpdateService $updater,
    ) {}

    public function index(ProductsIndexRequest $request): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validated('per_page') ?? 25);
        $perPage = max(1, min($perPage, 1000));

        /** @var string|null $search */
        $search = $request->validated('search');

        /** @var array<int, string> $searchTerms */
        $searchTerms = $request->validated('search_terms') ?? [];

        /** @var string|null $purchaseOrderUuid */
        $purchaseOrderUuid = $request->validated('purchase_order_uuid');

        /** @var array<int, string> $purchaseOrderUuids */
        $purchaseOrderUuids = $request->validated('purchase_order_uuids') ?? [];
        $purchaseOrderUuids = array_values(array_unique(array_filter(array_map('trim', $purchaseOrderUuids), static fn (string $v): bool => $v !== '')));
        $single = is_string($purchaseOrderUuid) ? trim($purchaseOrderUuid) : '';
        if ($single !== '') {
            $purchaseOrderUuids[] = $single;
            $purchaseOrderUuids = array_values(array_unique($purchaseOrderUuids));
        }
        /** @var string|null $poProductNovelty */
        $poProductNovelty = $request->validated('po_product_novelty');

        /** @var array<int, string> $types */
        $types = $request->validated('types') ?? [];

        /** @var array<int, string> $mainTypes */
        $mainTypes = $request->validated('main_types') ?? [];

        /** @var array<int, string> $vendors */
        $vendors = $request->validated('vendors') ?? [];

        /** @var array<int, string> $missing */
        $missing = $request->validated('missing') ?? [];

        /** @var string|null $ready */
        $ready = $request->validated('ready');

        /** @var string|null $published */
        $published = $request->validated('published');

        $availableMin = $request->validated('available_min');
        $availableMin = is_numeric($availableMin) ? (int) $availableMin : null;
        $availableMax = $request->validated('available_max');
        $availableMax = is_numeric($availableMax) ? (int) $availableMax : null;
        $notArrivedFilter = $request->validated('not_arrived');
        $notArrivedFilter = is_numeric($notArrivedFilter) ? (int) $notArrivedFilter : null;
        $notArrivedMinFilter = $request->validated('not_arrived_min');
        $notArrivedMinFilter = is_numeric($notArrivedMinFilter) ? (int) $notArrivedMinFilter : null;
        $reorderFilter = $request->validated('reorder');
        $reorderFilter = is_numeric($reorderFilter) ? (int) $reorderFilter : null;
        $reorderGtOne = (bool) ($request->validated('reorder_gt_one') ?? false);

        /** @var array<int, string> $productFlags */
        $productFlags = $request->validated('product_flags') ?? [];

        /** @var array<int, string> $shipmentMethods */
        $shipmentMethods = $request->validated('shipment_methods') ?? [];

        /** @var string|null $sortBy */
        $sortBy = $request->validated('sort_by');

        /** @var string $sortDir */
        $sortDir = $request->validated('sort_dir') ?? 'asc';

        $archivedFilter = $request->archivedFilter();

        $notArrivedIncludeDraftOrders = $request->validated('not_arrived_include_draft_orders');
        $notArrivedIncludeDraftOrders = $notArrivedIncludeDraftOrders === null
            ? true
            : filter_var($notArrivedIncludeDraftOrders, FILTER_VALIDATE_BOOLEAN);

        $sellingPriceMin = $request->validated('selling_price_min');
        $sellingPriceMin = is_numeric($sellingPriceMin) ? (float) $sellingPriceMin : null;
        $sellingPriceMax = $request->validated('selling_price_max');
        $sellingPriceMax = is_numeric($sellingPriceMax) ? (float) $sellingPriceMax : null;

        $missingLandedCost = (bool) ($request->validated('missing_landed_cost') ?? false);
        $hasLandedCost = (bool) ($request->validated('has_landed_cost') ?? false);

        return ProductResource::collection(
            $this->products->paginate(
                $perPage,
                $search,
                $mainTypes,
                $types,
                $vendors,
                $missing,
                $sortBy,
                $sortDir,
                $purchaseOrderUuids,
                $searchTerms,
                $archivedFilter,
                $poProductNovelty,
                $ready,
                $published,
                $availableMin,
                $availableMax,
                $notArrivedFilter,
                $notArrivedMinFilter,
                $reorderFilter,
                $reorderGtOne,
                $productFlags,
                $shipmentMethods,
                $notArrivedIncludeDraftOrders,
                $sellingPriceMin,
                $sellingPriceMax,
                $missingLandedCost,
                $hasLandedCost,
            ),
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        try {
            $product = $this->creator->create($request->validated());

            return ProductResource::make($product)->response()->setStatusCode(201);
        } catch (DuplicateSkuException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        try {
            $product = $this->updater->update($id, $request->validated());

            return ProductResource::make($product)->response();
        } catch (DuplicateSkuException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }
}
