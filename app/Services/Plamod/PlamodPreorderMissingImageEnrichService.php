<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorder;
use App\Services\Products\Http\PlamodScraper;

final class PlamodPreorderMissingImageEnrichService
{
    private const int CHUNK_SIZE = 15;

    public function __construct(
        private readonly PlamodScraper $scraper,
    ) {}

    /**
     * @return array{attempted: int, enriched: int, failed: int}
     */
    public function enrichActiveRowsMissingImageUrl(int $maxSkus = 300): array
    {
        $skus = PlamodPreorder::query()
            ->active()
            ->where(function ($query): void {
                $query->whereNull('source_image_url')
                    ->orWhere('source_image_url', '=', '');
            })
            ->orderBy('sku')
            ->limit($maxSkus)
            ->pluck('sku')
            ->map(static fn (mixed $sku): string => trim((string) $sku))
            ->filter(static fn (string $sku): bool => $sku !== '')
            ->values()
            ->all();

        if ($skus === []) {
            return [
                'attempted' => 0,
                'enriched' => 0,
                'failed' => 0,
            ];
        }

        $enriched = 0;
        $failed = 0;

        foreach (array_chunk($skus, self::CHUNK_SIZE) as $chunk) {
            $result = $this->scraper->enrichPreorderPdpFields($chunk);
            if (($result['ok'] ?? false) === false) {
                $failed += count($chunk);

                continue;
            }

            /** @var array<string, array<string, mixed>|null> $rows */
            $rows = is_array($result['results'] ?? null) ? $result['results'] : [];

            foreach ($chunk as $sku) {
                $fields = $rows[$sku] ?? null;
                if (! is_array($fields)) {
                    $failed++;

                    continue;
                }

                $imageUrl = trim((string) ($fields['image_url'] ?? ''));
                if ($imageUrl === '') {
                    $failed++;

                    continue;
                }

                PlamodPreorder::query()
                    ->where('sku', '=', $sku)
                    ->update(['source_image_url' => $imageUrl]);
                $enriched++;
            }
        }

        return [
            'attempted' => count($skus),
            'enriched' => $enriched,
            'failed' => $failed,
        ];
    }
}
