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

        $contents = $this->contents->listForProduct((int) $product->id);
        // Filter out "empty" source rows (e.g. after a recrawl could not resolve a PDP and we cleared the record),
        // so the frontend doesn't treat them as selectable sources.
        $contents = array_values(array_filter($contents, static function ($c): bool {
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

        return new ProductInfoData($contents, $assets);
    }
}

