<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\DTOs\PurchaseOrders\WaterDecalPromoteApplyRowDTO;
use App\DTOs\PurchaseOrders\WaterDecalPromotePreviewRowDTO;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Services\Products\ProductLatestCostCacheService;
use App\Services\Products\ProductSkuCascadeRenameService;
use App\Services\Products\ProductTypeDerivationService;
use App\Support\Products\WaterDecalSkuNormalizer;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderWaterDecalPromoteService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly WaterDecalSkuNormalizer $normalizer,
        private readonly ProductTypeDerivationService $types,
        private readonly ProductSkuCascadeRenameService $skuRename,
        private readonly ProductLatestCostCacheService $latestCosts,
    ) {}

    /**
     * @param  array<int, int>  $itemIds
     * @param  array<int, string>  $proposedSkuByItemId
     * @return array<int, WaterDecalPromotePreviewRowDTO>
     */
    public function preview(string $purchaseOrderUuid, array $itemIds, array $proposedSkuByItemId = []): array
    {
        $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        $items = $this->loadItemsForPo($po->id, $itemIds);

        return array_values(array_map(
            function (PurchaseOrderItem $item) use ($proposedSkuByItemId): WaterDecalPromotePreviewRowDTO {
                $override = $proposedSkuByItemId[(int) $item->id] ?? null;

                return $this->previewItem($item, is_string($override) ? $override : null);
            },
            $items,
        ));
    }

    /**
     * @param  array<int, WaterDecalPromoteApplyRowDTO>  $rows
     * @return array{merged: int, promoted: int, skipped: int, errors: array<int, string>}
     */
    public function apply(string $purchaseOrderUuid, array $rows): array
    {
        $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        $merged = 0;
        $promoted = 0;
        $skipped = 0;
        /** @var array<int, string> $errors */
        $errors = [];
        /** @var array<int, string> $skusToRefresh */
        $skusToRefresh = [];

        DB::transaction(function () use ($po, $rows, &$merged, &$promoted, &$skipped, &$errors, &$skusToRefresh): void {
            foreach ($rows as $index => $row) {
                try {
                    $result = $this->applyRow($po->id, $row);
                    $skusToRefresh[] = $result['sku'];
                    if ($result['action'] === 'merge') {
                        $merged++;
                    } elseif ($result['action'] === 'promote') {
                        $promoted++;
                    } else {
                        $skipped++;
                    }
                } catch (\InvalidArgumentException $e) {
                    $errors[$index] = $e->getMessage();
                }
            }
        });

        if ($skusToRefresh !== []) {
            $this->latestCosts->recomputeForSkus(array_values(array_unique($skusToRefresh)));
        }

        return [
            'merged' => $merged,
            'promoted' => $promoted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return array<int, PurchaseOrderItem>
     */
    private function loadItemsForPo(int $purchaseOrderId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        if ($ids === []) {
            throw new \InvalidArgumentException('Select at least one PO line.');
        }

        $items = PurchaseOrderItem::query()
            ->where('purchase_order_id', '=', $purchaseOrderId)
            ->whereIn('id', $ids)
            ->with('product')
            ->orderBy('id')
            ->get();

        if ($items->count() !== count($ids)) {
            throw new \InvalidArgumentException('One or more PO lines were not found on this purchase order.');
        }

        return $items->all();
    }

    private function previewItem(PurchaseOrderItem $item, ?string $proposedSkuOverride = null): WaterDecalPromotePreviewRowDTO
    {
        $product = $item->product;
        if ($product === null) {
            throw new \InvalidArgumentException("PO line {$item->id} has no linked product.");
        }

        $proposedSku = $this->normalizer->normalizeSku(
            $proposedSkuOverride ?? (string) $item->sku,
        );

        [$intention, $label, $warning, $mergeTarget] = $this->resolveIntention(
            $product,
            $proposedSku,
        );

        $description = (string) ($product->description ?? $item->sku ?? '');

        return new WaterDecalPromotePreviewRowDTO(
            itemId: (int) $item->id,
            intention: $intention,
            intentionLabel: $label,
            currentSku: (string) $item->sku,
            currentDescription: $description,
            currentMainType: $product->main_type,
            proposedSku: $proposedSku,
            proposedDescription: $this->normalizer->proposeDescription($description),
            proposedVendor: WaterDecalSkuNormalizer::DEFAULT_VENDOR,
            proposedType: $this->resolveType($description, $product->type),
            mergeTarget: $mergeTarget,
            warning: $warning,
        );
    }

    /**
     * @return array{action: string, sku: string}
     */
    private function applyRow(int $purchaseOrderId, WaterDecalPromoteApplyRowDTO $row): array
    {
        $item = PurchaseOrderItem::query()
            ->where('purchase_order_id', '=', $purchaseOrderId)
            ->where('id', '=', $row->itemId)
            ->with('product')
            ->first();

        if ($item === null) {
            throw new \InvalidArgumentException("PO line {$row->itemId} was not found.");
        }

        $product = $item->product;
        if ($product === null) {
            throw new \InvalidArgumentException("PO line {$row->itemId} has no linked product.");
        }

        $sku = $this->normalizer->normalizeSku($row->sku);
        if ($sku === '' || $sku === 'WD-') {
            throw new \InvalidArgumentException('Proposed SKU must not be empty.');
        }

        [$intention] = $this->resolveIntention($product, $sku);

        if ($intention === 'blocked') {
            throw new \InvalidArgumentException("SKU {$sku} is already used by another product.");
        }

        if ($intention === 'merge') {
            if (! $row->confirmMerge) {
                throw new \InvalidArgumentException("Confirm merge for PO line {$row->itemId}.");
            }

            return $this->applyMerge($item, $product, $sku, $row);
        }

        return $this->applyPromote($item, $product, $sku, $row);
    }

    /**
     * @return array{action: string, sku: string}
     */
    private function applyMerge(
        PurchaseOrderItem $item,
        Product $sourceProduct,
        string $sku,
        WaterDecalPromoteApplyRowDTO $row,
    ): array {
        $target = Product::query()->where('sku', '=', $sku)->first();
        if ($target === null || (int) $target->id === (int) $sourceProduct->id) {
            throw new \InvalidArgumentException("Merge target for SKU {$sku} is unavailable.");
        }

        $this->updateProductMetadata($target, $row, $sku);

        $item->product_id = (int) $target->id;
        $item->sku = $sku;
        $item->save();

        $this->deleteOrphanProductIfUnused($sourceProduct);

        return ['action' => 'merge', 'sku' => $sku];
    }

    /**
     * @return array{action: string, sku: string}
     */
    private function applyPromote(
        PurchaseOrderItem $item,
        Product $product,
        string $sku,
        WaterDecalPromoteApplyRowDTO $row,
    ): array {
        $currentSku = trim((string) $product->sku);
        if ($currentSku !== $sku && Product::query()->where('sku', '=', $sku)->where('id', '!=', $product->id)->exists()) {
            throw new \InvalidArgumentException("SKU {$sku} is already used by another product.");
        }

        $this->updateProductMetadata($product, $row, $currentSku === $sku ? $sku : null);

        if ($currentSku !== $sku) {
            $this->skuRename->rename($currentSku, $sku);
        }

        $item->sku = $sku;
        $item->save();

        return ['action' => 'promote', 'sku' => $sku];
    }

    private function updateProductMetadata(Product $product, WaterDecalPromoteApplyRowDTO $row, ?string $sku = null): void
    {
        if ($sku !== null) {
            $product->sku = $sku;
        }
        $product->main_type = WaterDecalSkuNormalizer::MAIN_TYPE;
        $product->vendor = $row->vendor !== '' ? $row->vendor : WaterDecalSkuNormalizer::DEFAULT_VENDOR;
        $product->description = $row->description !== '' ? $row->description : $this->normalizer->proposeDescription((string) $product->description);
        $product->type = $row->type !== '' ? $row->type : ($this->resolveType($product->description, $product->type) ?? 'Others');
        $product->save();
    }

    private function deleteOrphanProductIfUnused(Product $product): void
    {
        $remainingLines = PurchaseOrderItem::query()->where('product_id', '=', $product->id)->count();
        if ($remainingLines > 0) {
            return;
        }

        $product->delete();
    }

    /**
     * @return array{0: string, 1: string, 2: string|null, 3: array<string, mixed>|null}
     */
    private function resolveIntention(Product $product, string $proposedSku): array
    {
        $target = Product::query()->with('sellingPrice')->where('sku', '=', $proposedSku)->first();

        if ($target !== null && (int) $target->id !== (int) $product->id) {
            return [
                'merge',
                'Merge into existing catalog product',
                'PO line will point at the existing product; duplicate catalog row removed when unused.',
                $this->mergeTargetPayload($target),
            ];
        }

        if (
            $this->normalizer->isWaterDecalMainType($product->main_type)
            && strtoupper(trim((string) $product->sku)) === strtoupper($proposedSku)
            && trim((string) ($product->vendor ?? '')) === WaterDecalSkuNormalizer::DEFAULT_VENDOR
        ) {
            return ['noop', 'Already a water decal — update metadata only', null, null];
        }

        if (Product::query()->where('sku', '=', $proposedSku)->where('id', '!=', $product->id)->exists()) {
            return ['blocked', 'Blocked — SKU already taken', 'Choose a different SKU or confirm merge.', null];
        }

        return [
            'promote',
            'Promote in place — rename SKU and set water decal fields',
            null,
            null,
        ];
    }

    /**
     * @return array{
     *   product_id: int,
     *   sku: string,
     *   description: string|null,
     *   handle: string|null,
     *   selling_price: string|null
     * }
     */
    private function mergeTargetPayload(Product $target): array
    {
        $sellingPrice = $target->sellingPrice?->selling_price;

        return [
            'product_id' => (int) $target->id,
            'sku' => (string) $target->sku,
            'description' => $target->description,
            'handle' => $target->handle,
            'selling_price' => $sellingPrice !== null ? (string) $sellingPrice : null,
        ];
    }

    private function resolveType(string $description, ?string $existingType): string
    {
        $derived = $this->types->deriveFromName($description);
        if ($derived !== null && trim($derived) !== '') {
            return $derived;
        }

        $existing = trim((string) ($existingType ?? ''));

        return $existing !== '' ? $existing : 'Others';
    }
}
