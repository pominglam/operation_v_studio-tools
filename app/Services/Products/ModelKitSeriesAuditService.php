<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTOs\Products\ModelKitSeriesProposal;
use App\Models\Product;
use App\Support\Products\AgentTestSkuGuard;
use App\Support\Products\ModelKitAccessoryKind;
use App\Support\Products\ModelKitSeriesCatalog;
use App\Support\Products\Storefront\ModelKitStorefrontTagResolver;

final class ModelKitSeriesAuditService
{
    private const CONFIDENCE_RANK = ['high' => 3, 'medium' => 2, 'low' => 1];

    public function __construct(
        private readonly ModelKitSeriesInferenceService $inference,
        private readonly ModelKitStorefrontTagResolver $tagResolver,
        private readonly GundamFandomSeriesLookupService $fandom,
        private readonly PlamodSeriesLookupService $plamod,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     ok: int,
     *     summary: array<string, int>,
     *     plamod_coverage: array<string, int>,
     *     by_series: array<string, list<array<string, mixed>>>,
     *     findings: array<string, list<array<string, mixed>>>
     * }
     */
    public function audit(
        bool $activeOnly = true,
        ?string $seriesTagSlugFilter = null,
        string $minConfidence = 'low',
        bool $withFandom = true,
        bool $withPlamod = true,
    ): array {
        /** @var array<string, list<array<string, mixed>>> */
        $findings = [
            'proposed_fill' => [],
            'proposed_change' => [],
            'review_low_confidence' => [],
            'unresolved' => [],
            'unknown_stored_series' => [],
            'tag_mismatch' => [],
        ];

        /** @var array<string, list<array<string, mixed>>> */
        $bySeries = [];

        $scanned = 0;
        $ok = 0;
        $plamodMatched = 0;
        $plamodProposedChange = 0;
        $plamodProposedFill = 0;
        $minRank = self::CONFIDENCE_RANK[$minConfidence] ?? 1;

        /** @var list<string> $auditSkus */
        $auditSkus = [];

        $plamodIndex = $withPlamod ? $this->plamod->seriesIndex() : [];

        $query = ModelKitTaxonomyAuditService::modelKitScope(Product::query());
        if ($activeOnly) {
            $query->whereNull('archived_at');
        }

        /** @var array<string, true> $seenSkus */
        $seenSkus = [];

        $query->orderBy('id')->chunkById(100, function ($products) use (
            &$seenSkus,
            &$scanned,
            &$ok,
            &$plamodMatched,
            &$plamodProposedChange,
            &$plamodProposedFill,
            &$auditSkus,
            &$findings,
            &$bySeries,
            $seriesTagSlugFilter,
            $minRank,
            $withFandom,
            $plamodIndex,
        ): void {
            foreach ($products as $product) {
                $sku = (string) $product->sku;
                if (isset($seenSkus[$sku])) {
                    continue;
                }
                $seenSkus[$sku] = true;

                if (AgentTestSkuGuard::isAgentTestSku($sku)) {
                    continue;
                }

                if (! $this->expectsSeriesAudit($product)) {
                    continue;
                }

                $scanned++;
                $auditSkus[] = $sku;
                $text = $this->inference->searchableText($product);
                $storedRaw = self::normalize($product->series);
                $storedCanonical = $this->inference->normalizeStoredSeries($storedRaw);
                $storedTagSlug = $this->inference->tagSlugForErpSeries($storedRaw);

                $rulesProposal = $this->inference->infer($product, $text);
                $fandomMeta = null;
                $plamodMeta = $plamodIndex[$sku] ?? null;
                $proposal = $rulesProposal;

                if ($plamodMeta !== null) {
                    $plamodMatched++;
                    $tagSlug = $this->inference->tagSlugForErpSeries($plamodMeta['erp_series']) ?? '';
                    $proposal = $this->refineGunplaProposal(
                        $product,
                        new ModelKitSeriesProposal(
                            erpSeries: $plamodMeta['erp_series'],
                            tagSlug: $tagSlug,
                            ruleId: 'plamod',
                            confidence: 'high',
                            evidence: 'plamod:'.$plamodMeta['raw_series'],
                        ),
                        $rulesProposal,
                    );
                } elseif ($withFandom && $this->isGunplaForFandom($product)) {
                    $fandomMeta = $this->fandom->lookup(
                        trim((string) $product->description),
                        cacheKey: (string) $product->sku,
                    );
                    if ($fandomMeta !== null && is_string($fandomMeta['erp_series'])) {
                        $proposal = $this->refineGunplaProposal(
                            $product,
                            new ModelKitSeriesProposal(
                                erpSeries: $fandomMeta['erp_series'],
                                tagSlug: is_string($fandomMeta['tag_slug']) ? $fandomMeta['tag_slug'] : '',
                                ruleId: 'fandom',
                                confidence: 'high',
                                evidence: (string) ($fandomMeta['evidence'] ?? 'gunpla'),
                            ),
                            $rulesProposal,
                        );
                    }
                }

                $proposalRank = $proposal !== null ? (self::CONFIDENCE_RANK[$proposal->confidence] ?? 0) : 0;

                $tags = $this->tagResolver->tagsForProduct($product);
                $resolverSeriesTag = self::seriesTagFromTags($tags);
                $resolverTagSlug = $resolverSeriesTag !== null
                    ? substr($resolverSeriesTag, strlen('mk:series:'))
                    : null;

                if ($storedRaw !== null && $storedTagSlug !== null
                    && ModelKitSeriesCatalog::erpSeriesForTagSlug($storedTagSlug) === null) {
                    $isGundamLine = mb_strtolower(trim((string) ($product->franchise ?? ''))) === 'gundam'
                        || mb_strtolower(trim((string) ($product->product_line ?? ''))) === 'gunpla';
                    if (! $isGundamLine && $resolverTagSlug === $storedTagSlug) {
                        $ok++;

                        continue;
                    }

                    $row = $this->row($product, $storedRaw, $storedCanonical, $proposal, $rulesProposal, $fandomMeta, $plamodMeta, $storedTagSlug, $resolverTagSlug, 'unknown_stored_series');
                    $findings['unknown_stored_series'][] = $row;
                    $this->bucketBySeries($bySeries, $storedTagSlug ?? 'unknown', $row);

                    continue;
                }

                if ($proposal === null) {
                    if ($storedRaw === null && $this->franchiseExpectsSeries($product)) {
                        $row = $this->row($product, null, null, null, $rulesProposal, $fandomMeta, $plamodMeta, null, $resolverTagSlug, 'unresolved');
                        $findings['unresolved'][] = $row;
                        $this->bucketBySeries($bySeries, 'unresolved', $row);
                    } elseif ($storedRaw !== null && $resolverTagSlug !== null && $storedTagSlug !== $resolverTagSlug) {
                        $row = $this->row($product, $storedRaw, $storedCanonical, null, $rulesProposal, $fandomMeta, $plamodMeta, $storedTagSlug, $resolverTagSlug, 'tag_mismatch');
                        $findings['tag_mismatch'][] = $row;
                        $this->bucketBySeries($bySeries, $storedTagSlug ?? 'unknown', $row);
                    } else {
                        $ok++;
                    }

                    continue;
                }

                if ($seriesTagSlugFilter !== null
                    && $proposal->tagSlug !== $seriesTagSlugFilter
                    && $storedTagSlug !== $seriesTagSlugFilter) {
                    continue;
                }

                $canonicalProposed = $proposal->erpSeries;
                $seriesMatch = $storedCanonical !== null
                    && mb_strtolower($storedCanonical) === mb_strtolower($canonicalProposed);

                if ($seriesMatch && ($storedTagSlug === $proposal->tagSlug || $storedTagSlug === null)) {
                    $ok++;

                    continue;
                }

                if ($proposalRank < $minRank) {
                    if ($storedRaw === null) {
                        $row = $this->row($product, null, null, $proposal, $rulesProposal, $fandomMeta, $plamodMeta, null, $resolverTagSlug, 'review_low_confidence');
                        $findings['review_low_confidence'][] = $row;
                        $this->bucketBySeries($bySeries, $proposal->tagSlug, $row);
                    } else {
                        $ok++;
                    }

                    continue;
                }

                if ($storedRaw === null) {
                    $row = $this->row($product, null, null, $proposal, $rulesProposal, $fandomMeta, $plamodMeta, null, $resolverTagSlug, 'proposed_fill');
                    $findings['proposed_fill'][] = $row;
                    if ($plamodMeta !== null) {
                        $plamodProposedFill++;
                    }
                    $this->bucketBySeries($bySeries, $proposal->tagSlug, $row);
                } elseif (! $seriesMatch || ($storedTagSlug !== null && $storedTagSlug !== $proposal->tagSlug)) {
                    $row = $this->row($product, $storedRaw, $storedCanonical, $proposal, $rulesProposal, $fandomMeta, $plamodMeta, $storedTagSlug, $resolverTagSlug, 'proposed_change');
                    if ($plamodMeta !== null) {
                        $plamodProposedChange++;
                    }
                    $findings['proposed_change'][] = $row;
                    $this->bucketBySeries($bySeries, $proposal->tagSlug, $row);
                } else {
                    $ok++;
                }
            }
        });

        ksort($bySeries);

        $summary = array_map(static fn (array $rows): int => count($rows), $findings);
        $summary['ok'] = $ok;

        $plamodCoverage = $withPlamod
            ? $this->plamod->coverageStats($scanned, $auditSkus)
            : ['audit_scope' => $scanned, 'plamod_instock_rows' => 0, 'plamod_preorder_rows' => 0, 'matched_in_scope' => 0];

        return [
            'scanned' => $scanned,
            'ok' => $ok,
            'summary' => $summary,
            'plamod_coverage' => array_merge($plamodCoverage, [
                'proposed_change' => $plamodProposedChange,
                'proposed_fill' => $plamodProposedFill,
            ]),
            'by_series' => $bySeries,
            'findings' => $findings,
        ];
    }

    /**
     * @param  array{scanned: int, ok: int, summary: array<string, int>, by_series: array<string, list<array<string, mixed>>>, findings: array<string, list<array<string, mixed>>>}  $result
     * @return array{md: string, json: string, approved_template: string, proposals: string, csv: string}
     */
    public function writeReports(array $result): array
    {
        $mdPath = storage_path('app/model-kit-series-audit.md');
        $jsonPath = storage_path('app/model-kit-series-audit.json');
        $templatePath = storage_path('app/model-kit-series-approved.template.json');
        $proposalsPath = storage_path('app/model-kit-series-proposals.md');
        $csvPath = storage_path('app/model-kit-series-proposals.csv');

        file_put_contents($mdPath, self::renderMarkdown($result));
        file_put_contents($jsonPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'scanned' => $result['scanned'],
            'ok' => $result['ok'],
            'summary' => $result['summary'],
            'plamod_coverage' => $result['plamod_coverage'] ?? [],
            'by_series' => $result['by_series'],
            'findings' => $result['findings'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $approvedRows = [];
        $plamodCoverage = $result['plamod_coverage'] ?? [];
        $proposalLines = [
            '# Model kit series — proposed modifications',
            '',
            'Generated: '.now()->toIso8601String(),
            '',
            'Review this file (product names included). Copy approved rows to `model-kit-series-approved.json`, then `products:model-kit-series-apply`.',
            '',
        ];
        if ($plamodCoverage !== []) {
            $proposalLines[] = sprintf(
                'Plamod coverage: **%d / %d** audit-scope SKUs matched (`%d` instock rows, `%d` preorder rows). Plamod proposals: **%d** change + **%d** fill.',
                $plamodCoverage['matched_in_scope'] ?? 0,
                $plamodCoverage['audit_scope'] ?? $result['scanned'],
                $plamodCoverage['plamod_instock_rows'] ?? 0,
                $plamodCoverage['plamod_preorder_rows'] ?? 0,
                $plamodCoverage['proposed_change'] ?? 0,
                $plamodCoverage['proposed_fill'] ?? 0,
            );
            $proposalLines[] = '';
        }
        $csvLines = ['action,sku,product_name,stored_series,proposed_series,confidence,source,plamod_series'];

        foreach (['proposed_change', 'proposed_fill'] as $bucket) {
            $rows = $result['findings'][$bucket] ?? [];
            $title = $bucket === 'proposed_change'
                ? 'Wrong series — change stored value'
                : 'Missing series — fill empty value';
            $proposalLines[] = "## {$title} (".count($rows).')';
            $proposalLines[] = '';

            foreach ($rows as $row) {
                if ($row['proposed_series'] === null) {
                    continue;
                }

                $name = (string) ($row['product_name'] ?? $row['description'] ?? '');
                $stored = $row['stored_series'] ?? '(empty)';
                $source = $row['proposal_source'] ?? 'rules';
                $rulesNote = in_array($source, ['fandom', 'plamod'], true) && ($row['rules_series'] ?? null) !== null
                    ? ' (rules had: '.$row['rules_series'].')'
                    : '';
                $linkNote = '';
                if ($source === 'plamod' && is_string($row['plamod_pdp_url'] ?? null)) {
                    $raw = is_string($row['plamod_series'] ?? null) ? ' — '.$row['plamod_series'] : '';
                    $linkNote = ' — [plamod]('.$row['plamod_pdp_url'].')'.$raw;
                } elseif (is_string($row['fandom_wiki_title'] ?? null)) {
                    $linkNote = ' — [wiki]('.$this->fandomWikiUrl((string) $row['fandom_wiki_title']).')';
                }
                $proposalLines[] = sprintf(
                    '- **%s** (`%s`) — %s → **%s** [%s via %s%s]%s',
                    $name,
                    $row['sku'],
                    $stored,
                    $row['proposed_series'],
                    $row['confidence'] ?? '?',
                    $source,
                    $rulesNote,
                    $linkNote,
                );

                $csvLines[] = implode(',', array_map(
                    static fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
                    [
                        (string) $row['action'],
                        (string) $row['sku'],
                        $name,
                        (string) ($row['stored_series'] ?? ''),
                        (string) $row['proposed_series'],
                        (string) ($row['confidence'] ?? ''),
                        $source,
                        (string) ($row['plamod_series'] ?? ''),
                    ],
                ));

                $approvedRows[] = [
                    'sku' => $row['sku'],
                    'product_name' => $name,
                    'stored_series' => $row['stored_series'],
                    'series' => $row['proposed_series'],
                    'note' => $row['action'],
                ];
            }

            $proposalLines[] = '';
        }

        file_put_contents($proposalsPath, implode("\n", $proposalLines));
        file_put_contents($csvPath, implode("\n", $csvLines)."\n");
        file_put_contents($templatePath, json_encode($approvedRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'md' => $mdPath,
            'json' => $jsonPath,
            'approved_template' => $templatePath,
            'proposals' => $proposalsPath,
            'csv' => $csvPath,
        ];
    }

    private function expectsSeriesAudit(Product $product): bool
    {
        if ($this->isAccessoryLine($product)) {
            return false;
        }

        $line = mb_strtolower(trim((string) ($product->product_line ?? '')));
        $skipLines = [
            'action base', 'builders parts hd', 'option system', 'pokémon plamo collection',
            'pokemon plamo collection', '30 minutes missions', '30 minutes sisters', '30 minutes fantasy',
        ];
        foreach ($skipLines as $skip) {
            if ($line === $skip || str_starts_with($line, $skip)) {
                return false;
            }
        }

        return $this->franchiseExpectsSeries($product)
            || self::normalize($product->series) !== null;
    }

    private function franchiseExpectsSeries(Product $product): bool
    {
        $franchise = mb_strtolower(trim((string) ($product->franchise ?? '')));
        $line = mb_strtolower(trim((string) ($product->product_line ?? '')));

        if ($franchise === 'gundam' || $line === 'gunpla') {
            return true;
        }

        $nonGundamSeriesFranchises = [
            'patlabor', 'macross', 'mazinger', 'getter robo', 'kotetsu jeeg',
            'armored core', 'eureka seven', 'doraemon', 'sakura wars', 'super robot wars',
        ];
        foreach ($nonGundamSeriesFranchises as $needle) {
            if (str_contains($franchise, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isAccessoryLine(Product $product): bool
    {
        $kind = mb_strtolower(trim((string) ($product->accessory_kind ?? '')));

        return in_array($kind, [
            ModelKitAccessoryKind::OPTION_PARTS,
            ModelKitAccessoryKind::DETAIL_PARTS,
            ModelKitAccessoryKind::DISPLAY_STAND,
        ], true)
            || mb_strtolower(trim((string) ($product->type ?? ''))) === 'action base'
            || mb_strtolower(trim((string) ($product->type ?? ''))) === 'option parts set';
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $bySeries
     * @param  array<string, mixed>  $row
     */
    private function bucketBySeries(array &$bySeries, string $tagSlug, array $row): void
    {
        $bySeries[$tagSlug][] = $row;
    }

    private function isGunplaForFandom(Product $product): bool
    {
        $franchise = mb_strtolower(trim((string) ($product->franchise ?? '')));
        $line = mb_strtolower(trim((string) ($product->product_line ?? '')));

        if ($line !== 'gunpla' && $franchise !== 'gundam') {
            return false;
        }

        $nonGunplaFranchises = [
            'patlabor', 'macross', 'mazinger', 'getter robo', 'kotetsu jeeg',
            'armored trooper votoms', 'doraemon', 'sakura wars', 'super robot wars',
            'armored core', 'eureka seven',
        ];
        foreach ($nonGunplaFranchises as $needle) {
            if (str_contains($franchise, $needle)) {
                return false;
            }
        }

        return true;
    }

    private function refineGunplaProposal(
        Product $product,
        ModelKitSeriesProposal $gunplaProposal,
        ?ModelKitSeriesProposal $rulesProposal,
    ): ModelKitSeriesProposal {
        $proposal = $this->applyBuildFightersLineOverride($product, $gunplaProposal);
        $proposal = $this->applyEndlessWaltzGuard($product, $proposal, $rulesProposal);

        return $proposal;
    }

    private function applyBuildFightersLineOverride(Product $product, ModelKitSeriesProposal $proposal): ModelKitSeriesProposal
    {
        if (! $this->isBuildFightersProductLine($product)) {
            return $proposal;
        }

        $buildFighters = ModelKitSeriesCatalog::erpSeriesForTagSlug('gundam_build_fighters');
        if ($buildFighters === null) {
            return $proposal;
        }

        if ($proposal->erpSeries === $buildFighters && $proposal->ruleId === 'line:hgbf') {
            return $proposal;
        }

        // HGBF / SDBF / HGBC kits belong on the Build Fighters shelf — not MS franchise pages.
        return new ModelKitSeriesProposal(
            erpSeries: $buildFighters,
            tagSlug: 'gundam_build_fighters',
            ruleId: 'line:hgbf',
            confidence: 'high',
            evidence: 'line:hgbf→build_fighters',
        );
    }

    private function applyEndlessWaltzGuard(
        Product $product,
        ModelKitSeriesProposal $proposal,
        ?ModelKitSeriesProposal $rulesProposal,
    ): ModelKitSeriesProposal {
        $endlessWaltz = ModelKitSeriesCatalog::erpSeriesForTagSlug('gundam_wing__endless_waltz');
        if ($endlessWaltz === null || $proposal->erpSeries !== $endlessWaltz) {
            return $proposal;
        }

        if ($this->productNameIndicatesEndlessWaltz($product)) {
            return $proposal;
        }

        if ($rulesProposal !== null && $rulesProposal->erpSeries !== $endlessWaltz) {
            return $rulesProposal;
        }

        $wing = ModelKitSeriesCatalog::erpSeriesForTagSlug('gundam_wing');
        if ($wing === null) {
            return $proposal;
        }

        return new ModelKitSeriesProposal(
            erpSeries: $wing,
            tagSlug: 'gundam_wing',
            ruleId: 'guard:no-ew-in-title',
            confidence: 'high',
            evidence: 'guard:no-ew-in-title→wing',
        );
    }

    private function isBuildFightersProductLine(Product $product): bool
    {
        $subline = mb_strtolower(trim((string) ($product->subline ?? '')));
        if (in_array($subline, ['hgbf', 'hgbft', 'sdbf', 'hgbc'], true)) {
            return true;
        }

        $name = mb_strtoupper(trim((string) $product->description));

        return preg_match('/^(HGBF|SDBF|HGBC)\b/', $name) === 1;
    }

    private function productNameIndicatesEndlessWaltz(Product $product): bool
    {
        $name = mb_strtoupper(trim((string) $product->description));

        if (str_contains($name, 'ENDLESS WALTZ')) {
            return true;
        }

        if (preg_match('/\bEW\b/', $name) === 1) {
            return true;
        }

        if (preg_match('/\bXXXG-00W0\b/', $name) === 1) {
            return true;
        }

        if (str_contains($name, 'ZERO EW') || str_contains($name, 'WING ZERO EW')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{fandom_series: ?string, wiki_title: ?string, erp_series: ?string, tag_slug: ?string, evidence: string}|null  $fandomMeta
     * @param  array{raw_series: string, erp_series: string, source: string, plamod_pdp_url: ?string}|null  $plamodMeta
     * @return array<string, mixed>
     */
    private function row(
        Product $product,
        ?string $storedRaw,
        ?string $storedCanonical,
        ?ModelKitSeriesProposal $proposal,
        ?ModelKitSeriesProposal $rulesProposal,
        ?array $fandomMeta,
        ?array $plamodMeta,
        ?string $storedTagSlug,
        ?string $resolverTagSlug,
        string $action,
    ): array {
        $productName = trim((string) $product->description);
        $proposalSource = match ($proposal?->ruleId) {
            'plamod', 'line:hgbf', 'guard:no-ew-in-title' => $plamodMeta !== null ? 'plamod' : 'rules',
            'fandom' => 'fandom',
            default => 'rules',
        };

        return [
            'sku' => $product->sku,
            'product_name' => $productName,
            'description' => mb_substr($productName, 0, 90),
            'franchise' => self::normalize($product->franchise),
            'subline' => self::normalize($product->subline),
            'stored_series' => $storedRaw,
            'stored_canonical' => $storedCanonical,
            'stored_tag_slug' => $storedTagSlug,
            'proposed_series' => $proposal?->erpSeries,
            'proposed_tag_slug' => $proposal?->tagSlug,
            'confidence' => $proposal?->confidence,
            'rule_id' => $proposal?->ruleId,
            'evidence' => $proposal?->evidence,
            'rules_series' => $rulesProposal?->erpSeries,
            'rules_rule_id' => $rulesProposal?->ruleId,
            'fandom_series' => $fandomMeta['fandom_series'] ?? null,
            'fandom_wiki_title' => $fandomMeta['wiki_title'] ?? null,
            'plamod_series' => $plamodMeta['raw_series'] ?? null,
            'plamod_source' => $plamodMeta['source'] ?? null,
            'plamod_pdp_url' => $plamodMeta['plamod_pdp_url'] ?? null,
            'proposal_source' => $proposalSource,
            'resolver_tag_slug' => $resolverTagSlug,
            'action' => $action,
        ];
    }

    /**
     * @param  array{scanned: int, ok: int, summary: array<string, int>, by_series: array<string, list<array<string, mixed>>>, findings: array<string, list<array<string, mixed>>>}  $result
     */
    private static function renderMarkdown(array $result): string
    {
        $lines = [
            '# Model kit series audit',
            '',
            'Generated: '.now()->toIso8601String(),
            '',
            "- Scanned: **{$result['scanned']}** products in series-audit scope",
            "- OK (stored matches inference or intentionally skipped): **{$result['ok']}**",
        ];
        if (($result['plamod_coverage'] ?? []) !== []) {
            $pc = $result['plamod_coverage'];
            $lines[] = sprintf(
                '- Plamod: **%d / %d** audit-scope SKUs matched; proposals **%d** change + **%d** fill',
                $pc['matched_in_scope'] ?? 0,
                $pc['audit_scope'] ?? $result['scanned'],
                $pc['proposed_change'] ?? 0,
                $pc['proposed_fill'] ?? 0,
            );
        }
        $lines = array_merge($lines, [
            '',
            'Workflow: `docs/requirements/model-kit-series-audit.md`',
            '',
            'Apply template (copy to `model-kit-series-approved.json`, edit, then `products:model-kit-series-apply`):',
            '`storage/app/model-kit-series-approved.template.json`',
            '',
        ]);

        foreach ([
            'proposed_change' => 'Wrong series — proposed ERP change (high/medium confidence)',
            'proposed_fill' => 'Missing series — proposed fill (high/medium confidence)',
            'review_low_confidence' => 'Low-confidence inference — operator review',
            'unresolved' => 'Gundam/Gunpla with no inference rule match',
            'unknown_stored_series' => 'Stored series not in canonical catalog',
            'tag_mismatch' => 'Stored series tag slug mismatch (legacy rows)',
        ] as $key => $title) {
            $rows = $result['findings'][$key] ?? [];
            $lines[] = "## {$title} (".count($rows).')';
            $lines[] = '';
            if ($rows === []) {
                $lines[] = '_None._';
                $lines[] = '';

                continue;
            }
            $lines[] = '| SKU | Description | Stored | Proposed | Conf | Rule | Tag slug | Action |';
            $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($rows as $row) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s | %s → %s | %s |',
                    $row['sku'],
                    str_replace('|', '/', (string) $row['description']),
                    $row['stored_series'] ?? '—',
                    $row['proposed_series'] ?? '—',
                    $row['confidence'] ?? '—',
                    $row['rule_id'] ?? '—',
                    $row['stored_tag_slug'] ?? '—',
                    $row['proposed_tag_slug'] ?? '—',
                    $row['action'],
                );
            }
            $lines[] = '';
        }

        $lines[] = '## By proposed series shelf ('.count($result['by_series']).' buckets)';
        $lines[] = '';
        foreach ($result['by_series'] as $slug => $rows) {
            $erpName = ModelKitSeriesCatalog::erpSeriesForTagSlug($slug) ?? $slug;
            $lines[] = "### {$erpName} (`{$slug}`) — ".count($rows);
            $lines[] = '';
            foreach ($rows as $row) {
                $lines[] = '- '.$row['sku'].' — '.($row['stored_series'] ?? '(empty)').' → '.($row['proposed_series'] ?? '?').' ['.$row['action'].']';
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<int, string>  $tags
     */
    private static function seriesTagFromTags(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'mk:series:')) {
                return $tag;
            }
        }

        return null;
    }

    private function fandomWikiUrl(string $title): string
    {
        return 'https://gunpla.fandom.com/wiki/'.rawurlencode(str_replace(' ', '_', $title));
    }
}
