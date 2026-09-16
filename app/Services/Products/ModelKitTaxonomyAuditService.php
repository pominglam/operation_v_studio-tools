<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Support\Products\AgentTestSkuGuard;
use App\Support\Products\ModelKitAccessoryKind;
use App\Support\Products\ProductTaxonomyFields;
use App\Support\Products\Storefront\ModelKitStorefrontTagResolver;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Database\Eloquent\Builder;

final class ModelKitTaxonomyAuditService
{
    /** @var list<string> */
    private const TAXONOMY_FIELDS = [
        'department', 'manufacturer', 'franchise', 'product_line', 'subline', 'grade', 'series', 'scale', 'accessory_kind',
    ];

    public function __construct(
        private readonly ProductTaxonomyDerivationService $derivation,
        private readonly ProductTaxonomyEvidenceEnrichmentService $enrichment,
        private readonly ModelKitStorefrontTagResolver $tagResolver,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     with_storefront_tags: int,
     *     summary: array<string, int>,
     *     findings: array<string, list<array<string, mixed>>>
     * }
     */
    public function audit(bool $activeOnly = true): array
    {
        /** @var array<string, list<array<string, mixed>>> */
        $findings = [
            'erp_vs_derivation' => [],
            'missing_grade' => [],
            'missing_series' => [],
            'missing_tags' => [],
            'unexpected_tags' => [],
            'filter_grade_mismatch' => [],
            'accessory_mismatch' => [],
            'questions' => [],
        ];

        $scanned = 0;
        $withTags = 0;

        $query = self::modelKitScope(Product::query())->orderBy('sku');
        if ($activeOnly) {
            $query->whereNull('archived_at');
        }

        $query->chunkById(100, function ($products) use (&$scanned, &$withTags, &$findings): void {
            foreach ($products as $product) {
                if (AgentTestSkuGuard::isAgentTestSku((string) $product->sku)) {
                    continue;
                }

                $scanned++;
                $proposal = $this->enrichment->enrich($product, $this->derivation->derive($product));
                $derived = ProductTaxonomyFields::normalize($proposal->values);
                $tags = $this->tagResolver->tagsForProduct($product);

                if ($tags !== []) {
                    $withTags++;
                }

                foreach (self::TAXONOMY_FIELDS as $field) {
                    $current = self::normalize($product->getAttribute($field));
                    $proposed = self::normalize($derived[$field] ?? null);
                    if ($proposed === null || $proposed === $current) {
                        continue;
                    }
                    if (self::isCosmeticFieldChange($field, $current, $proposed)) {
                        continue;
                    }
                    if ($current === null && $proposed !== null) {
                        $findings['erp_vs_derivation'][] = self::row($product, $field, $current, $proposed, 'fill_missing', null);
                    } elseif (self::isLikelyBadDerivation($field, $current, $proposed, $product)) {
                        $findings['questions'][] = self::row($product, $field, $current, $proposed, 'derivation_disagrees_keep_erp', null);
                    } else {
                        $findings['erp_vs_derivation'][] = self::row($product, $field, $current, $proposed, 'change', null);
                    }
                }

                $gradeSlug = self::gradeSlugFromTags($tags, $product);
                if ($gradeSlug === null && ! self::isAccessoryLine($tags) && self::expectsGunplaGrade($product)) {
                    $findings['missing_grade'][] = self::row($product, 'grade', self::normalize($product->grade), null, 'no_mk_grade_tag', null);
                }

                if ($gradeSlug === 're' && ! in_array(StorefrontTag::mkGrade('re'), $tags, true)) {
                    $findings['filter_grade_mismatch'][] = self::row($product, 'filter_key', 're_100', 're', 'erp_grade_re_maps_to_filter_re_100', null);
                }

                $franchise = self::normalize($product->franchise);
                if ($franchise === 'Gundam' && self::normalize($product->series) === null && ! self::isAccessoryLine($tags)) {
                    $findings['missing_series'][] = self::row($product, 'series', null, self::normalize($derived['series'] ?? null), 'gundam_without_series', null);
                }

                $accessoryKind = self::normalize($product->accessory_kind);
                $hasOptionParts = in_array(StorefrontTag::MK_LINE_GUNPLA_OPTION_PARTS, $tags, true);
                $hasActionBase = in_array(StorefrontTag::MK_LINE_ACTION_BASE, $tags, true);
                if ($accessoryKind === 'gunpla option parts' && ! $hasOptionParts) {
                    $findings['accessory_mismatch'][] = self::row($product, 'accessory_kind', $accessoryKind, 'missing mk:line:gunpla_option_parts', 'reclassify_or_tag', null);
                }
                if ($accessoryKind === 'action base' && ! $hasActionBase) {
                    $findings['accessory_mismatch'][] = self::row($product, 'accessory_kind', $accessoryKind, 'missing mk:line:action_base', 'reclassify_or_tag', null);
                }
            }
        });

        $summary = array_map(static fn (array $rows): int => count($rows), $findings);

        return [
            'scanned' => $scanned,
            'with_storefront_tags' => $withTags,
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $findings
     */
    /**
     * @param  array{scanned: int, with_storefront_tags: int, summary: array<string, int>, findings: array<string, list<array<string, mixed>>>}  $result
     * @return array{md: string, json: string}
     */
    public function writeReports(array $result): array
    {
        $mdPath = storage_path('app/model-kit-taxonomy-audit.md');
        $jsonPath = storage_path('app/model-kit-taxonomy-audit.json');

        file_put_contents($mdPath, self::renderMarkdown($result));
        file_put_contents($jsonPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'scanned' => $result['scanned'],
            'with_storefront_tags' => $result['with_storefront_tags'],
            'summary' => $result['summary'],
            'findings' => $result['findings'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['md' => $mdPath, 'json' => $jsonPath];
    }

    public static function modelKitScope(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('main_type', 'model kit')
                ->orWhere('type', 'KUN DX')
                ->orWhere('type', 'ACTION BASE')
                ->orWhere('accessory_kind', ModelKitAccessoryKind::DISPLAY_STAND)
                ->orWhere('type', 'OPTION PARTS SET')
                ->orWhere('sku', 'like', 'BPHD-%')
                ->orWhere('sku', 'like', 'OP-%')
                ->orWhere('sku', 'like', 'WAVOP-%')
                ->orWhere(function (Builder $query): void {
                    $query->whereIn('accessory_kind', [
                        ModelKitAccessoryKind::OPTION_PARTS,
                        ModelKitAccessoryKind::DETAIL_PARTS,
                    ])->whereIn('product_line', ['Gunpla', 'Builders Parts HD', 'Option System']);
                });
        });
    }

    private static function expectsGunplaGrade(Product $product): bool
    {
        if (self::normalize($product->department) !== 'model kits' && self::normalize($product->department) !== null) {
            return false;
        }

        $line = mb_strtolower((string) ($product->product_line ?? ''));
        if ($line === '' || $line === 'gunpla') {
            return true;
        }

        $noGradeLines = [
            '30 minutes missions', '30 minutes sisters', '30 minutes fantasy',
            'pokémon plamo collection', 'moderoid', 'stedi markers', 'dspiae markers',
            'action base', 'builders parts hd', 'option system', 'mechatrowego', 'snaa',
        ];

        foreach ($noGradeLines as $prefix) {
            if (str_starts_with($line, $prefix) || $line === $prefix) {
                return false;
            }
        }

        return true;
    }

    private static function isCosmeticFieldChange(string $field, ?string $current, ?string $proposed): bool
    {
        return $field === 'manufacturer'
            && mb_strtolower((string) $current) === mb_strtolower((string) $proposed);
    }

    private static function isLikelyBadDerivation(string $field, ?string $current, ?string $proposed, Product $product): bool
    {
        if ($field === 'franchise' && $current !== null && $proposed === 'Gundam') {
            $franchises = ['mazinger', 'getter robo', 'kotetsu jeeg', 'evangelion', 'patlabor', 'macross', 'gurren lagann', 'pokémon', 'pokemon'];
            $currentLower = mb_strtolower((string) $current);
            foreach ($franchises as $franchise) {
                if (str_contains($currentLower, $franchise)) {
                    return true;
                }
            }
        }

        if ($field === 'department' && $current === 'accessories' && $proposed === 'model kits') {
            return str_contains(mb_strtolower((string) $product->description), 'led unit');
        }

        return false;
    }

    /**
     * @param  array{scanned: int, with_storefront_tags: int, summary: array<string, int>, findings: array<string, list<array<string, mixed>>>}  $result
     */
    private static function renderMarkdown(array $result): string
    {
        $findings = $result['findings'];
        $lines = [
            '# Model kit taxonomy audit',
            '',
            'Generated: '.now()->toIso8601String(),
            '',
            "- Scanned: **{$result['scanned']}** active ERP products (same scope as `products:push-model-kit-tags`)",
            "- With storefront mk:* tags from resolver: **{$result['with_storefront_tags']}**",
            '',
            'See `docs/requirements/model-kit-taxonomy-audit.md` for workflow and business rules.',
            '',
        ];

        foreach ([
            'erp_vs_derivation' => 'Suggested ERP changes (derivation vs stored)',
            'missing_grade' => 'Missing mk:grade tag (Gunpla-grade products only)',
            'accessory_mismatch' => 'Accessory kind vs storefront line tag mismatch',
            'filter_grade_mismatch' => 'Grade slug vs hub filter key mismatch',
            'missing_series' => 'Gundam products missing series',
            'questions' => 'Needs operator decision (derivation likely wrong)',
        ] as $key => $title) {
            $rows = $findings[$key] ?? [];
            $lines[] = "## {$title} (".count($rows).')';
            $lines[] = '';
            if ($rows === []) {
                $lines[] = '_None._';
                $lines[] = '';

                continue;
            }
            $lines[] = '| SKU | Description | Field | Current | Proposed | Action |';
            $lines[] = '| --- | --- | --- | --- | --- | --- |';
            foreach ($rows as $row) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s |',
                    $row['sku'],
                    str_replace('|', '/', (string) $row['description']),
                    $row['field'],
                    $row['current'] ?? '—',
                    $row['proposed'] ?? '—',
                    $row['action'],
                );
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
     * @return array<string, mixed>
     */
    private static function row(Product $product, string $field, ?string $current, ?string $proposed, string $action, ?float $confidence): array
    {
        return [
            'sku' => $product->sku,
            'description' => mb_substr((string) $product->description, 0, 80),
            'field' => $field,
            'current' => $current,
            'proposed' => $proposed,
            'action' => $action,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  array<int, string>  $tags
     */
    private static function gradeSlugFromTags(array $tags, Product $product): ?string
    {
        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'mk:grade:')) {
                return substr($tag, strlen('mk:grade:'));
            }
        }

        return StorefrontTag::slugify(is_string($product->grade) ? $product->grade : null);
    }

    /**
     * @param  array<int, string>  $tags
     */
    private static function isAccessoryLine(array $tags): bool
    {
        return in_array(StorefrontTag::MK_LINE_GUNPLA_OPTION_PARTS, $tags, true)
            || in_array(StorefrontTag::MK_LINE_ACTION_BASE, $tags, true);
    }
}
