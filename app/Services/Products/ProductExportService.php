<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ProductExportService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<int, string>  $types
     * @return Collection<int, Product>
     */
    public function listForExport(?string $search, array $types, ?string $sortBy, string $sortDir): Collection
    {
        return $this->products->listForExport($search, $types, $sortBy, $sortDir);
    }

    /**
     * @return Collection<int, Product>
     */
    public function listMissingSellingPriceForExport(?string $sortBy, string $sortDir): Collection
    {
        return $this->products->listMissingSellingPriceForExport($sortBy, $sortDir);
    }

    /**
     * @return Collection<int, Product>
     */
    public function listMissingBarcodeForExport(?string $sortBy, string $sortDir): Collection
    {
        return $this->products->listMissingBarcodeForExport($sortBy, $sortDir);
    }

    /**
     * @return Collection<int, Product>
     */
    public function listBarcodedForExportSorted(): Collection
    {
        return $this->products->listBarcodedForExportSorted();
    }

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listSelectedForShopifyExport(array $uuids, ?string $sortBy, string $sortDir): Collection
    {
        return $this->products->listByUuidsForExport($uuids, $sortBy, $sortDir);
    }

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listSelectedMissingBarcodeForExport(array $uuids, ?string $sortBy, string $sortDir): Collection
    {
        return $this->products->listMissingBarcodeByUuidsForExport($uuids, $sortBy, $sortDir);
    }

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listSelectedBarcodedForExportSorted(array $uuids): Collection
    {
        return $this->products->listBarcodedByUuidsForExportSorted($uuids);
    }

    /**
     * @return array<int, string>
     */
    public function shopifyHeader(): array
    {
        return [
            'Handle',
            'Title',
            'Body (HTML)',
            'Vendor',
            'Product Category',
            'Type',
            'Tags',
            'Published',
            'Option1 Name',
            'Option1 Value',
            'Option1 Linked To',
            'Option2 Name',
            'Option2 Value',
            'Option2 Linked To',
            'Option3 Name',
            'Option3 Value',
            'Option3 Linked To',
            'Variant SKU',
            'Variant Grams',
            'Variant Inventory Tracker',
            'Variant Inventory Qty',
            'Variant Inventory Policy',
            'Variant Fulfillment Service',
            'Price',
            'Variant Compare At Price',
            'Variant Requires Shipping',
            'Variant Taxable',
            'Unit Price Total Measure',
            'Unit Price Total Measure Unit',
            'Unit Price Base Measure',
            'Unit Price Base Measure Unit',
            'Variant Barcode',
            'Image Src',
            'Image Position',
            'Image Alt Text',
            'Gift Card',
            'SEO Title',
            'SEO Description',
            'Variant Image',
            'Variant Weight Unit',
            'Variant Tax Code',
            'Cost per item',
            'Status',
        ];
    }

    public function shopifyHandleForProduct(Product $product, array &$usedHandles): string
    {
        // Prefer stored handle so exports update the existing Shopify product (handle is the primary identifier in Shopify CSV imports).
        $stored = $product->handle !== null ? trim((string) $product->handle) : '';
        if ($stored !== '') {
            $handle = $stored;
        } else {
            $base = Str::slug((string) $product->description);
            if ($base === '') {
                $base = Str::slug((string) $product->sku);
            }
            if ($base === '') {
                $base = 'product';
            }
            $handle = $base;
        }

        if (isset($usedHandles[$handle])) {
            $base = $stored !== '' ? $stored : Str::slug((string) $product->description);
            if ($base === '') {
                $base = Str::slug((string) $product->sku);
            }
            if ($base === '') {
                $base = 'product';
            }
            $handle = $base.'-'.Str::slug((string) $product->sku);
        }
        if ($handle === '' || isset($usedHandles[$handle])) {
            $handle = $handle.'-'.(string) $product->sku;
        }

        $usedHandles[$handle] = true;

        return $handle;
    }

    /**
     * @return array<int, string>
     */
    public function shopifyRow(Product $product, string $handle): array
    {
        $filledQty = $product->filled_qty ?? 0;
        $selling = $product->sellingPrice?->selling_price;
        $type = $product->type !== null ? trim((string) $product->type) : '';

        return [
            $handle, // Handle
            (string) $product->description, // Title
            '', // Body (HTML)
            '', // Vendor
            '', // Product Category
            $type, // Type
            $type, // Tags
            $product->published_on_shopify ? 'TRUE' : 'FALSE', // Published
            'Title', // Option1 Name
            'Default Title', // Option1 Value
            '', // Option1 Linked To
            '', // Option2 Name
            '', // Option2 Value
            '', // Option2 Linked To
            '', // Option3 Name
            '', // Option3 Value
            '', // Option3 Linked To
            (string) $product->sku, // Variant SKU
            '0.0', // Variant Grams
            'shopify', // Variant Inventory Tracker
            (string) max(0, (int) $filledQty), // Variant Inventory Qty
            'continue', // Variant Inventory Policy
            'manual', // Variant Fulfillment Service
            (string) ($selling ?? ''), // Variant Price
            '', // Variant Compare At Price
            'true', // Variant Requires Shipping
            'true', // Variant Taxable
            '', // Unit Price Total Measure
            '', // Unit Price Total Measure Unit
            '', // Unit Price Base Measure
            '', // Unit Price Base Measure Unit
            (string) ($product->barcode ?? ''), // Variant Barcode
            '', // Image Src
            '', // Image Position
            '', // Image Alt Text
            'false', // Gift Card
            '', // SEO Title
            '', // SEO Description
            '', // Variant Image
            'kg', // Variant Weight Unit
            '', // Variant Tax Code
            '', // Cost per item (intentionally omitted)
            'active', // Status
        ];
    }

}
