<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StorePreorderResource;
use App\Services\StorePreorders\Exceptions\StorePreorderCloseException;
use App\Services\StorePreorders\StorePreorderCloseService;
use Illuminate\Http\JsonResponse;

final class StorePreorderCloseController extends Controller
{
    public function __construct(
        private readonly StorePreorderCloseService $close,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        try {
            $offer = $this->close->close($id);
        } catch (StorePreorderCloseException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return StorePreorderResource::make($offer)->response();
    }
}
