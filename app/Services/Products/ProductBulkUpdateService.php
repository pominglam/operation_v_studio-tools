<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Services\Products\Exceptions\DuplicateSkuException;
use Illuminate\Database\QueryException;

final class ProductBulkUpdateService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<int, string>  $uuids
     * @param  array{
     *   sku?: string,
     *   barcode?: string|null,
     *   description?: string,
     *   type?: string|null,
     *   vendor?: string|null,
 *   published_on_shopify?: bool,
     *   price?: string|int|float|null,
     *   order?: int|null,
     *   filled?: int|null,
     *   extended?: string|int|float|null
     * } $changes
     */
    public function updateByUuids(array $uuids, array $changes): int
    {
        if ($uuids === [] || $changes === []) {
            return 0;
        }

        $updates = [];

        if (array_key_exists('sku', $changes)) {
            $updates['sku'] = trim($changes['sku']);
        }

        if (array_key_exists('barcode', $changes)) {
            $barcode = $changes['barcode'];
            $barcode = $barcode !== null ? trim($barcode) : null;
            $updates['barcode'] = $barcode !== '' ? $barcode : null;
        }

        if (array_key_exists('description', $changes)) {
            $updates['description'] = trim($changes['description']);
        }

        if (array_key_exists('type', $changes)) {
            $type = $changes['type'];
            $type = $type !== null ? trim($type) : null;
            $updates['type'] = $type !== '' ? $type : null;
        }

        if (array_key_exists('vendor', $changes)) {
            $vendor = $changes['vendor'];
            $vendor = $vendor !== null ? trim($vendor) : null;
            $updates['vendor'] = $vendor !== '' ? $vendor : null;
        }

        if (array_key_exists('published_on_shopify', $changes)) {
            $updates['published_on_shopify'] = (bool) $changes['published_on_shopify'];
        }

        if (array_key_exists('price', $changes)) {
            $price = $changes['price'];
            $updates['price'] = $price !== null ? trim((string) $price) : null;
        }

        if (array_key_exists('order', $changes)) {
            $updates['order_qty'] = $changes['order'];
        }

        if (array_key_exists('filled', $changes)) {
            $updates['filled_qty'] = $changes['filled'];
        }

        if (array_key_exists('extended', $changes)) {
            $extended = $changes['extended'];
            $updates['extended'] = $extended !== null ? trim((string) $extended) : null;
        }

        try {
            return $this->products->updateByUuids($uuids, $updates);
        } catch (QueryException $e) {
            throw new DuplicateSkuException('SKU already exists.', previous: $e);
        }
    }
}



