<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorder;
use App\Models\PlamodPreorderOffer;
use App\Services\Products\Http\PlamodScraper;

final class PlamodPreorderOfferEnrichService
{
    private const int CHUNK_SIZE = 15;

    private const int DEFAULT_MAX_SKUS = 400;

    public function __construct(
        private readonly PlamodScraper $scraper,
        private readonly PlamodPreorderOfferUpsertService $offerUpserts,
        private readonly PlamodPdpDescriptionPersistService $descriptions,
    ) {}

    /**
     * @return array{attempted: int, skus: int, failed: int}
     */
    public function enrichActiveRowsMissingOffers(int $maxSkus = self::DEFAULT_MAX_SKUS): array
    {
        $skus = $this->skusMissingOffers($maxSkus);
        if ($skus === []) {
            return ['attempted' => 0, 'skus' => 0, 'failed' => 0];
        }

        $imported = 0;
        $failed = 0;
        foreach (array_chunk($skus, self::CHUNK_SIZE) as $chunk) {
            $chunkResult = $this->applyChunk($chunk);
            $imported += $chunkResult['skus'];
            $failed += $chunkResult['failed'];
        }

        return [
            'attempted' => count($skus),
            'skus' => $imported,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function skusMissingOffers(int $maxSkus): array
    {
        $already = PlamodPreorderOffer::query()->distinct()->pluck('sku');

        return PlamodPreorder::query()
            ->active()
            ->whereNotIn('sku', $already)
            ->orderBy('sku')
            ->limit($maxSkus)
            ->pluck('sku')
            ->map(static fn (mixed $sku): string => trim((string) $sku))
            ->filter(static fn (string $sku): bool => $sku !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $chunk
     * @return array{skus: int, failed: int}
     */
    private function applyChunk(array $chunk): array
    {
        $result = $this->scraper->enrichPreorderPdpFields($chunk);
        if (($result['ok'] ?? false) === false) {
            return ['skus' => 0, 'failed' => count($chunk)];
        }

        /** @var array<string, array<string, mixed>|null> $rows */
        $rows = is_array($result['results'] ?? null) ? $result['results'] : [];
        $imported = 0;
        $failed = 0;
        foreach ($chunk as $sku) {
            $fields = $rows[$sku] ?? null;
            if (! is_array($fields)) {
                $failed++;

                continue;
            }

            $html = trim((string) ($fields['description_html'] ?? ''));
            if ($html !== '') {
                $this->descriptions->persistForSku(
                    $sku,
                    $html,
                    trim((string) ($fields['product_name'] ?? '')) ?: null,
                );
            }

            $offers = is_array($fields['preorder_offers'] ?? null) ? $fields['preorder_offers'] : [];
            $committed = array_values(array_filter(
                $offers,
                static fn (mixed $offer): bool => is_array($offer) && ctype_digit(trim((string) ($offer['quantity'] ?? ''))) && (int) $offer['quantity'] > 0,
            ));
            if ($committed === []) {
                $failed++;

                continue;
            }

            $this->offerUpserts->replaceForSku($sku, $committed);
            $this->fillMissingPreorderCosts($sku, $fields);
            $imported++;
        }

        return ['skus' => $imported, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function fillMissingPreorderCosts(string $sku, array $fields): void
    {
        $price = trim((string) ($fields['price_preorder'] ?? ''));
        $qty = trim((string) ($fields['quantity_preorder'] ?? ''));
        $patch = [];
        if ($price !== '') {
            $patch['price_preorder'] = $price;
        }
        if ($qty !== '' && ctype_digit($qty)) {
            $patch['quantity_preorder'] = (int) $qty;
        }
        if ($patch === []) {
            return;
        }

        PlamodPreorder::query()->active()->where('sku', '=', $sku)->update($patch);
    }
}
