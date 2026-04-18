<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductsRecrawlSelectedResultDTO;
use App\Jobs\RecrawlSelectedProductJob;
use App\Services\Products\GundamHangar\GundamHangarContentSyncService;
use App\Services\Products\Newtype\NewtypeHtmlParser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class ProductsRecrawlSelectedService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly JobBatchItemRepository $batchItems,
        private readonly ProductPdpSearchTermsService $terms,
    ) {}

    /**
     * @param  array<int, string>  $productUuids
     * @param  array<int, string>  $sources
     */
    public function recrawlSelected(array $productUuids, array $sources): ProductsRecrawlSelectedResultDTO
    {
        $productUuids = array_values(array_unique(array_filter(array_map('strval', $productUuids), static fn (string $v): bool => trim($v) !== '')));
        $sources = array_values(array_unique(array_filter(array_map('strval', $sources), static fn (string $v): bool => trim($v) !== '')));

        if ($productUuids === [] || $sources === []) {
            return new ProductsRecrawlSelectedResultDTO(0, '');
        }

        $jobs = [];
        $itemProducts = [];
        $sourcesLine = '[job] sources='.implode(',', $sources);
        $wantGundamPlanet = in_array('gundamplanet', $sources, true);
        $wantNewtype = in_array('newtype', $sources, true);
        $wantGundamHangar = in_array('gundamhangar', $sources, true);

        $existing = $this->products->findByUuids($productUuids)->keyBy('uuid');
        foreach ($productUuids as $uuid) {
            $p = $existing->get($uuid);
            if ($p === null) {
                continue;
            }

            $jobs[] = new RecrawlSelectedProductJob((string) Str::uuid(), (string) $uuid, $sources);

            $debug = $sourcesLine;
            if ($wantGundamPlanet) {
                $debug = $this->appendGundamPlanetPlan($debug, $p);
            }
            if ($wantNewtype) {
                $debug = $this->appendNewtypePlan($debug, $p);
            }
            if ($wantGundamHangar) {
                $debug = $this->appendGundamHangarPlan($debug, $p);
            }

            $itemProducts[] = [
                'product_uuid' => (string) $uuid,
                'sku' => is_string($p->sku ?? null) ? (string) $p->sku : null,
                'vendor' => is_string($p->vendor ?? null) ? (string) $p->vendor : null,
                // Ensure the UI can show selected sources immediately (even if a worker hasn't picked up newer code yet).
                'debug_log' => $debug,
            ];
        }

        if ($jobs === []) {
            return new ProductsRecrawlSelectedResultDTO(0, '');
        }

        $batch = Bus::batch($jobs)
            ->name('recrawl_selected_products')
            ->onQueue('pdp_sync')
            ->allowFailures()
            ->dispatch();

        $this->batchItems->insertQueued($batch->id, $itemProducts);

        return new ProductsRecrawlSelectedResultDTO(
            queued: count($itemProducts),
            batchId: $batch->id,
        );
    }

    private function appendGundamPlanetPlan(string $debug, object $product): string
    {
        $lines = [$debug];

        $terms = $this->terms->termsForProduct($product);

        $lines[] = '[gundamplanet][plan] terms_count='.count($terms);

        $limit = 10;
        foreach (array_slice($terms, 0, $limit) as $t) {
            $q = trim((string) $t);
            if ($q === '') {
                continue;
            }
            $url = $this->gundamPlanetSearchUrl($q);
            $lines[] = "[gundamplanet][plan] q={$q} url={$url}";
        }
        if (count($terms) > $limit) {
            $lines[] = '[gundamplanet][plan] (truncated)';
        }

        return implode("\n", $lines);
    }

    private function appendNewtypePlan(string $debug, object $product): string
    {
        $lines = [$debug];

        $terms = $this->terms->termsForProduct($product);
        $lines[] = '[newtype][plan] terms_count='.count($terms);

        $limit = 10;
        foreach (array_slice($terms, 0, $limit) as $t) {
            $q = trim((string) $t);
            if ($q === '') {
                continue;
            }
            $url = $this->newtypeSearchUrl($q);
            $lines[] = "[newtype][plan] q={$q} url={$url}";
        }
        if (count($terms) > $limit) {
            $lines[] = '[newtype][plan] (truncated)';
        }

        return implode("\n", $lines);
    }

    private function gundamPlanetSearchUrl(string $query): string
    {
        $q = rawurlencode(trim($query));

        return 'https://www.gundamplanet.com/search?q='.$q.'&options%5Bprefix%5D=last';
    }

    private function newtypeSearchUrl(string $query): string
    {
        $qs = http_build_query(['q' => trim($query)]);

        return NewtypeHtmlParser::BASE_URL.'/search?'.$qs;
    }

    private function appendGundamHangarPlan(string $debug, object $product): string
    {
        $lines = [$debug];
        $terms = $this->terms->termsForProduct($product);
        $lines[] = '[gundamhangar][plan] terms_count='.count($terms);

        $limit = 10;
        foreach (array_slice($terms, 0, $limit) as $t) {
            $q = trim((string) $t);
            if ($q === '') {
                continue;
            }
            $url = $this->gundamHangarSearchUrl($q);
            $lines[] = "[gundamhangar][plan] q={$q} url={$url}";
        }
        if (count($terms) > $limit) {
            $lines[] = '[gundamhangar][plan] (truncated)';
        }

        return implode("\n", $lines);
    }

    private function gundamHangarSearchUrl(string $query): string
    {
        $qs = http_build_query([
            'search' => trim($query),
            'page' => 1,
            'outofstock' => '',
            'limit' => 10,
        ]);

        return GundamHangarContentSyncService::API_BASE_URL.'/products?'.$qs;
    }
}
