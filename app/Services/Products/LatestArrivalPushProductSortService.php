<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Carbon;

final class LatestArrivalPushProductSortService
{
    public function __construct(
        private readonly ProductTypeDerivationService $typeDerivation,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function sortPreviewRows(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $cmp = $this->compareSortKeys(
                (int) ($a['type_rank'] ?? 8),
                (string) ($a['type_label'] ?? ''),
                is_string($a['product_created_at'] ?? null) ? strtotime($a['product_created_at']) : 0,
                (string) ($a['sku'] ?? ''),
                (int) ($b['type_rank'] ?? 8),
                (string) ($b['type_label'] ?? ''),
                is_string($b['product_created_at'] ?? null) ? strtotime($b['product_created_at']) : 0,
                (string) ($b['sku'] ?? ''),
            );

            return $cmp;
        });

        return $rows;
    }

    /**
     * @param  array<int, Product>  $products
     * @return array<int, Product>
     */
    public function sortProducts(array $products): array
    {
        $list = array_values($products);
        usort($list, fn (Product $a, Product $b): int => $this->compareProducts($a, $b));

        return $list;
    }

    public function typeRankForProduct(Product $product): int
    {
        return $this->typeToRank($this->resolveTypeLabel($product));
    }

    public function typeLabelForProduct(Product $product): string
    {
        return $this->resolveTypeLabel($product);
    }

    public function productCreatedAtIso(Product $product): ?string
    {
        $created = $product->created_at;
        if ($created instanceof Carbon) {
            return $created->toIso8601String();
        }

        return null;
    }

    private function compareProducts(Product $a, Product $b): int
    {
        $aLabel = $this->resolveTypeLabel($a);
        $bLabel = $this->resolveTypeLabel($b);
        $aTs = $a->created_at instanceof Carbon ? $a->created_at->getTimestamp() : 0;
        $bTs = $b->created_at instanceof Carbon ? $b->created_at->getTimestamp() : 0;

        return $this->compareSortKeys(
            $this->typeToRank($aLabel),
            $aLabel,
            $aTs,
            (string) $a->sku,
            $this->typeToRank($bLabel),
            $bLabel,
            $bTs,
            (string) $b->sku,
        );
    }

    private function compareSortKeys(
        int $aRank,
        string $aType,
        int|false $aCreatedTs,
        string $aSku,
        int $bRank,
        string $bType,
        int|false $bCreatedTs,
        string $bSku,
    ): int {
        $cmp = $this->typeRankSortIndex($aRank) <=> $this->typeRankSortIndex($bRank);
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $this->withinRankSortIndex($aRank, $aType) <=> $this->withinRankSortIndex($bRank, $bType);
        if ($cmp !== 0) {
            return $cmp;
        }

        $aCreated = is_int($aCreatedTs) ? $aCreatedTs : 0;
        $bCreated = is_int($bCreatedTs) ? $bCreatedTs : 0;
        if ($aCreated !== $bCreated) {
            return $bCreated <=> $aCreated;
        }

        return strcmp($aSku, $bSku);
    }

    private function resolveTypeLabel(Product $product): string
    {
        $stored = is_string($product->type) ? trim($product->type) : '';
        $storedLabel = $stored !== '' ? $this->normalizeTypeLabel($stored) : '';
        $derived = $this->typeDerivation->deriveFromName((string) $product->description);
        $derivedLabel = $derived !== null ? $this->normalizeTypeLabel($derived) : '';

        if ($derivedLabel !== '' && ($this->isGenericSortType($storedLabel) || $this->shouldPreferDerivedOverStored($derivedLabel))) {
            return $derivedLabel;
        }

        if ($storedLabel !== '') {
            return $storedLabel;
        }

        return $derivedLabel;
    }

    private function isGenericSortType(string $normalizedType): bool
    {
        if ($normalizedType === '' || $normalizedType === 'OTHERS') {
            return true;
        }

        /** @var array<string, int> $map */
        $map = config('latest_arrival.type_to_rank', []);
        foreach ($map as $key => $_rank) {
            if ($this->normalizeTypeLabel((string) $key) === $normalizedType) {
                return false;
            }
        }

        return true;
    }

    private function shouldPreferDerivedOverStored(string $normalizedDerived): bool
    {
        /** @var array<int, string> $types */
        $types = config('latest_arrival.prefer_derived_over_stored_types', []);
        foreach ($types as $type) {
            if ($this->normalizeTypeLabel((string) $type) === $normalizedDerived) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTypeLabel(string $type): string
    {
        $type = mb_strtoupper(trim($type));

        return str_replace(' ', '-', $type);
    }

    private function typeToRank(string $type): int
    {
        $type = $this->normalizeTypeLabel($type);
        if ($type === '') {
            return (int) config('latest_arrival.default_type_rank', 8);
        }

        /** @var array<string, int> $map */
        $map = config('latest_arrival.type_to_rank', []);
        $normalized = [];
        foreach ($map as $key => $rank) {
            $normalized[$this->normalizeTypeLabel((string) $key)] = (int) $rank;
        }

        if (isset($normalized[$type])) {
            return $normalized[$type];
        }

        return (int) config('latest_arrival.default_type_rank', 8);
    }

    private function withinRankSortIndex(int $rank, string $type): int
    {
        $type = $this->normalizeTypeLabel($type);
        /** @var array<int, array<string, int>> $byRank */
        $byRank = config('latest_arrival.type_within_rank_order', []);
        $order = $byRank[$rank] ?? [];
        $normalized = [];
        foreach ($order as $key => $index) {
            $normalized[$this->normalizeTypeLabel((string) $key)] = (int) $index;
        }

        return $normalized[$type] ?? (int) config('latest_arrival.default_within_rank_order', 50);
    }

    private function typeRankSortIndex(int $rank): int
    {
        /** @var array<int, int> $order */
        $order = config('latest_arrival.type_rank_display_order', [7, 69, 6, 65, 5, 4, 3, 2, 8]);
        $pos = array_search($rank, $order, true);

        return $pos === false ? PHP_INT_MAX : (int) $pos;
    }
}
