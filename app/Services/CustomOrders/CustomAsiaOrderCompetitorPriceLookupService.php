<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use App\Services\PriceResearch\Providers\CompetitorPriceProvider;
use App\Support\CustomOrders\CustomAsiaOrderCompetitorPriceSites;
use Illuminate\Support\Facades\Process;
use JsonException;
use Throwable;

final class CustomAsiaOrderCompetitorPriceLookupService
{
    /**
     * @param  iterable<CompetitorPriceProvider>  $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @param  array<int, string>|null  $siteKeys
     * @return array<int, array<string, mixed>>
     */
    public function lookupByProductName(string $productName, ?array $siteKeys = null): array
    {
        $productName = trim($productName);
        if ($productName === '') {
            return [];
        }

        $keys = $siteKeys ?? CustomAsiaOrderCompetitorPriceSites::FAST_SITE_KEYS;

        return $this->lookupParallelByProductName($productName, $keys);
    }

    /**
     * @param  array<int, string>  $siteKeys
     * @return array<int, array<string, mixed>>
     */
    public function lookupParallelByProductName(string $productName, array $siteKeys): array
    {
        $productName = trim($productName);
        if ($productName === '' || $siteKeys === []) {
            return [];
        }

        try {
            return $this->lookupViaSiteWorkerProcesses($productName, $siteKeys);
        } catch (Throwable) {
            return $this->lookupSequentialByProductName($productName, $siteKeys);
        }
    }

    /**
     * @param  array<int, string>  $siteKeys
     * @return array<int, array<string, mixed>>
     */
    private function lookupViaSiteWorkerProcesses(string $productName, array $siteKeys): array
    {
        $phpBinary = (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '')
            ? PHP_BINARY
            : 'php';
        $artisan = base_path('artisan');

        /** @var array<string, \Illuminate\Process\InvokedProcess> $running */
        $running = [];

        foreach ($siteKeys as $siteKey) {
            $running[$siteKey] = Process::path(base_path())
                ->env([
                    'COMPETITOR_LOOKUP_PRODUCT_NAME' => $productName,
                ])
                ->timeout(180)
                ->start([
                    $phpBinary,
                    $artisan,
                    'custom-asia-orders:competitor-price-site',
                    $siteKey,
                ]);
        }

        $quotes = [];
        foreach ($siteKeys as $siteKey) {
            $quotes[] = $this->quoteFromSiteWorkerProcess($siteKey, $running[$siteKey]->wait());
        }

        return $quotes;
    }

    /** @return array<string, mixed> */
    private function quoteFromSiteWorkerProcess(string $siteKey, \Illuminate\Process\ProcessResult $result): array
    {
        if (! $result->successful()) {
            $message = trim($result->errorOutput());
            if ($message === '') {
                $message = trim($result->output());
            }

            return $this->missingSiteQuote($siteKey, $message !== '' ? $message : 'Lookup failed.');
        }

        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode(trim($result->output()), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                return $this->missingSiteQuote($siteKey, 'Lookup returned invalid JSON.');
            }

            return $decoded;
        } catch (JsonException $e) {
            return $this->missingSiteQuote($siteKey, 'Lookup returned invalid JSON: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function lookupSite(string $productName, string $siteKey): array
    {
        return $this->lookupSingleSiteByProductName($productName, $siteKey);
    }

    /** @return array<string, mixed> */
    public function lookupSingleSiteByProductName(string $productName, string $siteKey): array
    {
        $productName = trim($productName);
        if ($productName === '') {
            return $this->missingSiteQuote($siteKey, 'Product name is required.');
        }

        $provider = $this->providerForSiteKey($siteKey);
        if ($provider === null) {
            return $this->missingSiteQuote($siteKey, 'Unknown retailer.');
        }

        $product = new Product;
        $product->description = $productName;

        return $this->quoteToArray($provider->lookup($product));
    }

    /**
     * @param  array<int, string>  $siteKeys
     * @return array<int, array<string, mixed>>
     */
    private function lookupSequentialByProductName(string $productName, array $siteKeys): array
    {
        $quotes = [];
        foreach ($siteKeys as $siteKey) {
            $quotes[] = $this->lookupSingleSiteByProductName($productName, $siteKey);
        }

        return $quotes;
    }

    private function providerForSiteKey(string $siteKey): ?CompetitorPriceProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->siteKey() === $siteKey) {
                return $provider;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function missingSiteQuote(string $siteKey, string $message): array
    {
        return [
            'site_key' => $siteKey,
            'site_name' => CustomAsiaOrderCompetitorPriceSites::siteLabel($siteKey),
            'site_url' => CustomAsiaOrderCompetitorPriceSites::siteUrl($siteKey),
            'status' => 'error',
            'availability' => null,
            'currency' => 'CAD',
            'price' => null,
            'original_price' => null,
            'product_url' => null,
            'error_message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function quoteToArray(PriceLookupResult $result): array
    {
        return [
            'site_key' => $result->siteKey,
            'site_name' => $result->siteName,
            'site_url' => CustomAsiaOrderCompetitorPriceSites::siteUrl($result->siteKey),
            'status' => $result->status,
            'availability' => $result->availability,
            'currency' => $result->currency,
            'price' => $result->price !== null ? number_format($result->price, 2, '.', '') : null,
            'original_price' => $result->originalPrice !== null
                ? number_format($result->originalPrice, 2, '.', '')
                : null,
            'product_url' => $result->productUrl,
            'error_message' => $result->errorMessage,
        ];
    }
}
