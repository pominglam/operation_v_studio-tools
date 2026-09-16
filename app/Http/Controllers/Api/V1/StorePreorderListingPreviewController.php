<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderListingPreviewRequest;
use App\Http\Resources\Api\V1\StorePreorderListingPreviewResource;
use App\Services\StorePreorders\Exceptions\StorePreorderListingCrawlException;
use App\Services\StorePreorders\Listing\StorePreorderListingPreviewService;
use Illuminate\Http\JsonResponse;

final class StorePreorderListingPreviewController extends Controller
{
    public function __construct(
        private readonly StorePreorderListingPreviewService $preview,
    ) {}

    public function __invoke(StorePreorderListingPreviewRequest $request): JsonResponse
    {
        try {
            $result = $this->preview->preview((string) $request->validated('url'));
        } catch (StorePreorderListingCrawlException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey,
                'host' => $exception->host,
            ], 422);
        }

        return StorePreorderListingPreviewResource::make($result)->response();
    }
}
