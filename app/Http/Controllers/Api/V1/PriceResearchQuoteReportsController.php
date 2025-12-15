<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PriceResearchQuoteReportsIndexRequest;
use App\Http\Requests\Api\V1\PriceResearchQuoteReportStoreRequest;
use App\Http\Resources\Api\V1\PriceResearchQuoteReportResource;
use App\Services\PriceResearch\Exceptions\QuoteNotFoundException;
use App\Services\PriceResearch\PriceResearchQuoteReportQueryService;
use App\Services\PriceResearch\PriceResearchQuoteReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PriceResearchQuoteReportsController extends Controller
{
    public function __construct(
        private readonly PriceResearchQuoteReportService $creator,
        private readonly PriceResearchQuoteReportQueryService $query,
    ) {}

    public function index(PriceResearchQuoteReportsIndexRequest $request): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validated('per_page') ?? 50);
        $perPage = max(1, min($perPage, 200));

        return PriceResearchQuoteReportResource::collection($this->query->paginate($perPage));
    }

    public function store(PriceResearchQuoteReportStoreRequest $request): JsonResponse
    {
        /** @var string $productUuid */
        $productUuid = $request->validated('product_id');
        /** @var string $siteKey */
        $siteKey = $request->validated('site_key');
        /** @var string|null $note */
        $note = $request->validated('note');
        /** @var string|null $runUuid */
        $runUuid = $request->validated('run_id');

        try {
            $report = $this->creator->report($productUuid, $siteKey, $note, $runUuid);

            return PriceResearchQuoteReportResource::make($report)->response()->setStatusCode(201);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Product not found.'], 404);
        } catch (QuoteNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
