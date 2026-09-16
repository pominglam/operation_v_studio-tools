<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderOpenRequest;
use App\Http\Resources\Api\V1\StorePreorderResource;
use App\Services\StorePreorders\Exceptions\StorePreorderOpenException;
use App\Services\StorePreorders\StorePreorderOpenService;
use Illuminate\Http\JsonResponse;

final class StorePreorderStoreController extends Controller
{
    public function __construct(
        private readonly StorePreorderOpenService $open,
    ) {}

    public function __invoke(StorePreorderOpenRequest $request): JsonResponse
    {
        try {
            $result = $this->open->open($this->items($request->validated()));
        } catch (StorePreorderOpenException $exception) {
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
     * @return array<int, array{sku: string, deposit_percent: string, cap_qty: int|null, window_ends_on: string|null, selling_price: string|null}>
     */
    private function items(array $validated): array
    {
        $raw = $validated['items'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $items[] = [
                'sku' => $sku,
                'deposit_percent' => (string) ($row['deposit_percent'] ?? ''),
                'cap_qty' => isset($row['cap_qty']) ? (int) $row['cap_qty'] : null,
                'window_ends_on' => isset($row['window_ends_on']) && $row['window_ends_on'] !== ''
                    ? (string) $row['window_ends_on']
                    : null,
                'selling_price' => isset($row['selling_price']) && $row['selling_price'] !== ''
                    ? (string) $row['selling_price']
                    : null,
            ];
        }

        return $items;
    }
}
