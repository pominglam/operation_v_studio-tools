<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\DAL\InventoryChecks\InventoryCheckRepository;
use App\DAL\Products\ProductRepository;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\Product;
use App\Models\ProductExternalAsset;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EmployeeInventoryCountService
{
    public function __construct(
        private readonly InventoryCheckRepository $inventoryChecks,
        private readonly ProductRepository $products,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createSession(?string $name, string $createdByRole = 'employee'): array
    {
        $name = is_string($name) ? trim($name) : '';

        $session = new InventoryCheck([
            'name' => $name !== '' ? $name : null,
            'source' => 'employee_scan',
            'workflow_state' => 'draft',
            'created_by_role' => $createdByRole,
        ]);
        $this->inventoryChecks->create($session);

        return $this->sessionPayload((string) $session->uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionPayload(string $sessionUuid): array
    {
        $session = $this->inventoryChecks->findByUuidOrFail($sessionUuid);
        $session->load([
            'items' => fn ($q) => $q->orderByDesc('id'),
            'items.product',
            'items.product.sellingPrice',
        ]);

        $productIds = $session->items
            ->pluck('product_id')
            ->filter(static fn ($v): bool => is_int($v) || ctype_digit((string) $v))
            ->map(static fn ($v): int => (int) $v)
            ->unique()
            ->values()
            ->all();
        $imageUrls = $this->resolveImageUrlsByProductId($productIds);

        $items = $session->items->map(function (InventoryCheckItem $item) use ($imageUrls): array {
            $product = $item->product;
            $selling = $item->selling_price_snapshot;
            if ($selling === null && $product?->sellingPrice?->selling_price !== null) {
                $selling = (string) $product->sellingPrice->selling_price;
            }
            $landed = $item->landed_unit_cost_snapshot;
            if ($landed === null && $product?->latest_landed_unit_cost !== null) {
                $landed = (string) $product->latest_landed_unit_cost;
            }

            return [
                'id' => (int) $item->id,
                'product_id' => $product?->uuid,
                'barcode_scanned' => $item->barcode_scanned,
                'sku' => $item->sku,
                'product_name' => $product?->description ?? $item->english_name ?? $item->product_name,
                'quantity' => (int) ($item->quantity_in_store ?? 0),
                'available_amount' => $item->available_amount,
                'difference' => $item->difference,
                'selling_price' => $selling !== null ? (string) $selling : null,
                'landed_unit_cost' => $landed !== null ? (string) $landed : null,
                'match_status' => $item->match_status,
                'match_error' => $item->match_error,
                'issue_flag' => (bool) ($item->issue_flag ?? false),
                'issue_reason' => $item->issue_reason,
                'applied' => (bool) $item->applied,
                'applied_at' => optional($item->applied_at)->toISOString(),
                'image_url' => $product !== null ? ($imageUrls[(int) $product->id] ?? null) : null,
                'updated_at' => optional($item->updated_at)->toISOString(),
            ];
        })->all();

        $units = array_sum(array_map(static fn (array $x): int => (int) ($x['quantity'] ?? 0), $items));
        $issues = count(array_filter($items, static fn (array $x): bool => (bool) ($x['issue_flag'] ?? false)));

        return [
            'session' => [
                'id' => (string) $session->uuid,
                'name' => $session->name,
                'source' => $session->source,
                'workflow_state' => $session->workflow_state,
                'created_by_role' => $session->created_by_role,
                'applied_at' => optional($session->applied_at)->toISOString(),
                'created_at' => optional($session->created_at)->toISOString(),
                'updated_at' => optional($session->updated_at)->toISOString(),
                'counts' => [
                    'lines' => count($items),
                    'units' => $units,
                    'issues' => $issues,
                ],
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scanBarcode(string $sessionUuid, string $barcode): array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            throw new \InvalidArgumentException('Barcode is required.');
        }

        return DB::transaction(function () use ($sessionUuid, $barcode): array {
            $session = $this->inventoryChecks->findByUuidOrFail($sessionUuid);
            $this->assertSessionEditable($session);

            /** @var Product|null $product */
            $product = Product::query()
                ->with(['sellingPrice'])
                ->notArchived()
                ->where('barcode', '=', $barcode)
                ->first();

            if ($product === null) {
                $line = InventoryCheckItem::query()
                    ->where('inventory_check_id', '=', (int) $session->id)
                    ->where('issue_flag', '=', true)
                    ->where('barcode_scanned', '=', $barcode)
                    ->first();

                if (! $line instanceof InventoryCheckItem) {
                    $line = new InventoryCheckItem([
                        'inventory_check_id' => (int) $session->id,
                        'product_id' => null,
                        'barcode_scanned' => $barcode,
                        'sku' => $barcode,
                        'quantity_in_store' => 0,
                        'available_amount' => null,
                        'difference' => null,
                        'match_status' => 'unmatched',
                        'match_error' => 'No active product found for barcode.',
                        'issue_flag' => true,
                        'issue_reason' => 'Product not found or archived.',
                    ]);
                    $this->inventoryChecks->createItem($line);
                }

                $line->quantity_in_store = (int) ($line->quantity_in_store ?? 0) + 1;
                $line->difference = null;
                $this->inventoryChecks->saveItem($line);
                $this->touchReadyForReview($session);

                return $this->sessionPayload((string) $session->uuid);
            }

            /** @var InventoryCheckItem|null $line */
            $line = InventoryCheckItem::query()
                ->where('inventory_check_id', '=', (int) $session->id)
                ->where('product_id', '=', (int) $product->id)
                ->first();

            if (! $line instanceof InventoryCheckItem) {
                $line = new InventoryCheckItem([
                    'inventory_check_id' => (int) $session->id,
                    'product_id' => (int) $product->id,
                    'barcode_scanned' => $barcode,
                    'handle' => $product->handle,
                    'vendor' => $product->vendor,
                    'sku' => $product->sku,
                    'type' => $product->type,
                    'product_name' => $product->description,
                    'english_name' => $product->description,
                    'available_amount' => $product->available_qty,
                    'quantity_in_store' => 0,
                    'difference' => null,
                    'match_status' => 'matched',
                    'match_error' => null,
                    'issue_flag' => false,
                    'issue_reason' => null,
                    'selling_price_snapshot' => $product->sellingPrice?->selling_price,
                    'landed_unit_cost_snapshot' => $product->latest_landed_unit_cost,
                ]);
                $this->inventoryChecks->createItem($line);
            }

            $line->barcode_scanned = $barcode;
            $line->quantity_in_store = (int) ($line->quantity_in_store ?? 0) + 1;
            $line->available_amount = $line->available_amount ?? $product->available_qty;
            $line->difference = $line->available_amount !== null
                ? ((int) $line->quantity_in_store - (int) $line->available_amount)
                : null;
            $line->match_status = 'matched';
            $line->match_error = null;
            $line->issue_flag = false;
            $line->issue_reason = null;
            $line->selling_price_snapshot = $product->sellingPrice?->selling_price;
            $line->landed_unit_cost_snapshot = $product->latest_landed_unit_cost;
            $this->inventoryChecks->saveItem($line);
            $this->touchReadyForReview($session);

            return $this->sessionPayload((string) $session->uuid);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function setLineQuantity(string $sessionUuid, int $lineId, int $quantity): array
    {
        return $this->updateLine($sessionUuid, $lineId, $quantity, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateLine(
        string $sessionUuid,
        int $lineId,
        ?int $quantity,
        ?string $productName,
        bool $returnSessionPayload = true,
    ): array {
        $quantity = $quantity !== null ? max(0, $quantity) : null;
        $productName = is_string($productName) ? trim($productName) : null;

        return DB::transaction(function () use ($sessionUuid, $lineId, $quantity, $productName, $returnSessionPayload): array {
            $session = $this->inventoryChecks->findByUuidOrFail($sessionUuid);
            $this->assertSessionEditable($session);
            $line = $this->inventoryChecks->findItemInSessionOrFail($session, $lineId);

            if ($quantity !== null) {
                $line->quantity_in_store = $quantity;
                $line->difference = $line->available_amount !== null
                    ? ((int) $quantity - (int) $line->available_amount)
                    : null;
            }

            if ($productName !== null) {
                $line->product_name = $productName !== '' ? $productName : null;
                $line->english_name = $line->english_name ?? $line->product_name;
            }

            $line->applied = false;
            $line->applied_at = null;
            $this->inventoryChecks->saveItem($line);
            $this->touchReadyForReview($session);

            return $returnSessionPayload ? $this->sessionPayload((string) $session->uuid) : [];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function assignLineToProduct(string $sessionUuid, int $lineId, string $productUuid): array
    {
        return DB::transaction(function () use ($sessionUuid, $lineId, $productUuid): array {
            $session = $this->inventoryChecks->findByUuidOrFail($sessionUuid);
            $this->assertSessionEditable($session);
            $line = $this->inventoryChecks->findItemInSessionOrFail($session, $lineId);
            $product = $this->products->findByUuidOrFail($productUuid);
            $product->loadMissing('sellingPrice');

            $line->product_id = (int) $product->id;
            $line->handle = $product->handle;
            $line->vendor = $product->vendor;
            $line->sku = $product->sku;
            $line->type = $product->type;
            $line->product_name = $product->description;
            $line->english_name = $product->description;
            $line->available_amount = $product->available_qty;
            $line->difference = $line->quantity_in_store !== null && $product->available_qty !== null
                ? ((int) $line->quantity_in_store - (int) $product->available_qty)
                : null;
            $line->match_status = 'matched';
            $line->match_error = null;
            $line->issue_flag = false;
            $line->issue_reason = null;
            $line->selling_price_snapshot = $product->sellingPrice?->selling_price;
            $line->landed_unit_cost_snapshot = $product->latest_landed_unit_cost;
            $line->applied = false;
            $line->applied_at = null;

            $this->inventoryChecks->saveItem($line);
            $this->touchReadyForReview($session);

            return $this->sessionPayload((string) $session->uuid);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function removeLine(string $sessionUuid, int $lineId): array
    {
        return DB::transaction(function () use ($sessionUuid, $lineId): array {
            $session = $this->inventoryChecks->findByUuidOrFail($sessionUuid);
            $this->assertSessionEditable($session);
            $line = $this->inventoryChecks->findItemInSessionOrFail($session, $lineId);
            $this->inventoryChecks->deleteItem($line);
            $this->touchReadyForReview($session);

            return $this->sessionPayload((string) $session->uuid);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function flagBarcodeIssue(string $sessionUuid, string $barcode, ?string $reason): array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            throw new \InvalidArgumentException('Barcode is required.');
        }
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '') {
            $reason = 'Employee flagged this barcode for review.';
        }

        return DB::transaction(function () use ($sessionUuid, $barcode, $reason): array {
            $session = $this->inventoryChecks->findByUuidOrFail($sessionUuid);
            $this->assertSessionEditable($session);

            /** @var InventoryCheckItem|null $line */
            $line = InventoryCheckItem::query()
                ->where('inventory_check_id', '=', (int) $session->id)
                ->where('issue_flag', '=', true)
                ->where('barcode_scanned', '=', $barcode)
                ->first();

            if (! $line instanceof InventoryCheckItem) {
                $line = new InventoryCheckItem([
                    'inventory_check_id' => (int) $session->id,
                    'product_id' => null,
                    'barcode_scanned' => $barcode,
                    'sku' => $barcode,
                    'quantity_in_store' => 0,
                    'match_status' => 'unmatched',
                    'issue_flag' => true,
                ]);
                $this->inventoryChecks->createItem($line);
            }

            $line->quantity_in_store = (int) ($line->quantity_in_store ?? 0) + 1;
            $line->issue_flag = true;
            $line->issue_reason = $reason;
            $line->match_status = 'unmatched';
            $line->match_error = 'Employee flagged issue.';
            $line->difference = null;
            $this->inventoryChecks->saveItem($line);
            $this->touchReadyForReview($session);

            return $this->sessionPayload((string) $session->uuid);
        });
    }

    /**
     * @param  array<int, int>|null  $lineIds
     * @return array{applied:int,skipped:int,session:array<string,mixed>}
     */
    public function applySessionQuantities(
        string $sessionUuid,
        ?array $lineIds = null,
        bool $applyQuantity = true,
        bool $applyName = true,
        string $applyQuantityMode = 'overwrite',
    ): array {
        $applyQuantityMode = strtolower(trim($applyQuantityMode));
        if (! in_array($applyQuantityMode, ['overwrite', 'increment'], true)) {
            $applyQuantityMode = 'overwrite';
        }

        return DB::transaction(function () use ($sessionUuid, $lineIds, $applyQuantity, $applyName, $applyQuantityMode): array {
            $session = $this->inventoryChecks->findByUuidOrFail($sessionUuid);
            $session->load(['items', 'items.product']);

            $allowed = null;
            if (is_array($lineIds) && $lineIds !== []) {
                $allowed = array_values(array_unique(array_map(static fn ($v): int => (int) $v, $lineIds)));
            }

            $applied = 0;
            $skipped = 0;
            /** @var InventoryCheckItem $item */
            foreach ($session->items as $item) {
                if ($allowed !== null && ! in_array((int) $item->id, $allowed, true)) {
                    continue;
                }
                if ((bool) $item->issue_flag === true) {
                    $skipped++;

                    continue;
                }
                if ($item->product === null) {
                    $skipped++;

                    continue;
                }

                $product = $item->product;
                $lineName = trim((string) ($item->product_name ?? ''));
                $canApplyQuantity = $applyQuantity && $item->quantity_in_store !== null;
                $canApplyName = $applyName && $lineName !== '';
                if (! $canApplyQuantity && ! $canApplyName) {
                    $skipped++;

                    continue;
                }

                if ($canApplyQuantity) {
                    if ($applyQuantityMode === 'increment') {
                        $product->available_qty = (int) ($product->available_qty ?? 0) + (int) $item->quantity_in_store;
                    } else {
                        $product->available_qty = (int) $item->quantity_in_store;
                    }
                }
                if ($canApplyName) {
                    $product->description = $lineName;
                }
                $product->save();

                $item->applied = true;
                $item->applied_at = now();
                $this->inventoryChecks->saveItem($item);
                $applied++;
            }

            $session->workflow_state = 'applied';
            $session->applied_at = now();
            $this->inventoryChecks->save($session);

            return [
                'applied' => $applied,
                'skipped' => $skipped,
                'session' => $this->sessionPayload((string) $session->uuid),
            ];
        });
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, string>
     */
    private function resolveImageUrlsByProductId(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var \Illuminate\Support\Collection<int, ProductExternalAsset> $assets */
        $assets = ProductExternalAsset::query()
            ->whereIn('product_id', $productIds)
            ->where(function ($q): void {
                $q->where('kind', '=', 'image')
                    ->orWhere('mime_type', 'like', 'image/%');
            })
            ->orderByRaw('sort_order is null')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($assets as $asset) {
            $pid = (int) $asset->product_id;
            if ($pid <= 0 || array_key_exists($pid, $out)) {
                continue;
            }
            $out[$pid] = "/api/v1/product-assets/{$asset->id}/view";
        }

        return $out;
    }

    private function touchReadyForReview(InventoryCheck $session): void
    {
        $session->workflow_state = 'ready_for_review';
        $this->inventoryChecks->save($session);
    }

    private function assertSessionEditable(InventoryCheck $session): void
    {
        $state = strtolower(trim((string) ($session->workflow_state ?? 'draft')));
        if ($state === 'applied') {
            throw new ConflictHttpException('This inventory count session is already applied.');
        }
    }
}
