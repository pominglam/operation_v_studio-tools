<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Products\Exceptions\DuplicateSkuException;
use Illuminate\Database\QueryException;

final class ProductUpdateService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param array{
     *   sku: string,
     *   barcode?: string|null,
     *   description: string,
     *   type?: string|null,
     *   vendor?: string|null,
     *   price?: string|null,
     *   order?: int|null,
     *   filled?: int|null,
     *   available?: int|null,
     *   extended?: string|null
     * } $payload
     */
    public function update(string $uuid, array $payload): Product
    {
        $product = $this->products->findByUuidOrFail($uuid);

        $product->fill([
            'sku' => $payload['sku'],
            'barcode' => $payload['barcode'] ?? null,
            'description' => $payload['description'],
            'type' => $payload['type'] ?? null,
            'vendor' => $payload['vendor'] ?? null,
            'price' => $payload['price'] ?? null,
            'order_qty' => $payload['order'] ?? null,
            'filled_qty' => $payload['filled'] ?? null,
            'available_qty' => $payload['available'] ?? null,
            'extended' => $payload['extended'] ?? null,
        ]);

        try {
            return $this->products->save($product);
        } catch (QueryException $e) {
            throw new DuplicateSkuException('SKU already exists.', previous: $e);
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
}
