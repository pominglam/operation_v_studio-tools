<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Support\Products\ProductGradeResolver;
use App\Support\Products\ProductGunplaMgClassificationResolver;
use App\Support\Products\ProductModelKitSeriesResolver;
use App\Support\Products\ProductTaxonomyFields;
use Illuminate\Support\Facades\DB;

final class ProductModelKitErpClassifyService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTaxonomyDerivationService $derivation,
        private readonly ProductTaxonomyEvidenceEnrichmentService $enrichment,
        private readonly ProductGunplaMgClassificationResolver $mgClassification,
        private readonly ProductGradeResolver $gradeResolver,
        private readonly ProductModelKitSeriesResolver $seriesResolver,
    ) {}

    /**
     * @return array{scanned: int, updated: int, skipped: int}
     */
    public function classify(bool $dryRun = true): array
    {
        $scanned = 0;
        $updated = 0;
        $skipped = 0;

        Product::query()
            ->where('main_type', 'model kit')
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$scanned, &$updated, &$skipped, $dryRun): void {
                foreach ($products as $product) {
                    $scanned++;

                    $patch = $this->buildPatch($product);
                    if ($patch === []) {
                        $skipped++;

                        continue;
                    }

                    if ($dryRun) {
                        $updated++;

                        continue;
                    }

                    DB::transaction(function () use ($product, $patch): void {
                        $product->fill($patch);
                        $this->products->save($product);
                    });
                    $updated++;
                }
            });

        return compact('scanned', 'updated', 'skipped');
    }

    /**
     * @return array<string, string|null>
     */
    private function buildPatch(Product $product): array
    {
        $text = mb_strtoupper(implode(' ', array_filter([
            $product->sku,
            $product->description,
            $product->type,
            $product->brand,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));

        $proposal = $this->enrichment->enrich($product, $this->derivation->derive($product));
        $derived = ProductTaxonomyFields::normalize($proposal->values);

        /** @var array<string, string|null> $patch */
        $patch = [];

        foreach (['manufacturer', 'franchise', 'product_line', 'subline', 'grade', 'series', 'scale', 'accessory_kind'] as $field) {
            $current = $this->nullableString($product->getAttribute($field));
            $next = $derived[$field] ?? null;
            if ($current === null && is_string($next) && trim($next) !== '') {
                $patch[$field] = trim($next);
            }
        }

        if (($derived['department'] ?? null) === 'accessories' && ($derived['accessory_kind'] ?? null) !== null) {
            $currentDept = $this->nullableString($product->department);
            if ($currentDept === null || $currentDept === 'model kits') {
                $patch['department'] = 'accessories';
                $patch['grade'] = null;
            }
        }

        $series = $this->seriesResolver->resolve($product, $text);
        if ($this->nullableString($product->series) === null && $series !== null) {
            $patch['series'] = $series;
        }

        $mg = $this->mgClassification->classify((string) $product->description);
        if ($mg !== null) {
            foreach (['type', 'grade', 'subline'] as $field) {
                $current = $this->nullableString($product->getAttribute($field));
                $next = $mg[$field] ?? null;
                if ($next !== null && $current !== $next) {
                    $patch[$field] = $next;
                }
            }
            if ($this->nullableString($product->scale) === null) {
                $patch['scale'] = '1/100';
            }
        } elseif ($this->nullableString($product->grade) === null) {
            $grade = $this->gradeResolver->resolveFromProduct($product);
            if ($grade !== null) {
                $patch['grade'] = $grade;
            }
        }

        if (($derived['department'] ?? null) === 'model kits' && $this->nullableString($product->department) === null) {
            $patch['department'] = 'model kits';
        }

        if ($this->nullableString($product->subline) === null && ! isset($patch['subline'])) {
            $sublineFromType = $this->sublineFromType($product->type);
            if ($sublineFromType !== null) {
                $patch['subline'] = $sublineFromType;
            }
        }

        $patch = $this->applyKeroroGrade($product, $text, $patch);
        $patch = $this->applyProductLineInference($product, $text, $patch);

        return $patch;
    }

    /**
     * @param  array<string, string|null>  $patch
     * @return array<string, string|null>
     */
    private function applyKeroroGrade(Product $product, string $text, array $patch): array
    {
        if ($this->nullableString($product->grade) !== null || isset($patch['grade'])) {
            return $patch;
        }

        if (preg_match('/\b(?:KERORO|SGT\.?\s*FROG)\b/', $text) !== 1) {
            return $patch;
        }

        $patch['grade'] = 'Keroro';

        if ($this->nullableString($product->franchise) === null) {
            $patch['franchise'] = 'Sgt. Frog';
        }

        if ($this->nullableString($product->series) === null) {
            $patch['series'] = 'Keroro';
        }

        return $patch;
    }

    /**
     * @param  array<string, string|null>  $patch
     * @return array<string, string|null>
     */
    private function applyProductLineInference(Product $product, string $text, array $patch): array
    {
        if ($this->nullableString($product->product_line) !== null || isset($patch['product_line'])) {
            return $patch;
        }

        if (preg_match('/\bEUREKA SEVEN\b/', $text) === 1) {
            $patch['product_line'] = 'Eureka Seven';

            return $patch;
        }

        if (preg_match('/\b(?:KERORO|SGT\.?\s*FROG)\b/', $text) === 1) {
            return $patch;
        }

        if (preg_match('/\bUCHG\b/', $text) === 1) {
            $patch['product_line'] = 'Gunpla';

            return $patch;
        }

        $grade = $patch['grade'] ?? $this->nullableString($product->grade);
        if ($grade !== null && $this->isGunplaKitGrade($grade)) {
            $patch['product_line'] = 'Gunpla';
        }

        return $patch;
    }

    private function isGunplaKitGrade(string $grade): bool
    {
        return in_array(mb_strtoupper(trim($grade)), [
            'EG',
            'HG',
            'RG',
            'MG',
            'MGEX',
            'MGSD',
            'PG',
            'SD',
            'FM',
            'RE',
            'MEGA',
            'NG',
        ], true);
    }

    private function sublineFromType(mixed $type): ?string
    {
        return match (mb_strtoupper(trim((string) $type))) {
            'HGUC' => 'HGUC',
            'HGCE' => 'HGCE',
            'HGAC' => 'HGAC',
            'HGAW' => 'HGAW',
            'HGFC' => 'HGFC',
            'HGBF' => 'HGBF',
            'HGBD' => 'HGBD',
            'HGIBO', 'ORPHANS HG' => 'HGIBO',
            'EX-STANDARD' => 'EX-Standard',
            'SDW' => 'SDW',
            'SDBF' => 'SDBF',
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
