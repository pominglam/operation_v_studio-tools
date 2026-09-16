<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductSavedViewWriteRequest;
use App\Http\Resources\Api\V1\ProductSavedViewResource;
use App\Services\Products\ProductSavedViewService;
use Illuminate\Http\JsonResponse;

final class ProductSavedViewStoreController extends Controller
{
    public function __construct(
        private readonly ProductSavedViewService $views,
    ) {}

    public function __invoke(ProductSavedViewWriteRequest $request): JsonResponse
    {
        $result = $this->views->upsert($request->toWriteData());

        return (new ProductSavedViewResource($result->view))
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
    }
}
