<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Products\ProductRepository;
use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\DTOs\StorePreorders\StorePreorderPlamodDescriptionSyncResult;
use App\Models\Product;
use App\Services\Plamod\PlamodPdpDescriptionPersistService;
use App\Services\Products\Http\PlamodScraper;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;

final class StorePreorderPlamodDescriptionSyncService
{
    private const int CHUNK_SIZE = 8;

    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly ProductRepository $products,
        private readonly PlamodScraper $scraper,
        private readonly PlamodPdpDescriptionPersistService $persist,
        private readonly ShopifyProductPushBySkusService $shopifyPush,
    ) {}

    public function syncAll(bool $pushShopify = false): StorePreorderPlamodDescriptionSyncResult
    {
        return $this->syncSkus($this->plamodOfferSkus(), $pushShopify);
    }

    /**
     * @param  list<string>  $productUuids
     */
    public function syncProductUuids(array $productUuids, bool $pushShopify = false): StorePreorderPlamodDescriptionSyncResult
    {
        $products = $this->products->findByUuids($productUuids);
        $skus = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $sku = trim((string) $product->sku);
            if ($sku === '' || $this->isManualSku($sku)) {
                continue;
            }
            $skus[] = $sku;
        }

        return $this->syncSkus($skus, $pushShopify);
    }

    /**
     * @param  list<string>  $skus
     */
    public function syncSkus(array $skus, bool $pushShopify = false): StorePreorderPlamodDescriptionSyncResult
    {
        $skus = $this->normalizeSkus($skus);
        $updated = [];
        $failed = [];
        foreach (array_chunk($skus, self::CHUNK_SIZE) as $chunk) {
            $chunkResult = $this->applyChunk($chunk);
            $updated = [...$updated, ...$chunkResult['updated']];
            $failed = [...$failed, ...$chunkResult['failed']];
        }

        $pushed = [];
        if ($pushShopify && $updated !== []) {
            $pushed = $this->pushInfo($updated);
        }

        return new StorePreorderPlamodDescriptionSyncResult(
            attempted: count($skus),
            updatedSkus: $updated,
            failedSkus: $failed,
            pushedSkus: $pushed,
        );
    }

    /**
     * @return list<string>
     */
    private function plamodOfferSkus(): array
    {
        return $this->normalizeSkus($this->offers->plamodSkus());
    }

    /**
     * @param  list<string>  $skus
     * @return array{updated: list<string>, failed: list<string>}
     */
    private function applyChunk(array $skus): array
    {
        $result = $this->scraper->enrichPreorderPdpFields($skus);
        $rows = is_array($result['results'] ?? null) ? $result['results'] : [];
        $updated = [];
        $failed = [];
        foreach ($skus as $sku) {
            $fields = $rows[$sku] ?? null;
            $html = is_array($fields) ? trim((string) ($fields['description_html'] ?? '')) : '';
            if ($html === '' || ($result['ok'] ?? false) !== true) {
                $failed[] = $sku;

                continue;
            }
            $title = is_array($fields) ? trim((string) ($fields['product_name'] ?? '')) : '';
            if ($this->persist->persistForSku($sku, $html, $title !== '' ? $title : null)) {
                $updated[] = $sku;

                continue;
            }
            $failed[] = $sku;
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * @param  list<string>  $skus
     * @return list<string>
     */
    private function pushInfo(array $skus): array
    {
        $rows = $this->shopifyPush->push($skus, new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: false,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ));

        $pushed = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $action = trim((string) ($row['action'] ?? ''));
            if ($sku !== '' && $action !== 'error') {
                $pushed[] = $sku;
            }
        }

        return $pushed;
    }

    /**
     * @param  list<string>  $skus
     * @return list<string>
     */
    private function normalizeSkus(array $skus): array
    {
        $out = [];
        foreach ($skus as $sku) {
            $sku = trim($sku);
            if ($sku === '' || $this->isManualSku($sku)) {
                continue;
            }
            $out[$sku] = $sku;
        }

        return array_values($out);
    }

    private function isManualSku(string $sku): bool
    {
        return str_starts_with(strtoupper($sku), 'OVS-');
    }
}
