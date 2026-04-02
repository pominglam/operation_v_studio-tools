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

    private function normalizeMainType(?string $value): string
    {
        if ($value === null) {
            return '';
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
     *   vendor?: string|null,
     *   order?: int|null,
     *   filled?: int|null,
     *   available?: int|null,
     *   maintain?: int|null,
     *   extended?: string|null
     * } $payload
     */
    public function create(array $payload): Product
    {
        $attrs = [
            'sku' => $payload['sku'],
            'barcode' => $payload['barcode'] ?? null,
            'description' => $payload['description'],
            'handle' => $payload['handle'] ?? null,
            'type' => $payload['type'] ?? null,
            'vendor' => $payload['vendor'] ?? null,
            'order_qty' => $payload['order'] ?? null,
            'filled_qty' => $payload['filled'] ?? null,
            'available_qty' => $payload['available'] ?? null,
            'maintain_qty' => $payload['maintain'] ?? null,
            'extended' => $payload['extended'] ?? null,
        ];

        // If the key is omitted, let the DB default apply (currently: "model kit").
        // If the key is present but blank/null, persist empty string (meaning: no Shopify tags).
        if (array_key_exists('main_type', $payload)) {
            $raw = $payload['main_type'];
            $attrs['main_type'] = is_string($raw) ? $this->normalizeMainType($raw) : '';
        }

        $product = new Product($attrs);

        try {
            return $this->products->create($product);
        } catch (QueryException $e) {
            if ($this->isSkuUniqueViolation($e)) {
                throw new DuplicateSkuException('SKU already exists.', previous: $e);
            }
            throw $e;
        }
    }
}
