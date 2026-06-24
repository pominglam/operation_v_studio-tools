<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProductsStorefrontAuditCommand extends Command
{
    protected $signature = 'products:storefront-audit
        {--orphans-only : List only unclassified non-model-kit products}
        {--gaps-only : List only classified products missing ts:dept on Shopify mirror}
        {--all : Include unpublished / unmirrored ERP rows}';

    protected $description = 'Audit storefront taxonomy orphans and Shopify ts:dept tag gaps (excludes model kits).';

    public function handle(
        ProductRepository $products,
        ProductStorefrontClassifier $classifier,
    ): int {
        $orphansOnly = (bool) $this->option('orphans-only');
        $gapsOnly = (bool) $this->option('gaps-only');
        $includeAll = (bool) $this->option('all');

        if ($orphansOnly && $gapsOnly) {
            $this->error('Use only one of --orphans-only or --gaps-only.');

            return self::FAILURE;
        }

        $mirrorTagsBySku = $this->mirrorTagsBySku();
        $orphanRows = [];
        $gapRows = [];

        foreach ($products->listForExport(null, [], null, 'asc') as $product) {
            if ($product->archived_at !== null) {
                continue;
            }

            if (! $includeAll && ! $this->isInScope($product, $mirrorTagsBySku)) {
                continue;
            }

            if ($this->isModelKit($product)) {
                continue;
            }

            $classification = $classifier->classify($product);
            $sku = (string) $product->sku;
            $mirrorTags = $mirrorTagsBySku[strtoupper($sku)] ?? [];

            if ($classification->department === null) {
                $orphanRows[] = [
                    $sku,
                    (string) ($product->description ?? ''),
                    trim((string) ($product->main_type ?? '')) ?: '-',
                    trim((string) ($product->type ?? '')) ?: '-',
                    implode(', ', $mirrorTags) ?: '-',
                ];

                continue;
            }

            $deptTag = StorefrontTag::deptTagForDepartment($classification->department);
            if ($deptTag === null) {
                continue;
            }

            if (! in_array($deptTag, $mirrorTags, true)) {
                $gapRows[] = [
                    $sku,
                    $classification->department,
                    $deptTag,
                    implode(', ', $classification->shopifyTags) ?: '-',
                    implode(', ', $mirrorTags) ?: '-',
                ];
            }
        }

        if (! $gapsOnly) {
            $this->info('Orphans (non-model-kit ERP products with no ts department): '.count($orphanRows));
            if ($orphanRows !== []) {
                $this->table(
                    ['SKU', 'Description', 'main_type', 'type', 'Shopify mirror tags'],
                    $orphanRows,
                );
            }
        }

        if (! $orphansOnly) {
            $this->info('Tag gaps (classified in ERP but missing ts:dept on Shopify mirror): '.count($gapRows));
            if ($gapRows !== []) {
                $this->table(
                    ['SKU', 'ERP dept', 'Missing tag', 'Expected push tags', 'Mirror tags'],
                    $gapRows,
                );
            }
        }

        if ($orphanRows === [] && $gapRows === []) {
            $this->info('No orphans or tag gaps found in scope.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<int, string>>  $mirrorTagsBySku
     */
    private function isInScope(Product $product, array $mirrorTagsBySku): bool
    {
        if ((bool) $product->published_on_shopify) {
            return true;
        }

        return isset($mirrorTagsBySku[strtoupper(trim((string) $product->sku))]);
    }

    private function isModelKit(Product $product): bool
    {
        return strtolower(trim((string) ($product->main_type ?? ''))) === 'model kit';
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function mirrorTagsBySku(): array
    {
        $rows = DB::table('shopify_product_variants as spv')
            ->join('shopify_products as sp', 'sp.gid', '=', 'spv.product_gid')
            ->whereNotNull('spv.sku')
            ->select(['spv.sku', 'sp.payload_json'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $sku = strtoupper(trim((string) ($row->sku ?? '')));
            if ($sku === '') {
                continue;
            }

            $out[$sku] = $this->parseTags($row->payload_json ?? null);
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function parseTags(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return [];
        }

        $tags = $payload['tags'] ?? [];
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $tag): string => trim((string) $tag),
            $tags,
        ), static fn (string $tag): bool => $tag !== ''));
    }
}
