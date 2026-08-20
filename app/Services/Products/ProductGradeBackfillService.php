<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Support\Products\ProductGradeResolver;
use Illuminate\Support\Collection;

final class ProductGradeBackfillService
{
    public function __construct(
        private readonly ProductGradeResolver $gradeResolver,
    ) {}

    /**
     * @param  list<string>|null  $skus
     * @return array{matched:int,updated:int,rows: list<array{sku:string,from:?string,to:string}>}
     */
    public function backfill(?array $skus = null, bool $dryRun = false): array
    {
        $query = Product::query()
            ->where('main_type', 'model kit')
            ->whereNull('archived_at')
            ->orderBy('sku');

        if ($skus !== null && $skus !== []) {
            $query->whereIn('sku', $skus);
        }

        /** @var Collection<int, Product> $products */
        $products = $query->get();

        $rows = [];
        $updated = 0;

        foreach ($products as $product) {
            if (! $this->gradeResolver->needsCorrection($product)) {
                continue;
            }

            $resolved = $this->gradeResolver->resolveFromProduct($product);
            if ($resolved === null) {
                continue;
            }

            $rows[] = [
                'sku' => (string) $product->sku,
                'from' => $product->grade,
                'to' => $resolved,
            ];

            if ($dryRun) {
                continue;
            }

            $product->grade = $resolved;
            $scale = $this->gradeResolver->scaleForGrade($resolved);
            if ($scale !== null && trim((string) ($product->scale ?? '')) === '') {
                $product->scale = $scale;
            }
            $product->save();
            $updated++;
        }

        return [
            'matched' => count($rows),
            'updated' => $updated,
            'rows' => $rows,
        ];
    }
}
