<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderManualOpenRequest;
use App\Http\Resources\Api\V1\StorePreorderResource;
use App\Services\Products\Exceptions\DuplicateSkuException;
use App\Services\StorePreorders\Exceptions\StorePreorderOpenException;
use App\Services\StorePreorders\StorePreorderManualOpenService;
use Illuminate\Http\JsonResponse;

final class StorePreorderManualStoreController extends Controller
{
    public function __construct(
        private readonly StorePreorderManualOpenService $open,
    ) {}

    public function __invoke(StorePreorderManualOpenRequest $request): JsonResponse
    {
        try {
            $result = $this->open->open($this->payload($request->validated()));
        } catch (StorePreorderOpenException|DuplicateSkuException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return StorePreorderResource::collection($result->offers)->additional([
            'opened' => $result->opened,
            'reopened' => $result->reopened,
            'skipped_open' => $result->skippedOpen,
            'products_created' => $result->productsCreated,
            'shopify_queued' => $result->shopifyQueued,
            'photos_queued' => $result->photosQueued,
        ])->response()->setStatusCode(201);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{sku: string, product_name: string, description_html: string|null, selling_price: string, deposit_percent: string, cap_qty: int|null, window_ends_on: string|null, eta_date: string|null, photo_ids: list<string>}
     */
    private function payload(array $validated): array
    {
        $photoIds = [];
        foreach ($validated['photo_ids'] ?? [] as $id) {
            if (is_string($id) && $id !== '') {
                $photoIds[] = $id;
            }
        }

        return [
            'sku' => (string) $validated['sku'],
            'product_name' => (string) $validated['product_name'],
            'description_html' => isset($validated['description_html']) && $validated['description_html'] !== ''
                ? (string) $validated['description_html']
                : null,
            'selling_price' => (string) $validated['selling_price'],
            'deposit_percent' => (string) $validated['deposit_percent'],
            'cap_qty' => isset($validated['cap_qty']) ? (int) $validated['cap_qty'] : null,
            'window_ends_on' => isset($validated['window_ends_on']) && $validated['window_ends_on'] !== ''
                ? (string) $validated['window_ends_on']
                : null,
            'eta_date' => isset($validated['eta_date']) && $validated['eta_date'] !== ''
                ? (string) $validated['eta_date']
                : null,
            'photo_ids' => $photoIds,
        ];
    }
}
