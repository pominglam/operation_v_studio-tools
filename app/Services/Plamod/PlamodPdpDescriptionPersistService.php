<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Products\PlamodAssetSyncService;

final class PlamodPdpDescriptionPersistService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExternalContentRepository $contents,
    ) {}

    public function persistForSku(string $sku, string $descriptionHtml, ?string $title = null): bool
    {
        $sku = trim($sku);
        $html = trim($descriptionHtml);
        if ($sku === '' || $html === '') {
            return false;
        }

        $product = $this->products->findBySkus([$sku])->first();
        if (! $product instanceof Product) {
            return false;
        }

        $existing = $this->contents->findForProduct((int) $product->id, PlamodAssetSyncService::SOURCE);
        $name = trim((string) $title);
        $this->contents->upsertForProduct(
            productId: (int) $product->id,
            source: PlamodAssetSyncService::SOURCE,
            title: $name !== '' ? $name : ($existing?->title ?? null),
            descriptionHtml: $html,
            attributes: is_array($existing?->attributes_json) ? $existing->attributes_json : null,
            sourceUrl: 'https://plamod.com/retailer/products/'.rawurlencode((string) $product->sku),
        );

        $preferred = is_string($product->preferred_description_source)
            ? trim($product->preferred_description_source)
            : '';
        if ($preferred !== 'other') {
            $product->preferred_description_source = PlamodAssetSyncService::SOURCE;
            $this->products->save($product);
        }

        return true;
    }
}
