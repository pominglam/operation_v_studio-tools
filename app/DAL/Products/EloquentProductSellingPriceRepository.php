<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductSellingPriceUpsertContext;
use App\Models\Product;
use App\Models\ProductSellingPrice;
use App\Models\ProductSellingPriceHistory;

final class EloquentProductSellingPriceRepository implements ProductSellingPriceRepository
{
    public function upsertForProduct(
        Product $product,
        ?string $sellingPrice,
        string $currency = 'CAD',
        ?ProductSellingPriceUpsertContext $context = null,
    ): ProductSellingPrice {
        /** @var ProductSellingPrice|null $existing */
        $existing = ProductSellingPrice::query()->where('product_id', $product->id)->first();
        $previousPrice = $this->normalizeMoney($existing?->selling_price);
        $newPrice = $this->normalizeMoney($sellingPrice);

        /** @var ProductSellingPrice $row */
        $row = ProductSellingPrice::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'product_uuid' => $product->uuid,
                'selling_price' => $newPrice,
                'currency' => $currency,
            ],
        );

        if ($context !== null && $this->priceChanged($previousPrice, $newPrice)) {
            ProductSellingPriceHistory::query()->create([
                'product_id' => $product->id,
                'product_uuid' => $product->uuid,
                'previous_price' => $previousPrice,
                'new_price' => $newPrice,
                'currency' => $currency,
                'source' => $context->source,
                'purchase_order_id' => $context->purchaseOrderId,
            ]);
        }

        return $row;
    }

    public function productIdsWithSellingPriceSet(): array
    {
        /** @var array<int, int> $ids */
        $ids = ProductSellingPrice::query()
            ->whereNotNull('selling_price')
            ->where('selling_price', '<>', '')
            ->orderBy('product_id')
            ->pluck('product_id')
            ->all();

        return array_values($ids);
    }

    private function normalizeMoney(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $trimmed)) {
            return null;
        }

        return number_format((float) $trimmed, 2, '.', '');
    }

    private function priceChanged(?string $previous, ?string $next): bool
    {
        if ($previous === null && $next === null) {
            return false;
        }

        if ($previous === null || $next === null) {
            return true;
        }

        return $previous !== $next;
    }
}
