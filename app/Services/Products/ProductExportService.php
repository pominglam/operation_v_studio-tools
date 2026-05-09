<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Models\ProductExternalContent;
use App\Services\PriceResearch\FxRateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ProductExportService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly FxRateService $fxRates,
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
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listSelectedRestockForExport(array $uuids): Collection
    {
        return $this->products->listRestockByUuidsForExport($uuids);
    }

    /**
     * @return array<int, string>
     */
    public function restockPoHeader(string $currencyCode): array
    {
        $currency = strtoupper(trim($currencyCode));
        if ($currency === '') {
            $currency = 'CAD';
        }

        return [
            'Product SKU',
            'Product Name',
            'Barcode',
            'Vendor',
            'Reorder Qty',
            "Unit Cost ({$currency})",
            "Total Cost ({$currency})",
        ];
    }

    public function cadToHkdRate(): float
    {
        return $this->fxRates->rate('CAD', 'HKD');
    }

    /**
     * @return array<int, string>
     */
    public function restockPoRow(Product $product, string $currencyCode, ?float $cadToHkdRate = null): array
    {
        $currency = strtoupper(trim($currencyCode));
        if ($currency === '') {
            $currency = 'CAD';
        }

        $reorderQty = max(0, (int) ($product->getAttribute('reorder_qty') ?? 0));
        $unitCostCad = $this->toFloatOrNull($product->latest_unit_cost);
        $unitCost = $unitCostCad;
        if ($unitCostCad !== null && $currency === 'HKD') {
            $rate = $cadToHkdRate ?? $this->cadToHkdRate();
            $unitCost = $unitCostCad * $rate;
        }

        $totalCost = $unitCost !== null ? $unitCost * $reorderQty : null;

        return [
            (string) $product->sku,
            (string) $product->description,
            (string) ($product->barcode ?? ''),
            (string) ($product->vendor ?? ''),
            (string) $reorderQty,
            $unitCost !== null ? number_format($unitCost, 2, '.', '') : '',
            $totalCost !== null ? number_format($totalCost, 2, '.', '') : '',
        ];
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
            'Published Scope',
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

    /**
     * Shopify content export variant that does NOT include inventory columns,
     * so importing the CSV will not overwrite Shopify quantities.
     *
     * @return array<int, string>
     */
    public function shopifyHeaderNoInventory(): array
    {
        $omit = [
            'Variant Inventory Tracker',
            'Variant Inventory Qty',
            'Variant Inventory Policy',
        ];

        return array_values(array_filter(
            $this->shopifyHeader(),
            static fn (string $col): bool => ! in_array($col, $omit, true),
        ));
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
        $availableQty = $product->available_qty ?? 0;
        $selling = $product->sellingPrice?->selling_price;
        $type = $product->type !== null ? trim((string) $product->type) : '';
        $tags = $this->shopifyTagsForProduct($product, $type);
        $isArchived = $product->archived_at !== null;
        $shouldPublish = ! $isArchived && (bool) $product->published_on_shopify;
        $status = $isArchived ? 'archived' : ($shouldPublish ? 'active' : 'draft');

        return [
            $handle, // Handle
            (string) $product->description, // Title
            $this->resolveBodyHtml($product), // Body (HTML)
            '', // Vendor
            '', // Product Category
            $type, // Type
            $tags, // Tags
            $shouldPublish ? 'TRUE' : 'FALSE', // Published
            'global', // Published Scope (all channels: Online Store + POS + Shop, etc)
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
            (string) max(0, (int) $availableQty), // Variant Inventory Qty
            'deny', // Variant Inventory Policy (stop selling when out of stock)
            'manual', // Variant Fulfillment Service
            (string) ($selling ?? ''), // Variant Price
            '', // Variant Compare At Price
            'TRUE', // Variant Requires Shipping
            'TRUE', // Variant Taxable
            '', // Unit Price Total Measure
            '', // Unit Price Total Measure Unit
            '', // Unit Price Base Measure
            '', // Unit Price Base Measure Unit
            (string) ($product->barcode ?? ''), // Variant Barcode
            '', // Image Src
            '', // Image Position
            '', // Image Alt Text
            'FALSE', // Gift Card
            '', // SEO Title
            '', // SEO Description
            '', // Variant Image
            'kg', // Variant Weight Unit
            '', // Variant Tax Code
            '', // Cost per item (intentionally omitted)
            $status, // Status
        ];
    }

    /**
     * @return array<int, string>
     */
    public function shopifyRowNoInventory(Product $product, string $handle): array
    {
        $fullHeader = $this->shopifyHeader();
        $fullRow = $this->shopifyRow($product, $handle);
        $map = array_combine($fullHeader, $fullRow);
        if (! is_array($map)) {
            $map = [];
        }

        $header = $this->shopifyHeaderNoInventory();

        return array_map(
            static fn (string $col): string => (string) ($map[$col] ?? ''),
            $header,
        );
    }

    private function shopifyTagsForProduct(Product $product, string $type): string
    {
        $mainType = trim((string) $product->main_type);
        if ($mainType === '') {
            // Explicitly treat empty main_type as "no tags at all" for Shopify exports.
            return '';
        }

        $tags = [$mainType];

        if ($type !== '') {
            $tags[] = $type;
        }
        if ($product->latest_arrival) {
            $tags[] = 'latest arrival';
        }

        $out = [];
        $seen = [];
        foreach ($tags as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $key = strtolower($t);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $t;
        }

        return implode(', ', $out);
    }

    private function resolveBodyHtml(Product $product): string
    {
        $preferred = is_string($product->preferred_description_source) ? trim($product->preferred_description_source) : '';
        if ($preferred !== '') {
            if ($preferred === 'hlj') {
                $hlj = $product->hljExternalContent?->description_html;
                if (is_string($hlj) && trim($hlj) !== '') {
                    return $this->normalizeBodyHtmlForShopify($hlj);
                }
            }
            if ($preferred === 'plamod') {
                $plamod = $product->plamodExternalContent?->description_html;
                if (is_string($plamod) && trim($plamod) !== '') {
                    return $this->normalizeBodyHtmlForShopify($plamod);
                }
            }

            /** @var array<int, ProductExternalContent> $contents */
            $contents = $product->externalContents?->all() ?? [];
            foreach ($contents as $c) {
                if (! $c instanceof ProductExternalContent) {
                    continue;
                }
                if ($c->source !== $preferred) {
                    continue;
                }
                if (! is_string($c->description_html) || trim($c->description_html) === '') {
                    continue;
                }

                return $this->normalizeBodyHtmlForShopify((string) $c->description_html);
            }
        }

        $hlj = $product->hljExternalContent?->description_html;
        if (is_string($hlj) && trim($hlj) !== '') {
            return $this->normalizeBodyHtmlForShopify($hlj);
        }

        /** @var array<int, ProductExternalContent> $contents */
        $contents = $product->externalContents?->all() ?? [];
        $best = null;
        foreach ($contents as $c) {
            if (! $c instanceof ProductExternalContent) {
                continue;
            }
            if (in_array($c->source, ['hlj', 'plamod'], true)) {
                continue;
            }
            if (! is_string($c->description_html) || trim($c->description_html) === '') {
                continue;
            }
            if ($best === null) {
                $best = $c;

                continue;
            }
            $bestAt = $best->updated_at?->getTimestamp() ?? 0;
            $cAt = $c->updated_at?->getTimestamp() ?? 0;
            if ($cAt >= $bestAt) {
                $best = $c;
            }
        }
        if ($best !== null && is_string($best->description_html) && trim($best->description_html) !== '') {
            return $this->normalizeBodyHtmlForShopify((string) $best->description_html);
        }

        $plamod = $product->plamodExternalContent?->description_html;
        if (is_string($plamod) && trim($plamod) !== '') {
            return $this->normalizeBodyHtmlForShopify($plamod);
        }

        $fallback = trim((string) $product->description);
        if ($fallback === '') {
            return '';
        }

        return '<p>'.e($fallback).'</p>';
    }

    private function normalizeBodyHtmlForShopify(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<\s*br\s*/?\s*>#i', '', $html) ?? $html;
        $html = str_replace('Â ', ' ', $html);
        $html = preg_replace('/[ \t\r\n]+/', ' ', $html) ?? $html;

        return trim($html);
    }

    private function toFloatOrNull(string|int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return (float) $trimmed;
    }
}
