<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Products\Exceptions\DuplicateSkuException;
use Illuminate\Database\QueryException;

final class ProductCreateService
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
    public function create(array $payload): Product
    {
        $product = new Product([
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
            return $this->products->create($product);
        } catch (QueryException $e) {
            // MySQL / SQLite will throw on unique index violation; map to typed business exception.
            throw new DuplicateSkuException('SKU already exists.', previous: $e);
        }
    }
}
