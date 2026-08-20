<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\DTOs\PurchaseOrders\WaterDecalPromoteApplyRowDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderWaterDecalApplyRequest;
use App\Http\Requests\Api\V1\PurchaseOrderWaterDecalPreviewRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\PurchaseOrderWaterDecalPromoteService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderWaterDecalPromoteController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderWaterDecalPromoteService $promoter,
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {}

    public function preview(PurchaseOrderWaterDecalPreviewRequest $request, string $id): JsonResponse
    {
        /** @var array{item_ids: array<int, int>, proposed?: array<int, array{item_id: int, proposed_sku: string}>} $validated */
        $validated = $request->validated();

        try {
            $rows = $this->promoter->preview(
                $id,
                array_map('intval', $validated['item_ids']),
                $this->proposedSkuMap($validated['proposed'] ?? []),
            );

            return response()->json([
                'rows' => array_map(static fn ($row) => $row->toArray(), $rows),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function apply(PurchaseOrderWaterDecalApplyRequest $request, string $id): JsonResponse|PurchaseOrderResource
    {
        /** @var array{rows: array<int, array<string, mixed>>} $validated */
        $validated = $request->validated();

        $rows = array_map(
            static fn (array $row): WaterDecalPromoteApplyRowDTO => WaterDecalPromoteApplyRowDTO::fromValidated($row),
            $validated['rows'],
        );

        try {
            $summary = $this->promoter->apply($id, $rows);

            if ($summary['merged'] + $summary['promoted'] + $summary['skipped'] === 0 && $summary['errors'] !== []) {
                return response()->json([
                    'message' => 'No rows were updated.',
                    'water_decals' => $summary,
                ], 422);
            }

            return PurchaseOrderResource::make(
                $this->purchaseOrders->findByUuidOrFail($id),
            )->additional(['water_decals' => $summary]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @param  array<int, array{item_id: int, proposed_sku: string}>  $proposed
     * @return array<int, string>
     */
    private function proposedSkuMap(array $proposed): array
    {
        $map = [];
        foreach ($proposed as $row) {
            $map[(int) $row['item_id']] = (string) $row['proposed_sku'];
        }

        return $map;
    }
}
