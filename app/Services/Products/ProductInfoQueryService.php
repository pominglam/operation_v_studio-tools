<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductInfoData;

final class ProductInfoQueryService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
    ) {}

    public function getByProductUuid(string $productUuid): ProductInfoData
    {
        $product = $this->products->findByUuidOrFail($productUuid);

        $allContents = $this->contents->listForProduct((int) $product->id);

        $preferred = is_string($product->preferred_description_source) ? trim((string) $product->preferred_description_source) : '';
        if ($preferred === '' && $this->hasNonEmptyDescriptionForSource($allContents, 'hlj')) {
            $product->preferred_description_source = 'hlj';
            $this->products->save($product);
            $preferred = 'hlj';
        }

        // Filter out "empty" source rows (e.g. after a recrawl could not resolve a PDP and we cleared the record),
        // so the frontend doesn't treat them as selectable sources.
        $contents = array_values(array_filter($allContents, static function ($c): bool {
            if (! $c instanceof \App\Models\ProductExternalContent) {
                return false;
            }

            $url = is_string($c->source_url) ? trim($c->source_url) : '';
            if ($url !== '') {
                return true;
            }

            $title = is_string($c->title) ? trim($c->title) : '';
            if ($title !== '') {
                return true;
            }

            $desc = is_string($c->description_html) ? trim($c->description_html) : '';
            if ($desc !== '') {
                return true;
            }

            return is_array($c->attributes_json) && $c->attributes_json !== [];
        }));
        $assets = $this->assets->listAllForProduct((int) $product->id);

        return new ProductInfoData(
            contents: $contents,
            assets: $assets,
            preferredDescriptionSource: $preferred !== '' ? $preferred : null,
        );
    }

    /**
     * @param  array<int, mixed>  $contents
     */
    private function hasNonEmptyDescriptionForSource(array $contents, string $source): bool
    {
        $source = strtolower(trim($source));
        if ($source === '') {
            return false;
        }

        foreach ($contents as $c) {
            if (! $c instanceof \App\Models\ProductExternalContent) {
                continue;
            }
            if (strtolower((string) $c->source) !== $source) {
                continue;
            }
            if (is_string($c->description_html) && trim($c->description_html) !== '') {
                return true;
            }
        }

        return false;
    }
}

