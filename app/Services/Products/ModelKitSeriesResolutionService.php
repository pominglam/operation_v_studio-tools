<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTOs\Products\ModelKitSeriesResolution;
use App\Models\Product;
use App\Models\ProductExternalContent;
use App\Services\Products\Bandai\BandaiContentSyncService;
use App\Support\Products\ModelKitAccessoryKind;
use App\Support\Products\ModelKitSeriesCatalog;

final class ModelKitSeriesResolutionService
{
    public function __construct(
        private readonly ModelKitSeriesInferenceService $inference,
        private readonly GundamFandomSeriesLookupService $fandom,
        private readonly PlamodSeriesLookupService $plamod,
    ) {}

    /**
     * @param  list<Product>  $products
     * @return array<string, ModelKitSeriesResolution>
     */
    public function resolveBatch(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $plamodIndex = $this->plamod->seriesIndex();
        $productIds = array_values(array_filter(array_map(
            static fn (Product $product): ?int => $product->id,
            $products,
        )));
        $bandaiByProductId = $this->loadBandaiSeriesByProductId($productIds);

        /** @var array<string, ModelKitSeriesResolution> $out */
        $out = [];

        foreach ($products as $product) {
            if (! $this->expectsSeriesResolution($product)) {
                continue;
            }

            $sku = (string) $product->sku;
            $out[$sku] = $this->resolveOne($product, $plamodIndex, $bandaiByProductId);
        }

        return $out;
    }

    public function resolveForProduct(Product $product): ?ModelKitSeriesResolution
    {
        if (! $this->expectsSeriesResolution($product)) {
            return null;
        }

        $bandaiByProductId = $this->loadBandaiSeriesByProductId([(int) $product->id]);

        return $this->resolveOne($product, $this->plamod->seriesIndex(), $bandaiByProductId);
    }

    /**
     * @param  array<string, array{raw_series: string, erp_series: string, source: string, plamod_pdp_url: ?string}>  $plamodIndex
     * @param  array<int, array{erp_series: ?string, source_url: ?string}>  $bandaiByProductId
     */
    private function resolveOne(
        Product $product,
        array $plamodIndex,
        array $bandaiByProductId,
    ): ModelKitSeriesResolution {
        $sku = (string) $product->sku;
        $text = $this->inference->searchableText($product);
        $erp = $this->displaySeries($product->series);

        $rulesProposal = $this->inference->infer($product, $text);
        $rules = $rulesProposal?->erpSeries;

        $plamodMeta = $plamodIndex[$sku] ?? null;
        $plamod = $plamodMeta['erp_series'] ?? null;
        $plamodUrl = $plamodMeta['plamod_pdp_url'] ?? null;

        $wikiMeta = $this->fandom->lookupCachedOnly((string) $product->sku);
        $wiki = is_array($wikiMeta) ? ($wikiMeta['erp_series'] ?? null) : null;
        $wikiTitle = is_array($wikiMeta) ? ($wikiMeta['wiki_title'] ?? null) : null;
        $wikiUrl = is_string($wikiTitle) && $wikiTitle !== ''
            ? $this->fandom->wikiPageUrl($wikiTitle)
            : null;

        $bandaiRow = $bandaiByProductId[(int) $product->id] ?? null;
        $bandai = $bandaiRow['erp_series'] ?? null;
        $bandaiUrl = $bandaiRow['source_url'] ?? null;

        [$final, $confidence, $reason] = $this->decide(
            $product,
            $erp,
            $rules,
            $plamod,
            $wiki,
            $bandai,
            $rulesProposal?->confidence ?? null,
        );

        return new ModelKitSeriesResolution(
            sku: $sku,
            erp: $erp,
            plamod: $this->displaySeries($plamod),
            rules: $this->displaySeries($rules),
            wiki: $this->displaySeries($wiki),
            bandai: $this->displaySeries($bandai),
            finalDecision: $this->displaySeries($final),
            confidence: $confidence,
            plamodUrl: $plamodUrl,
            wikiUrl: $wikiUrl,
            bandaiUrl: $bandaiUrl,
            decisionReason: $reason,
        );
    }

    /**
     * @return array{0: ?string, 1: string, 2: ?string}
     */
    private function decide(
        Product $product,
        ?string $erp,
        ?string $rules,
        ?string $plamod,
        ?string $wiki,
        ?string $bandai,
        ?string $rulesConfidence,
    ): array {
        if ($this->isBuildFightersProductLine($product)) {
            $buildFighters = ModelKitSeriesCatalog::erpSeriesForTagSlug('gundam_build_fighters');

            return [$buildFighters, 'high', 'line:hgbf'];
        }

        if ($rules !== null && $plamod !== null && $rules === $plamod) {
            $final = $this->applyEndlessWaltzGuard($product, $rules, $rules);
            if ($this->seriesInCatalog($final)) {
                return [$final, 'high', 'rules-plamod-agree'];
            }
        }

        if ($erp !== null && $plamod !== null && $erp === $plamod) {
            return [$erp, 'high', 'erp-plamod-agree'];
        }

        if ($bandai !== null && $rules !== null && $bandai === $rules) {
            $final = $this->applyEndlessWaltzGuard($product, $bandai, $rules);
            if ($this->seriesInCatalog($final)) {
                return [$final, 'high', 'bandai-rules-agree'];
            }
        }

        $wikiVote = $this->wikiUsableForVote($rules, $plamod, $bandai, $wiki) ? $wiki : null;

        /** @var array<string, int> $votes */
        $votes = [];
        $this->addVote($votes, $bandai, 4);
        $this->addVote($votes, $rules, 3);
        $this->addVote($votes, $plamod, 2);
        $this->addVote($votes, $wikiVote, 1);

        if ($votes === []) {
            return [$erp, 'review', 'no-sources'];
        }

        arsort($votes);
        $topSeries = array_key_first($votes);
        $topWeight = $topSeries !== null ? $votes[$topSeries] : 0;

        $leaders = array_keys(array_filter(
            $votes,
            static fn (int $weight): bool => $weight === $topWeight,
        ));

        if (count($leaders) > 1) {
            return [$erp ?? $topSeries, 'review', 'split-sources'];
        }

        $final = $topSeries;
        $final = $this->applyEndlessWaltzGuard($product, $final, $rules);

        if (! $this->seriesInCatalog($final)) {
            return [$final, 'review', 'unknown-shelf'];
        }

        $agreeing = $this->countAgreeingSources($final, $rules, $plamod, $wikiVote, $bandai);
        if ($agreeing >= 2 && ($bandai === null || $bandai === $final)) {
            return [$final, 'high', 'multi-source-agree'];
        }

        if ($bandai !== null && $bandai === $final) {
            return [$final, 'high', 'bandai'];
        }

        if ($rules !== null && $rules === $final && $rulesConfidence === 'high') {
            return [$final, 'medium', 'rules-high'];
        }

        if ($erp !== null && $erp === $final) {
            return [$final, 'medium', 'matches-erp'];
        }

        return [$final, 'review', 'single-source'];
    }

    /** @param array<string, int> $votes */
    private function addVote(array &$votes, ?string $series, int $weight): void
    {
        if ($series === null || trim($series) === '') {
            return;
        }

        $key = trim($series);
        $votes[$key] = ($votes[$key] ?? 0) + $weight;
    }

    private function wikiUsableForVote(?string $rules, ?string $plamod, ?string $bandai, ?string $wiki): bool
    {
        if ($wiki === null) {
            return false;
        }

        $core = array_values(array_filter([$rules, $plamod, $bandai], static fn (?string $v): bool => $v !== null && trim($v) !== ''));
        if ($core === []) {
            return true;
        }

        foreach ($core as $value) {
            if ($value === $wiki) {
                return true;
            }
        }

        return false;
    }

    private function countAgreeingSources(
        string $final,
        ?string $rules,
        ?string $plamod,
        ?string $wiki,
        ?string $bandai,
    ): int {
        $count = 0;
        foreach ([$rules, $plamod, $wiki, $bandai] as $value) {
            if ($value !== null && $value === $final) {
                $count++;
            }
        }

        return $count;
    }

    private function applyEndlessWaltzGuard(Product $product, ?string $final, ?string $rules): ?string
    {
        $endlessWaltz = ModelKitSeriesCatalog::erpSeriesForTagSlug('gundam_wing__endless_waltz');
        if ($endlessWaltz === null || $final !== $endlessWaltz) {
            return $final;
        }

        if ($this->productNameIndicatesEndlessWaltz($product)) {
            return $final;
        }

        if ($rules !== null && $rules !== $endlessWaltz) {
            return $rules;
        }

        return ModelKitSeriesCatalog::erpSeriesForTagSlug('gundam_wing') ?? $final;
    }

    private function seriesInCatalog(?string $series): bool
    {
        if ($series === null || trim($series) === '') {
            return false;
        }

        $slug = $this->inference->tagSlugForErpSeries($series);

        return $slug !== null && ModelKitSeriesCatalog::erpSeriesForTagSlug($slug) !== null;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array{erp_series: ?string, source_url: ?string}>
     */
    private function loadBandaiSeriesByProductId(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var array<int, array{erp_series: ?string, source_url: ?string}> $out */
        $out = [];

        ProductExternalContent::query()
            ->where('source', BandaiContentSyncService::SOURCE)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'source_url', 'attributes_json'])
            ->each(function (ProductExternalContent $row) use (&$out): void {
                $attrs = is_array($row->attributes_json) ? $row->attributes_json : [];
                $raw = isset($attrs['bandai_series']) && is_string($attrs['bandai_series'])
                    ? trim($attrs['bandai_series'])
                    : '';
                $mapped = $raw !== ''
                    ? ModelKitSeriesCatalog::erpSeriesForFandomName($raw) ?? $raw
                    : null;

                $out[(int) $row->product_id] = [
                    'erp_series' => $mapped,
                    'source_url' => $row->source_url,
                ];
            });

        return $out;
    }

    private function expectsSeriesResolution(Product $product): bool
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

        $franchise = mb_strtolower(trim((string) ($product->franchise ?? '')));
        if ($franchise === 'gundam' || $line === 'gunpla') {
            return true;
        }

        $nonGundamSeriesFranchises = [
            'patlabor', 'macross', 'mazinger', 'getter robo', 'kotetsu jeeg',
            'armored core', 'eureka seven', 'doraemon', 'sakura wars', 'super robot wars', 'evangelion',
        ];
        foreach ($nonGundamSeriesFranchises as $needle) {
            if (str_contains($franchise, $needle)) {
                return true;
            }
        }

        return $this->displaySeries($product->series) !== null;
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

        return str_contains($name, 'ZERO EW') || str_contains($name, 'WING ZERO EW');
    }

    private function displaySeries(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
