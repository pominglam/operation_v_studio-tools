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

        foreach (['manufacturer', 'franchise', 'product_line', 'subline', 'grade', 'series', 'scale'] as $field) {
            $current = $this->nullableString($product->getAttribute($field));
            $next = $derived[$field] ?? null;
            if ($current === null && is_string($next) && trim($next) !== '') {
                $patch[$field] = trim($next);
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

        return $patch;
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
