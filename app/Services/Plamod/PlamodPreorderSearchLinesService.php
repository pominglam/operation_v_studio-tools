<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorder;
use App\Services\Products\Http\PlamodScraper;

final class PlamodPreorderSearchLinesService
{
    /** @var array<int, string> */
    private const array STOP_TOKENS = [
        're', 'hguc', 'hg', 'mg', 'rg', 'pg', 'eg', 'fm', 'hgbd', '100', '144', '60',
        'the', 'ver', 'ka', 'custom', 'type', 'mass', 'production',
    ];

    public function __construct(
        private readonly PlamodPreorderSettingsService $settings,
        private readonly PlamodScraper $scraper,
    ) {}

    /**
     * @param  array<int, string>  $lines
     * @return array{
     *   matched: array<int, array{line: string, sku: string, product_name: string, in_snapshot: bool}>,
     *   plamod_only: array<int, array{line: string, sku: string, product_name: string, plamod_pdp_url: string}>,
     *   not_found: array<int, string>
     * }
     */
    public function search(array $lines): array
    {
        $snapshot = $this->searchSnapshot($lines);
        $live = $this->searchLive($snapshot['pending_live']);

        return [
            'matched' => $snapshot['matched'],
            'plamod_only' => $live['plamod_only'],
            'not_found' => $live['not_found'],
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{
     *   matched: array<int, array{line: string, sku: string, product_name: string, in_snapshot: bool}>,
     *   pending_live: array<int, string>
     * }
     */
    public function searchSnapshot(array $lines): array
    {
        $terms = $this->normalizeTerms($lines);
        if ($terms === []) {
            return ['matched' => [], 'pending_live' => []];
        }

        $rows = $this->snapshotRows();
        $matched = [];
        $pendingLive = [];

        foreach ($terms as $term) {
            $hit = $rows->first(fn (PlamodPreorder $row): bool => $this->rowMatchesTerm($row, $term));
            if ($hit instanceof PlamodPreorder) {
                $matched[] = [
                    'line' => $term,
                    'sku' => (string) $hit->sku,
                    'product_name' => (string) $hit->product_name,
                    'in_snapshot' => true,
                ];
            } else {
                $pendingLive[] = $term;
            }
        }

        return [
            'matched' => $matched,
            'pending_live' => $pendingLive,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{
     *   plamod_only: array<int, array{line: string, sku: string, product_name: string, plamod_pdp_url: string}>,
     *   not_found: array<int, string>
     * }
     */
    public function searchLive(array $lines): array
    {
        $terms = $this->normalizeTerms($lines);
        if ($terms === []) {
            return ['plamod_only' => [], 'not_found' => []];
        }

        $plamodOnly = [];
        $notFound = [];

        $live = $this->scraper->searchRetailerPreorders($terms);
        /** @var array<string, mixed> $liveResults */
        $liveResults = is_array($live['results'] ?? null) ? $live['results'] : [];

        foreach ($terms as $term) {
            $remote = $liveResults[$term] ?? null;
            if (! is_array($remote) || ! is_string($remote['sku'] ?? null) || trim((string) $remote['sku']) === '') {
                $notFound[] = $term;

                continue;
            }

            $sku = trim((string) $remote['sku']);
            $productName = trim((string) ($remote['product_name'] ?? $sku));
            $pdp = trim((string) ($remote['plamod_pdp_url'] ?? ''));
            if ($pdp === '') {
                $pdp = 'https://plamod.com/retailer/products/'.rawurlencode($sku);
            }

            $plamodOnly[] = [
                'line' => $term,
                'sku' => $sku,
                'product_name' => $productName,
                'plamod_pdp_url' => $pdp,
            ];
        }

        return [
            'plamod_only' => $plamodOnly,
            'not_found' => $notFound,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, PlamodPreorder> */
    private function snapshotRows()
    {
        $excluded = $this->settings->get()['excluded_categories'];

        return PlamodPreorder::query()
            ->active()
            ->when($excluded !== [], function ($q) use ($excluded): void {
                $q->where(function ($sub) use ($excluded): void {
                    $sub->whereNull('category')->orWhereNotIn('category', $excluded);
                });
            })
            ->get(['sku', 'barcode', 'product_name']);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function normalizeTerms(array $lines): array
    {
        $terms = [];
        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }
            $v = trim($line);
            if ($v !== '') {
                $terms[] = $v;
            }
        }

        return array_values(array_unique($terms));
    }

    private function rowMatchesTerm(PlamodPreorder $row, string $term): bool
    {
        if (strcasecmp((string) $row->sku, $term) === 0) {
            return true;
        }
        if ($row->barcode !== null && strcasecmp((string) $row->barcode, $term) === 0) {
            return true;
        }

        return $this->nameMatchesTerm((string) $row->product_name, $term);
    }

    private function nameMatchesTerm(string $productName, string $term): bool
    {
        $haystack = $this->normalizeForMatch($productName);
        $needle = $this->normalizeForMatch($term);
        if ($needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }

        $tokens = $this->significantTokens($term);
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeForMatch(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['-', '/', '#'], ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function significantTokens(string $term): array
    {
        $parts = preg_split('/\s+/', $this->normalizeForMatch($term)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) < 2) {
                continue;
            }
            if (ctype_digit($part) || in_array($part, self::STOP_TOKENS, true)) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }
}
