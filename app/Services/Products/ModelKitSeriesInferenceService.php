<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTOs\Products\ModelKitSeriesProposal;
use App\Models\Product;
use App\Support\Products\ModelKitSeriesCatalog;
use App\Support\Products\Storefront\StorefrontTag;

final class ModelKitSeriesInferenceService
{
    public function infer(Product $product, string $searchableText): ?ModelKitSeriesProposal
    {
        $text = mb_strtoupper($searchableText);

        if ($this->isLockedFranchiseLine($product)) {
            return $this->inferForLockedLine($product, $text);
        }

        $sublineProposal = $this->inferFromSubline($product, highConfidenceOnly: true);
        if ($sublineProposal !== null) {
            return $sublineProposal;
        }

        foreach (ModelKitSeriesCatalog::inferenceRules() as $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (! $this->matches($text, $pattern)) {
                    continue;
                }

                $erpSeries = ModelKitSeriesCatalog::erpSeriesForTagSlug($rule['tagSlug']);
                if ($erpSeries === null) {
                    continue;
                }

                return new ModelKitSeriesProposal(
                    erpSeries: $erpSeries,
                    tagSlug: $rule['tagSlug'],
                    ruleId: $rule['ruleId'],
                    confidence: $rule['confidence'],
                    evidence: 'title:'.$pattern,
                );
            }
        }

        return $this->inferFromSubline($product, highConfidenceOnly: false);
    }

    private function inferFromSubline(Product $product, bool $highConfidenceOnly): ?ModelKitSeriesProposal
    {
        $subline = mb_strtolower(trim((string) ($product->subline ?? '')));
        $hint = ModelKitSeriesCatalog::sublineSeriesHints()[$subline] ?? null;
        if ($hint === null) {
            return null;
        }

        if ($highConfidenceOnly && $hint['confidence'] !== 'high') {
            return null;
        }

        $erpSeries = ModelKitSeriesCatalog::erpSeriesForTagSlug($hint['tagSlug']);
        if ($erpSeries === null) {
            return null;
        }

        return new ModelKitSeriesProposal(
            erpSeries: $erpSeries,
            tagSlug: $hint['tagSlug'],
            ruleId: 'subline:'.$subline,
            confidence: $hint['confidence'],
            evidence: 'subline:'.$subline,
        );
    }

    public function searchableText(Product $product): string
    {
        return mb_strtoupper(implode(' ', array_filter([
            $product->sku,
            $product->description,
            $product->type,
            $product->brand,
            $product->vendor,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
    }

    public function normalizeStoredSeries(?string $series): ?string
    {
        if ($series === null || trim($series) === '') {
            return null;
        }

        $slug = StorefrontTag::slugify($series);
        if ($slug === null) {
            return trim($series);
        }

        return ModelKitSeriesCatalog::erpSeriesForTagSlug($slug) ?? trim($series);
    }

    public function tagSlugForErpSeries(?string $series): ?string
    {
        if ($series === null || trim($series) === '') {
            return null;
        }

        $slug = StorefrontTag::slugify($series);
        if ($slug === null) {
            return null;
        }

        if (ModelKitSeriesCatalog::erpSeriesForTagSlug($slug) !== null) {
            return $slug;
        }

        return $slug;
    }

    private function isLockedFranchiseLine(Product $product): bool
    {
        $line = mb_strtolower(trim((string) ($product->product_line ?? '')));
        $franchise = mb_strtolower(trim((string) ($product->franchise ?? '')));

        return str_contains($line, 'super robot wars')
            || str_contains($franchise, 'super robot wars');
    }

    private function inferForLockedLine(Product $product, string $text): ?ModelKitSeriesProposal
    {
        if (str_contains($text, 'SUPER ROBOT WARS') || preg_match('/\bSRW\b/', $text) === 1) {
            $erpSeries = ModelKitSeriesCatalog::erpSeriesForTagSlug('super_robot_wars');
            if ($erpSeries !== null) {
                return new ModelKitSeriesProposal(
                    erpSeries: $erpSeries,
                    tagSlug: 'super_robot_wars',
                    ruleId: 'srw_line',
                    confidence: 'high',
                    evidence: 'product_line:super_robot_wars',
                );
            }
        }

        return null;
    }

    private function matches(string $text, string $pattern): bool
    {
        $isRegex = str_starts_with($pattern, '\\')
            || str_contains($pattern, '\\b')
            || str_contains($pattern, '[')
            || str_contains($pattern, '(?:');

        if ($isRegex) {
            return preg_match('/'.$pattern.'/i', $text) === 1;
        }

        return str_contains($text, mb_strtoupper($pattern));
    }
}
