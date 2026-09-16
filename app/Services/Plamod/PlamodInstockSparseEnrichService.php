<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodInstockItem;
use App\Services\Products\Http\PlamodScraper;
use Carbon\Carbon;

final class PlamodInstockSparseEnrichService
{
    private const int CHUNK_SIZE = 10;

    public function __construct(
        private readonly PlamodScraper $scraper,
    ) {}

    /**
     * @return array{attempted: int, enriched: int, failed: int}
     */
    public function enrichMissingListingFields(int $maxSkus = 80): array
    {
        $skus = PlamodInstockItem::query()
            ->where(function ($query): void {
                $query->whereNull('source_image_url')
                    ->orWhere('source_image_url', '=', '')
                    ->orWhereColumn('product_name', 'sku');
            })
            ->orderBy('sku')
            ->limit($maxSkus)
            ->pluck('sku')
            ->map(static fn (mixed $sku): string => trim((string) $sku))
            ->filter(static fn (string $sku): bool => $sku !== '')
            ->values()
            ->all();

        if ($skus === []) {
            return ['attempted' => 0, 'enriched' => 0, 'failed' => 0];
        }

        $enriched = 0;
        $failed = 0;
        foreach (array_chunk($skus, self::CHUNK_SIZE) as $chunk) {
            $counts = $this->enrichChunk($chunk);
            $enriched += $counts['enriched'];
            $failed += $counts['failed'];
        }

        return [
            'attempted' => count($skus),
            'enriched' => $enriched,
            'failed' => $failed,
        ];
    }

    /**
     * @param  array<int, string>  $chunk
     * @return array{enriched: int, failed: int}
     */
    private function enrichChunk(array $chunk): array
    {
        $result = $this->scraper->enrichPreorderPdpFields($chunk);
        if (($result['ok'] ?? false) !== true) {
            return ['enriched' => 0, 'failed' => count($chunk)];
        }

        /** @var array<string, array<string, mixed>|null> $rows */
        $rows = is_array($result['results'] ?? null) ? $result['results'] : [];
        $enriched = 0;
        $failed = 0;
        foreach ($chunk as $sku) {
            $fields = $rows[$sku] ?? null;
            if (! is_array($fields) || ! $this->applyFields($sku, $fields)) {
                $failed++;

                continue;
            }
            $enriched++;
        }

        return ['enriched' => $enriched, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function applyFields(string $sku, array $fields): bool
    {
        $imageUrl = trim((string) ($fields['image_url'] ?? ''));
        $productName = trim((string) ($fields['product_name'] ?? ''));
        $patch = [];
        if ($imageUrl !== '') {
            $patch['source_image_url'] = $imageUrl;
        }
        if ($productName !== '' && strcasecmp($productName, $sku) !== 0) {
            $patch['product_name'] = $productName;
        }
        foreach (['series' => 'series', 'category' => 'category', 'barcode' => 'barcode'] as $field => $column) {
            $value = trim((string) ($fields[$field] ?? ''));
            if ($value !== '') {
                $patch[$column] = $value;
            }
        }
        $releaseRaw = trim((string) ($fields['release_date'] ?? ''));
        if ($releaseRaw !== '') {
            $patch['release_date_label'] = $releaseRaw;
            try {
                $patch['release_date'] = Carbon::parse($releaseRaw)->toDateString();
            } catch (\Throwable) {
            }
        }
        if ($patch === []) {
            return false;
        }

        return PlamodInstockItem::query()->where('sku', '=', $sku)->update($patch) > 0;
    }
}
