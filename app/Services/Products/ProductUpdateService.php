<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Products\Exceptions\DuplicateSkuException;
use App\Support\Products\ProductHoldQty;
use Illuminate\Database\QueryException;

final class ProductUpdateService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    private function isSkuUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        if ($sqlState !== '23000') {
            return false;
        }

        $msg = $e->getMessage();

        return str_contains($msg, 'products.sku')
            || str_contains($msg, 'products_sku_unique')
            || str_contains($msg, 'Duplicate entry')
            || str_contains($msg, 'UNIQUE constraint failed: products.sku');
    }

    private function normalizeMainType(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if (strtolower($v) === 'empty (no shopify tags)') {
            return '';
        }

        return $v;
    }

    /**
     * @param array{
     *   sku: string,
     *   barcode?: string|null,
     *   description: string,
     *   handle?: string|null,
     *   main_type?: string|null,
     *   type?: string|null,
     *   grade?: string|null,
     *   scale?: string|null,
     *   series?: string|null,
     *   vendor?: string|null,
     *   order?: int|null,
     *   filled?: int|null,
     *   available?: int|null,
     *   maintain?: int|null,
     *   extended?: string|null
     * } $payload
     */
    public function update(string $uuid, array $payload): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);

        $mainType = array_key_exists('main_type', $payload) ? $payload['main_type'] : null;
        $mainType = is_string($mainType) ? $this->normalizeMainType($mainType) : (array_key_exists('main_type', $payload) ? '' : null);

        $product->fill([
            'sku' => $payload['sku'],
            'barcode' => $payload['barcode'] ?? null,
            'description' => $payload['description'],
            'handle' => $payload['handle'] ?? null,
            // When provided, allow clearing main_type to an empty string.
            'main_type' => $mainType !== null ? $mainType : $product->main_type,
            'type' => $payload['type'] ?? null,
            'grade' => $payload['grade'] ?? null,
            'scale' => $payload['scale'] ?? null,
            'series' => $payload['series'] ?? null,
            'vendor' => $payload['vendor'] ?? null,
            'order_qty' => $payload['order'] ?? null,
            'filled_qty' => $payload['filled'] ?? null,
            'available_qty' => $payload['available'] ?? null,
            'maintain_qty' => $payload['maintain'] ?? null,
            'extended' => $payload['extended'] ?? null,
        ]);

        try {
            return $this->products->save($product);
        } catch (QueryException $e) {
            if ($this->isSkuUniqueViolation($e)) {
                throw new DuplicateSkuException('SKU already exists.', previous: $e);
            }
            throw $e;
        }
    }

    public function updateBarcode(string $uuid, ?string $barcode): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'barcode' => $barcode,
        ]);

        return $this->products->save($product);
    }

    public function updateFilled(string $uuid, ?int $filled): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'filled_qty' => $filled,
        ]);

        return $this->products->save($product);
    }

    public function updateAvailable(string $uuid, ?int $available): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $nextAvailable = $available === null ? null : max(0, $available);
        ProductHoldQty::assertHoldWithinAvailable($product->hold_qty, $nextAvailable);

        $product->fill([
            'available_qty' => $nextAvailable,
        ]);

        return $this->products->save($product);
    }

    public function updateMaintain(string $uuid, ?int $maintain): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'maintain_qty' => $maintain,
        ]);

        return $this->products->save($product);
    }

    public function updateHold(string $uuid, ?int $hold): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $nextHold = $hold === null ? 0 : max(0, $hold);
        ProductHoldQty::assertHoldWithinAvailable($nextHold, $product->available_qty);

        $product->fill([
            'hold_qty' => $nextHold,
        ]);

        return $this->products->save($product);
    }

    public function updateReady(string $uuid, bool $isReady): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'is_ready' => $isReady,
        ]);

        return $this->products->save($product);
    }

    public function updateLatestArrival(string $uuid, bool $latestArrival): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'latest_arrival' => $latestArrival,
        ]);

        return $this->products->save($product);
    }

    public function updateCritical(string $uuid, bool $isCritical): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'is_critical' => $isCritical,
        ]);

        return $this->products->save($product);
    }

    public function updateDiscontinued(string $uuid, bool $isDiscontinued): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'is_discontinued' => $isDiscontinued,
        ]);

        return $this->products->save($product);
    }

    public function updateHazardousShipment(string $uuid, bool $isHazardousShipment): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'is_hazardous_shipment' => $isHazardousShipment,
        ]);

        return $this->products->save($product);
    }

    public function updateShipmentMethod(string $uuid, ?string $shipmentMethod): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);
        $product->fill([
            'shipment_method' => $shipmentMethod,
        ]);

        return $this->products->save($product);
    }
}
