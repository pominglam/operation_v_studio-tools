<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\Models\Product;

final class ProductPreferredDescriptionSourceService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExternalContentRepository $contents,
    ) {}

    public function setForProduct(string $productUuid, ?string $source, ?string $manualDescriptionHtml = null): Product
    {
        $product = $this->products->findByUuidOrFail($productUuid);
        $source = is_string($source) ? trim($source) : null;
        $source = $source !== '' ? $source : null;

        if ($source === 'other' && $manualDescriptionHtml !== null) {
            $existing = $this->contents->findForProduct((int) $product->id, 'other');
            $this->contents->upsertForProduct(
                productId: (int) $product->id,
                source: 'other',
                title: $existing?->title,
                descriptionHtml: $manualDescriptionHtml,
                attributes: is_array($existing?->attributes_json) ? $existing->attributes_json : null,
                sourceUrl: $existing?->source_url,
            );
        }

        $product->preferred_description_source = $source;
        $this->products->save($product);

        return $product;
    }
}
