<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Support\Products\ProductGunplaMgClassificationResolver;
use Illuminate\Support\Facades\DB;

final class ProductMgClassificationCorrectionService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductGunplaMgClassificationResolver $mgClassification,
    ) {}

    /**
     * @return array{scanned: int, updated: int, skipped: int, changes: array<int, array{sku: string, before: array<string, string|null>, after: array<string, string|null>}>}
     */
    public function correct(bool $dryRun = true, bool $includeThirdParty = false): array
    {
        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        /** @var array<int, array{sku: string, before: array<string, string|null>, after: array<string, string|null>}> $changes */
        $changes = [];

        Product::query()
            ->where('main_type', 'model kit')
            ->where(function ($query): void {
                $query->whereIn('type', ['MG', 'MGEX', 'MGSD', 'Others'])
                    ->orWhere('description', 'like', '%MG%')
                    ->orWhere('description', 'like', '%Master Grade%');
            })
            ->orderBy('id')
            ->chunkById(100, function ($products) use (
                &$scanned,
                &$updated,
                &$skipped,
                &$changes,
                $dryRun,
                $includeThirdParty,
            ): void {
                foreach ($products as $product) {
                    $scanned++;

                    if (! $includeThirdParty && mb_strtoupper(trim((string) $product->type)) === '3RDPARTY') {
                        $skipped++;

                        continue;
                    }

                    $classified = $this->mgClassification->classify((string) $product->description);
                    if ($classified === null) {
                        $skipped++;

                        continue;
                    }

                    $before = [
                        'type' => $this->nullableString($product->type),
                        'grade' => $this->nullableString($product->grade),
                        'subline' => $this->nullableString($product->subline),
                        'scale' => $this->nullableString($product->scale),
                    ];

                    $after = [
                        'type' => $classified['type'],
                        'grade' => $classified['grade'],
                        'subline' => $classified['subline'],
                        'scale' => $before['scale'],
                    ];
                    if ($after['scale'] === null) {
                        $after['scale'] = '1/100';
                    }

                    if (! $this->needsUpdate($before, $after)) {
                        continue;
                    }

                    $changes[] = [
                        'sku' => (string) $product->sku,
                        'before' => $before,
                        'after' => $after,
                    ];

                    if ($dryRun) {
                        $updated++;

                        continue;
                    }

                    DB::transaction(function () use ($product, $after): void {
                        $product->type = $after['type'];
                        $product->grade = $after['grade'];
                        $product->subline = $after['subline'];
                        if ($after['scale'] !== null) {
                            $product->scale = $after['scale'];
                        }
                        $this->products->save($product);
                    });

                    $updated++;
                }
            });

        return compact('scanned', 'updated', 'skipped', 'changes');
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     */
    private function needsUpdate(array $before, array $after): bool
    {
        foreach (['type', 'grade', 'subline', 'scale'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
