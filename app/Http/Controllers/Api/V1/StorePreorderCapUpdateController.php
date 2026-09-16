<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderCapUpdateRequest;
use App\Http\Resources\Api\V1\StorePreorderResource;
use App\Services\StorePreorders\Exceptions\StorePreorderUpdateException;
use App\Services\StorePreorders\StorePreorderCapUpdateService;
use Illuminate\Http\JsonResponse;

final class StorePreorderCapUpdateController extends Controller
{
    public function __construct(
        private readonly StorePreorderCapUpdateService $update,
    ) {}

    public function __invoke(StorePreorderCapUpdateRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $capQty = array_key_exists('cap_qty', $validated) && $validated['cap_qty'] !== null
            ? (int) $validated['cap_qty']
            : null;

        try {
            $offer = $this->update->update($id, $capQty);
        } catch (StorePreorderUpdateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return StorePreorderResource::make($offer)->response();
    }
}
