<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\Exceptions\ProductSavedViewNotFoundException;
use App\Services\Products\ProductSavedViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class ProductSavedViewDeleteController extends Controller
{
    public function __construct(
        private readonly ProductSavedViewService $views,
    ) {}

    public function __invoke(string $viewId): JsonResponse|Response
    {
        try {
            $this->views->delete($viewId);
        } catch (ProductSavedViewNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->noContent();
    }
}
