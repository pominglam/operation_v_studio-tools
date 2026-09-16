<?php

declare(strict_types=1);

namespace App\Services\StorePreorders\Listing;

use App\DTOs\StorePreorders\StorePreorderListingPreview;
use App\Services\StorePreorders\Exceptions\StorePreorderListingCrawlException;
use App\Support\StorePreorders\StorePreorderManualSku;
use Illuminate\Support\Facades\Http;

final class StorePreorderListingPreviewService
{
    public function __construct(
        private readonly StorePreorderListingCrawlerRegistry $crawlers,
        private readonly StorePreorderListingPhotoStagingService $photos,
    ) {}

    public function preview(string $url): StorePreorderListingPreview
    {
        $url = trim($url);
        $host = StorePreorderListingCrawlerRegistry::hostFromUrl($url);
        if ($host === '') {
            throw StorePreorderListingCrawlException::failed('Enter a valid product URL.');
        }

        $crawler = $this->crawlers->forUrl($url);
        if ($crawler === null) {
            throw StorePreorderListingCrawlException::noCrawler($host);
        }

        $crawl = $crawler->crawl($url);
        $images = $this->stageImages($crawl->imageUrls);
        $price = $crawl->retailPriceCad;

        return new StorePreorderListingPreview(
            host: $host,
            crawler: 'shopify',
            title: $crawl->title,
            suggestedSku: StorePreorderManualSku::fromName($crawl->title),
            descriptionHtml: $crawl->descriptionHtml,
            etaDate: $crawl->etaDate,
            retailPriceCad: $price,
            retailPriceNote: $price !== null ? $host.' $'.$price : null,
            images: $images,
            sourceUrl: $crawl->sourceUrl,
        );
    }

    /**
     * @param  list<string>  $urls
     * @return list<array{id: string, preview_url: string}>
     */
    private function stageImages(array $urls): array
    {
        $staged = [];
        foreach (array_slice($urls, 0, 12) as $url) {
            $image = $this->download($url);
            if ($image === null) {
                continue;
            }
            $staged[] = $this->photos->storeBytes($image['bytes'], $image['mime'], $image['filename']);
        }

        return $staged;
    }

    /**
     * @return array{bytes: string, mime: string, filename: string}|null
     */
    private function download(string $url): ?array
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $bytes = $response->body();
        if (strlen($bytes) < 80 || strlen($bytes) > 8_000_000) {
            return null;
        }
        $mime = $response->header('Content-Type') ?? 'image/jpeg';
        $mime = strtolower(trim(explode(';', $mime)[0] ?? 'image/jpeg'));
        if (! str_starts_with($mime, 'image/')) {
            $mime = 'image/jpeg';
        }

        return [
            'bytes' => $bytes,
            'mime' => $mime,
            'filename' => basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image.jpg',
        ];
    }
}
