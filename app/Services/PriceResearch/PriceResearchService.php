<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\DAL\PriceResearch\PriceResearchRunLogRepository;
use App\DAL\PriceResearch\PriceResearchRunRepository;
use App\DAL\PriceResearch\ProductLookupRepository;
use App\DAL\PriceResearch\ProductPriceQuoteRepository;
use App\Models\PriceResearchRun;
use App\Models\Product;
use App\Models\ProductPriceQuote;
use App\Services\PriceResearch\Providers\CompetitorPriceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PriceResearchService
{
    /**
     * @param  iterable<CompetitorPriceProvider>  $providers
     */
    public function __construct(
        private readonly ProductLookupRepository $products,
        private readonly ProductPriceQuoteRepository $quotes,
        private readonly PriceResearchRunRepository $runs,
        private readonly PriceResearchRunLogRepository $runLogs,
        private readonly iterable $providers,
    ) {}

    public function ttlDays(): int
    {
        return max(1, (int) config('price_research.ttl_days', 14));
    }

    public function isExpired(?CarbonImmutable $at): bool
    {
        if ($at === null) {
            return true;
        }

        return $at->lt(CarbonImmutable::now()->subDays($this->ttlDays()));
    }

    /**
     * @return array{
     *   processed: int,
     *   refreshed: int,
     *   skipped_fresh: int,
     *   quotes_written: int
     * }
     */
    public function run(?array $productUuids = null, bool $force = false, ?string $runUuid = null, ?array $siteKeys = null): array
    {
        $processed = 0;
        $refreshed = 0;
        $skippedFresh = 0;
        $quotesWritten = 0;

        $providersToUse = $this->filterProvidersBySiteKeys($siteKeys);
        // Only touch product.price_researched_at when doing a full run across all sites; otherwise we can
        // accidentally mark products "fresh" even though other site quotes may be old.
        $touchProductTimestamp = $siteKeys === null;

        $targets = $productUuids === null
            ? $this->products->cursorAll()
            : $this->products->findByUuids($productUuids);

        $run = null;
        if ($runUuid !== null) {
            try {
                $run = $this->runs->findByUuidOrFail($runUuid);
            } catch (ModelNotFoundException) {
                // The run may have been removed (e.g., local DB restore). Still run price research,
                // but skip run tracking so the queued job doesn't fail.
                $run = null;
            }
        }
        if ($run !== null) {
            $run->status = 'running';
            $run->started_at = now();
            $run->processed_products = 0;
            $run->processed_sites = 0;
            $run->quotes_written = 0;
            $run->refreshed_products = 0;
            $run->skipped_fresh_products = 0;
            $this->runs->save($run);
        }

        try {
            foreach ($targets as $product) {
                $processed++;

                /** @var CarbonImmutable|null $last */
                $last = $product->price_researched_at?->toImmutable();
                if (! $force && ! $this->isExpired($last)) {
                    $skippedFresh++;
                    if ($run !== null) {
                        $run->processed_products = $processed;
                        $run->skipped_fresh_products = $skippedFresh;
                        $this->runs->save($run);
                    }

                    continue;
                }

                $written = $this->refreshProduct($product, $run, $providersToUse, $touchProductTimestamp);
                $quotesWritten += $written;
                $refreshed++;

                if ($run !== null) {
                    $run->processed_products = $processed;
                    $run->refreshed_products = $refreshed;
                    $run->skipped_fresh_products = $skippedFresh;
                    $run->quotes_written = $quotesWritten;
                    $this->runs->save($run);
                }
            }
        } catch (Throwable $e) {
            if ($run !== null) {
                $run->status = 'failed';
                $run->error_message = $e->getMessage();
                $run->finished_at = now();
                $run->processed_products = $processed;
                $run->refreshed_products = $refreshed;
                $run->skipped_fresh_products = $skippedFresh;
                $run->quotes_written = $quotesWritten;
                $this->runs->save($run);
            }

            throw $e;
        }

        if ($run !== null) {
            $run->status = 'completed';
            $run->finished_at = now();
            $run->processed_products = $processed;
            $run->refreshed_products = $refreshed;
            $run->skipped_fresh_products = $skippedFresh;
            $run->quotes_written = $quotesWritten;
            $this->runs->save($run);
        }

        return [
            'processed' => $processed,
            'refreshed' => $refreshed,
            'skipped_fresh' => $skippedFresh,
            'quotes_written' => $quotesWritten,
        ];
    }

    /**
     * @param  iterable<CompetitorPriceProvider>  $providers
     */
    private function refreshProduct(Product $product, ?PriceResearchRun $run, iterable $providers, bool $touchProductTimestamp): int
    {
        $now = now();
        $written = 0;

        foreach ($providers as $provider) {
            /** @var CompetitorPriceProvider $provider */
            $log = null;
            if ($run !== null) {
                $log = $this->runLogs->start($run, $product, $provider->siteKey(), $provider->siteName());
            }

            $t0 = microtime(true);
            try {
                $result = $provider->lookup($product);
            } catch (Throwable $e) {
                if ($log !== null) {
                    $durationMs = (int) round((microtime(true) - $t0) * 1000);
                    $this->runLogs->finish($log, 'exception', null, $e->getMessage(), $durationMs);
                }

                throw $e;
            }

            $durationMs = (int) round((microtime(true) - $t0) * 1000);

            DB::transaction(function () use ($product, $now, &$written, $run, $log, $result, $durationMs): void {
                $this->quotes->upsertForProduct($product, [
                    'site_key' => $result->siteKey,
                    'site_name' => $result->siteName,
                    'status' => $result->status,
                    'availability' => $result->availability,
                    'currency' => $result->currency,
                    'price' => $result->price,
                    'original_price' => $result->originalPrice,
                    'product_url' => $result->productUrl,
                    'error_message' => $result->errorMessage,
                    'fetched_at' => $now,
                ]);

                $written++;

                if ($run !== null) {
                    $run->processed_sites = $run->processed_sites + 1;
                    $run->quotes_written = $run->quotes_written + 1;
                    $this->runs->save($run);
                }

                if ($log !== null) {
                    $this->runLogs->finish(
                        $log,
                        $result->status,
                        $result->productUrl,
                        $result->errorMessage,
                        $durationMs,
                    );
                }
            });
        }

        if ($touchProductTimestamp) {
            $product->price_researched_at = $now;
            $product->save();
        }

        return $written;
    }

    /**
     * @return array<int, CompetitorPriceProvider>
     */
    private function filterProvidersBySiteKeys(?array $siteKeys): array
    {
        $out = [];

        /** @var array<int, string> $disabled */
        $disabled = (array) config('price_research.disabled_site_keys', []);
        $disabled = array_values(array_unique(array_filter(array_map('trim', $disabled), static fn (string $v): bool => $v !== '')));
        $disabledMap = array_fill_keys($disabled, true);

        if ($siteKeys === null) {
            foreach ($this->providers as $p) {
                /** @var CompetitorPriceProvider $p */
                if (isset($disabledMap[$p->siteKey()])) {
                    continue;
                }
                $out[] = $p;
            }

            return $out;
        }

        $wanted = array_fill_keys($siteKeys, true);
        foreach ($this->providers as $p) {
            /** @var CompetitorPriceProvider $p */
            if (isset($wanted[$p->siteKey()]) && ! isset($disabledMap[$p->siteKey()])) {
                $out[] = $p;
            }
        }

        return $out;
    }

    public function providerCountForSiteKeys(?array $siteKeys): int
    {
        return count($this->filterProvidersBySiteKeys($siteKeys));
    }

    /**
     * @return Collection<int, ProductPriceQuote>
     */
    public function latestQuotesForProduct(Product $product): Collection
    {
        return $this->quotes->listLatestForProduct($product);
    }
}
