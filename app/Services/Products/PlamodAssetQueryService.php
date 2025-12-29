<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Products\PlamodProductData;
use App\Services\Products\Hlj\HljPdpResolverService;

final class PlamodAssetQueryService
{
    public const string SOURCE = PlamodAssetSyncService::SOURCE;
    private const string HLJ_SOURCE = 'hlj';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
        private readonly HljPdpResolverService $hljPdpResolver,
    ) {}

    public function getByProductUuid(string $productUuid): PlamodProductData
    {
        $product = $this->products->findByUuidOrFail($productUuid);

        // Prefer HLJ description content when available (often more complete),
        // but keep Plamod as fallback.
        $content = $this->contents->findForProduct($product->id, self::HLJ_SOURCE)
            ?? $this->contents->findForProduct($product->id, self::SOURCE);

        // Backfill HLJ PDP URL for older syncs so the UI can link to the true PDP (not the search page).
        if ($content !== null && $content->source === self::HLJ_SOURCE && ($content->source_url === null || trim((string) $content->source_url) === '')) {
            $pdpUrl = $this->hljPdpResolver->resolvePdpUrlForProduct($product);
            if ($pdpUrl !== null && ! str_contains($pdpUrl, '/search/?')) {
                $this->contents->updateSourceUrl((int) $content->id, $pdpUrl);
                $content->source_url = $pdpUrl;
            }
        }
        $assets = $this->assets->listForProduct($product->id, self::SOURCE);

        return new PlamodProductData($content, $assets);
    }
}


