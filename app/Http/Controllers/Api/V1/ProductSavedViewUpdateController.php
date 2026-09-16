<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductSavedViewWriteRequest;
use App\Http\Resources\Api\V1\ProductSavedViewResource;
use App\Services\Products\Exceptions\ProductSavedViewNameConflictException;
use App\Services\Products\Exceptions\ProductSavedViewNotFoundException;
use App\Services\Products\ProductSavedViewService;
use Illuminate\Http\JsonResponse;

final class ProductSavedViewUpdateController extends Controller
{
    public function __construct(
        private readonly ProductSavedViewService $views,
    ) {}

    public function __invoke(
        string $viewId,
        ProductSavedViewWriteRequest $request,
    ): JsonResponse|ProductSavedViewResource {
        try {
            return new ProductSavedViewResource(
                $this->views->update($viewId, $request->toWriteData()),
            );
        } catch (ProductSavedViewNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (ProductSavedViewNameConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
