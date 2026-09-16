<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\PlamodInstockItem;
use App\Models\PlamodPreorder;
use App\Support\Products\ModelKitSeriesCatalog;

final class PlamodSeriesLookupService
{
    /** @var array<string, array{raw_series: string, erp_series: string, source: string, plamod_pdp_url: ?string}>|null */
    private ?array $index = null;

    /**
     * @return array<string, array{raw_series: string, erp_series: string, source: string, plamod_pdp_url: ?string}>
     */
    public function seriesIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        /** @var array<string, array{raw_series: string, erp_series: string, source: string, plamod_pdp_url: ?string}> $index */
        $index = [];

        PlamodInstockItem::query()
            ->whereNotNull('series')
            ->where('series', '!=', '')
            ->orderByDesc('last_seen_at')
            ->get(['sku', 'series', 'plamod_pdp_url'])
            ->each(function (PlamodInstockItem $row) use (&$index): void {
                $sku = trim($row->sku);
                if ($sku === '' || isset($index[$sku])) {
                    return;
                }

                $mapped = $this->mapPlamodSeriesToErp((string) $row->series);
                if ($mapped === null) {
                    return;
                }

                $index[$sku] = [
                    'raw_series' => trim((string) $row->series),
                    'erp_series' => $mapped,
                    'source' => 'plamod_instock',
                    'plamod_pdp_url' => $row->plamod_pdp_url,
                ];
            });

        PlamodPreorder::query()
            ->whereNull('dropped_at')
            ->whereNotNull('series')
            ->where('series', '!=', '')
            ->orderByDesc('last_seen_at')
            ->get(['sku', 'series'])
            ->each(function (PlamodPreorder $row) use (&$index): void {
                $sku = trim($row->sku);
                if ($sku === '' || isset($index[$sku])) {
                    return;
                }

                $mapped = $this->mapPlamodSeriesToErp((string) $row->series);
                if ($mapped === null) {
                    return;
                }

                $index[$sku] = [
                    'raw_series' => trim((string) $row->series),
                    'erp_series' => $mapped,
                    'source' => 'plamod_preorder',
                    'plamod_pdp_url' => self::pdpUrlForSku($sku),
                ];
            });

        $this->index = $index;

        return $this->index;
    }

    /**
     * @return array{raw_series: string, erp_series: string, source: string, plamod_pdp_url: ?string}|null
     */
    public function lookup(string $sku): ?array
    {
        return $this->seriesIndex()[$sku] ?? null;
    }

    public function mapPlamodSeriesToErp(string $rawSeries): ?string
    {
        $normalized = $this->normalizePlamodSeriesLabel($rawSeries);
        if ($normalized === '') {
            return null;
        }

        return ModelKitSeriesCatalog::erpSeriesForFandomName($normalized);
    }

    private function normalizePlamodSeriesLabel(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '/')) {
            $value = trim(explode('/', $value)[0]);
        }

        if (str_contains($value, ';')) {
            $value = trim(explode(';', $value)[0]);
        }

        return trim($value);
    }

    /**
     * @return array{audit_scope: int, plamod_instock_rows: int, plamod_preorder_rows: int, matched_in_scope: int}
     */
    public static function pdpUrlForSku(string $sku): string
    {
        return 'https://plamod.com/retailer/products/'.rawurlencode($sku);
    }

    /**
     * @param  list<string>  $auditSkus
     * @return array{audit_scope: int, plamod_instock_rows: int, plamod_preorder_rows: int, matched_in_scope: int}
     */
    public function coverageStats(int $auditScopeCount, array $auditSkus): array
    {
        $index = $this->seriesIndex();
        $matched = 0;
        foreach ($auditSkus as $sku) {
            if (isset($index[$sku])) {
                $matched++;
            }
        }

        return [
            'audit_scope' => $auditScopeCount,
            'plamod_instock_rows' => PlamodInstockItem::query()->whereNotNull('series')->where('series', '!=', '')->count(),
            'plamod_preorder_rows' => PlamodPreorder::query()->whereNull('dropped_at')->whereNotNull('series')->where('series', '!=', '')->count(),
            'matched_in_scope' => $matched,
        ];
    }
}
