<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\RunPlamodPreorderLiveSearchJob;
use App\Models\PlamodPreorder;
use App\Services\Products\Http\PlamodScraper;

final class PlamodPreorderSearchLinesService
{
    /** @var array<int, string> */
    private const array STOP_TOKENS = [
        're', 'hguc', 'hg', 'mg', 'rg', 'pg', 'eg', 'fm', 'hgbd', 'hgac', '100', '144', '60',
        'the', 'ver', 'ka', 'custom', 'type', 'mass', 'production', 'gundam', 'rx',
    ];

    public function __construct(
        private readonly PlamodPreorderSettingsService $settings,
        private readonly PlamodScraper $scraper,
        private readonly PlamodPreorderLiveSearchStore $liveSearchStore,
        private readonly PlamodPreorderSearchRowAssembler $rowAssembler,
    ) {}

    /**
     * @param  array<int, string>  $lines
     * @return array{
     *   matched: array<int, array{line: string, sku: string, product_name: string, in_snapshot: bool}>,
     *   plamod_only: array<int, array{line: string, sku: string, product_name: string, plamod_pdp_url: string}>,
     *   not_found: array<int, string>,
     *   rows: array<int, array<string, mixed>>
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
            'rows' => $this->rowAssembler->rowsInLineOrder(
                $this->normalizeTerms($lines),
                $snapshot['matched'],
                $snapshot['rows'],
                $live['plamod_only'],
                $live['rows'],
            ),
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{
     *   matched: array<int, array{line: string, sku: string, product_name: string, in_snapshot: bool}>,
     *   pending_live: array<int, string>,
     *   rows: array<int, array<string, mixed>>
     * }
     */
    public function searchSnapshot(array $lines): array
    {
        $terms = $this->normalizeTerms($lines);
        if ($terms === []) {
            return ['matched' => [], 'pending_live' => [], 'rows' => []];
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
            'rows' => $this->rowAssembler->rowsForSkus(array_column($matched, 'sku')),
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{
     *   plamod_only: array<int, array{line: string, sku: string, product_name: string, plamod_pdp_url: string}>,
     *   not_found: array<int, string>,
     *   rows: array<int, array<string, mixed>>
     * }
     */
    public function searchLive(array $lines): array
    {
        $terms = $this->normalizeTerms($lines);
        if ($terms === []) {
            return ['plamod_only' => [], 'not_found' => [], 'rows' => []];
        }

        $plamodOnly = [];
        $notFound = [];
        $rows = [];

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
            $rows[] = $this->rowAssembler->rowFromLiveHit($remote);
        }

        return [
            'plamod_only' => $plamodOnly,
            'not_found' => $notFound,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{job_id: string, status: string}
     */
    public function startLiveSearchJob(array $lines): array
    {
        $terms = $this->normalizeTerms($lines);
        if ($terms === []) {
            return [
                'job_id' => '',
                'status' => 'completed',
            ];
        }

        $jobId = $this->liveSearchStore->create($terms);
        RunPlamodPreorderLiveSearchJob::dispatch($jobId, $terms);

        return [
            'job_id' => $jobId,
            'status' => 'queued',
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   plamod_only: array<int, array{line: string, sku: string, product_name: string, plamod_pdp_url: string}>,
     *   not_found: array<int, string>,
     *   rows: array<int, array<string, mixed>>,
     *   error_summary: string|null
     * }
     */
    public function liveSearchJobStatus(string $jobId): array
    {
        $payload = $this->liveSearchStore->get($jobId);
        if ($payload === null) {
            return [
                'status' => 'missing',
                'plamod_only' => [],
                'not_found' => [],
                'rows' => [],
                'error_summary' => 'Live search job not found or expired.',
            ];
        }

        return [
            'status' => (string) ($payload['status'] ?? 'queued'),
            'plamod_only' => is_array($payload['plamod_only'] ?? null) ? $payload['plamod_only'] : [],
            'not_found' => is_array($payload['not_found'] ?? null) ? $payload['not_found'] : [],
            'rows' => is_array($payload['rows'] ?? null) ? $payload['rows'] : [],
            'error_summary' => is_string($payload['error_summary'] ?? null) ? $payload['error_summary'] : null,
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
        $tokens = [];

        if (preg_match_all('/#(\d+)/', $term, $matches)) {
            foreach ($matches[1] as $number) {
                if ($number !== '') {
                    $tokens[] = $number;
                }
            }
        }

        if (preg_match_all('/\b([A-Za-z]{2,3}-\d+(?:-\d+)?)\b/', $term, $matches)) {
            foreach ($matches[1] as $code) {
                $normalized = mb_strtolower(str_replace('-', ' ', $code));
                foreach (preg_split('/\s+/', $normalized) ?: [] as $segment) {
                    if ($segment !== '' && strlen($segment) >= 2 && ! in_array($segment, self::STOP_TOKENS, true)) {
                        $tokens[] = $segment;
                    }
                }
            }
        }

        $parts = preg_split('/\s+/', $this->normalizeForMatch($term)) ?: [];
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
