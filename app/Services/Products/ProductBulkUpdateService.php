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
     * @param  array<int, string>  $uuids
     * @param  array{
     *   sku?: string,
     *   barcode?: string|null,
     *   description?: string,
     *   handle?: string|null,
     *   main_type?: string|null,
     *   type?: string|null,
     *   vendor?: string|null,
     *   published_on_shopify?: bool,
     *   latest_arrival?: bool,
     *   archived?: bool,
     *   order?: int|null,
     *   filled?: int|null,
     *   available?: int|null,
     *   maintain?: int|null,
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

        if (array_key_exists('handle', $changes)) {
            $handle = $changes['handle'];
            $handle = $handle !== null ? trim($handle) : null;
            $updates['handle'] = $handle !== '' ? $handle : null;
        }

        if (array_key_exists('main_type', $changes)) {
            $mainType = $changes['main_type'];
            $mainType = $mainType !== null ? trim($mainType) : null;
            // Allow clearing main_type to an empty string.
            $updates['main_type'] = $this->normalizeMainType($mainType);
        }

        if (array_key_exists('type', $changes)) {
            $type = $changes['type'];
            $type = $type !== null ? trim($type) : null;
            $updates['type'] = $type !== '' ? $type : null;
        }

        if (array_key_exists('grade', $changes)) {
            $grade = $changes['grade'];
            $grade = $grade !== null ? trim($grade) : null;
            $updates['grade'] = $grade !== '' ? $grade : null;
        }

        if (array_key_exists('scale', $changes)) {
            $scale = $changes['scale'];
            $scale = $scale !== null ? trim($scale) : null;
            $updates['scale'] = $scale !== '' ? $scale : null;
        }

        if (array_key_exists('series', $changes)) {
            $series = $changes['series'];
            $series = $series !== null ? trim($series) : null;
            $updates['series'] = $series !== '' ? $series : null;
        }

        if (array_key_exists('vendor', $changes)) {
            $vendor = $changes['vendor'];
            $vendor = $vendor !== null ? trim($vendor) : null;
            $updates['vendor'] = $vendor !== '' ? $vendor : null;
        }

        if (array_key_exists('published_on_shopify', $changes)) {
            $updates['published_on_shopify'] = (bool) $changes['published_on_shopify'];
        }

        if (array_key_exists('latest_arrival', $changes)) {
            $updates['latest_arrival'] = (bool) $changes['latest_arrival'];
        }

        if (array_key_exists('archived', $changes)) {
            $updates['archived_at'] = (bool) $changes['archived'] ? now() : null;
        }

        if (array_key_exists('order', $changes)) {
            $updates['order_qty'] = $changes['order'];
        }

        if (array_key_exists('filled', $changes)) {
            $updates['filled_qty'] = $changes['filled'];
        }

        if (array_key_exists('available', $changes)) {
            $updates['available_qty'] = $changes['available'];
        }

        if (array_key_exists('maintain', $changes)) {
            $updates['maintain_qty'] = $changes['maintain'];
        }

        if (array_key_exists('extended', $changes)) {
            $extended = $changes['extended'];
            $updates['extended'] = $extended !== null ? trim((string) $extended) : null;
        }

        try {
            return $this->products->updateByUuids($uuids, $updates);
        } catch (QueryException $e) {
            if ($this->isSkuUniqueViolation($e)) {
                throw new DuplicateSkuException('SKU already exists.', previous: $e);
            }
            throw $e;
        }
    }
}
