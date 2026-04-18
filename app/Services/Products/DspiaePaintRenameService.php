<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;

final class DspiaePaintRenameService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * Renames DSPIAE "Water-based pre-mixed paint – Base Color:" products to:
     *   "{SKU} - {Paint Name} - DSPIAE - {volume}"
     *
     * @return array{
     *   matched:int,
     *   changed:int,
     *   preview:array<int, array{sku:string, old:string, new:string}>
     * }
     */
    public function rename(bool $apply, int $previewLimit = 25): array
    {
        $matched = 0;
        $changed = 0;
        $preview = [];

        /** @var \Illuminate\Support\Collection<int, Product> $all */
        $all = $this->products->listAll();

        foreach ($all as $p) {
            $old = (string) $p->description;
            if (! $this->isTarget($old)) {
                continue;
            }

            $matched++;
            $new = $this->buildNewName($p->sku, $old);

            if ($new === $old) {
                continue;
            }

            if (count($preview) < $previewLimit) {
                $preview[] = [
                    'sku' => $p->sku,
                    'old' => $old,
                    'new' => $new,
                ];
            }

            if ($apply) {
                $p->description = $new;
                $this->products->save($p);
            }

            $changed++;
        }

        return [
            'matched' => $matched,
            'changed' => $changed,
            'preview' => $preview,
        ];
    }

    private function isTarget(string $description): bool
    {
        return (bool) preg_match('/^Water-based pre-mixed paint\s*[-–]\s*Base Color:\s*/u', $description);
    }

    private function buildNewName(string $sku, string $description): string
    {
        // normalize en-dash to hyphen for easier parsing
        $description = str_replace("\u{2013}", '-', $description);

        $paintName = $description;
        $parts = preg_split('/Base Color:\s*/u', $description, 2);
        if (is_array($parts) && count($parts) === 2) {
            $paintName = $parts[1];
        }

        // Drop trailing volume like "- 50ML"
        $paintName = preg_replace('/\s*-\s*\d+\s*ML\b/u', '', $paintName) ?? $paintName;
        $paintName = trim(preg_replace('/\s+/u', ' ', $paintName) ?? $paintName);

        $volume = '';
        if (preg_match('/(\d+)\s*ML\b/u', $description, $m) === 1) {
            $volume = $m[1].'ml';
        }

        $new = "{$sku} - {$paintName} - DSPIAE - {$volume}";

        return trim($new);
    }
}
