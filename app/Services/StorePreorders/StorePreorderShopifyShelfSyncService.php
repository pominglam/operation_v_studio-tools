<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\Products\ProductRepository;
use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\StorePreorders\StorePreorderShopifyShelfSyncResult;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyOrderLineItem;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Write\ShopifyStorePreorderProductWriter;
use App\Support\Products\Storefront\StorefrontTag;

final class StorePreorderShopifyShelfSyncService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyStorePreorderProductWriter $writer,
        private readonly StorePreorderRepository $offers,
        private readonly ProductRepository $products,
        private readonly StorePreorderCollectionReorderScheduler $reorder,
    ) {}

    public function syncAll(): StorePreorderShopifyShelfSyncResult
    {
        $this->writer->assertCanWrite();
        $kept = 0;
        $retagged = 0;
        $untagged = 0;
        $deleted = 0;
        $failed = 0;
        $failures = [];
        foreach ($this->listTaggedProducts() as $row) {
            try {
                $action = $this->apply($row['gid'], $row['sku']);
            } catch (ShopifyGraphQlException $e) {
                $failed++;
                $failures[] = $row['sku'].': '.$e->getMessage();

                continue;
            }
            if ($action === 'kept') {
                $kept++;
            } elseif ($action === 'deleted') {
                $deleted++;
            } else {
                $untagged++;
            }
        }

        $restore = $this->retagMissingOffers();
        $retagged = $restore['retagged'];
        $failed += $restore['failed'];
        $failures = [...$failures, ...$restore['failures']];

        $result = new StorePreorderShopifyShelfSyncResult($kept, $retagged, $untagged, $deleted, $failed, $failures);
        if ($retagged > 0 || $untagged > 0 || $deleted > 0) {
            $this->reorder->queue();
        }

        return $result;
    }

    public function syncSku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return 'skipped';
        }

        $gid = $this->productGidForSku($sku);
        if ($gid === null) {
            return 'skipped';
        }

        return $this->apply($gid, $sku);
    }

    /**
     * @return list<array{gid: string, sku: string}>
     */
    private function listTaggedProducts(): array
    {
        $query = 'tag:"'.StorefrontTag::STORE_PREORDER.'"';
        $after = null;
        $out = [];
        do {
            $response = $this->client->query(ShopifyAdminGraphQlQueries::PRODUCTS_BY_QUERY, [
                'query' => $query,
                'first' => 50,
                'after' => $after,
            ]);
            $conn = is_array($response['data']['products'] ?? null) ? $response['data']['products'] : [];
            foreach ($conn['nodes'] ?? [] as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $out[] = $this->rowFromNode($node);
            }
            $after = ($conn['pageInfo']['hasNextPage'] ?? false) ? ($conn['pageInfo']['endCursor'] ?? null) : null;
        } while (is_string($after) && $after !== '');

        return $out !== [] ? $out : $this->listCollectionProducts();
    }

    /**
     * @return list<array{gid: string, sku: string}>
     */
    private function listCollectionProducts(): array
    {
        $after = null;
        $out = [];
        do {
            $response = $this->client->query(ShopifyAdminGraphQlQueries::COLLECTION_PRODUCTS_BY_HANDLE, [
                'handle' => 'pre-orders',
                'first' => 50,
                'after' => $after,
            ]);
            $conn = is_array($response['data']['collectionByHandle']['products'] ?? null)
                ? $response['data']['collectionByHandle']['products']
                : [];
            foreach ($conn['nodes'] ?? [] as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $out[] = $this->rowFromNode($node);
            }
            $after = ($conn['pageInfo']['hasNextPage'] ?? false) ? ($conn['pageInfo']['endCursor'] ?? null) : null;
        } while (is_string($after) && $after !== '');

        return $out;
    }

    /**
     * Closed (and any other) ERP offers must stay on `/collections/pre-orders`.
     * A later catalog upsert can drop `sp:store-preorder` because open-only
     * push overrides no longer apply.
     *
     * @return array{retagged: int, failed: int, failures: list<string>}
     */
    private function retagMissingOffers(): array
    {
        $retagged = 0;
        $failed = 0;
        $failures = [];
        $taggedSkus = [];
        foreach ($this->listTaggedProducts() as $row) {
            $sku = trim($row['sku']);
            if ($sku !== '') {
                $taggedSkus[strtolower($sku)] = true;
            }
        }

        foreach ($this->offers->listAll() as $offer) {
            $sku = trim((string) ($offer->product?->sku ?: $offer->plamod_sku));
            if ($sku === '' || isset($taggedSkus[strtolower($sku)])) {
                continue;
            }

            $gid = $this->productGidForSku($sku);
            if ($gid === null) {
                continue;
            }

            try {
                $this->writer->tag($gid, $sku);
                $this->restoreOfferMetafields($gid, $offer);
                $retagged++;
                $taggedSkus[strtolower($sku)] = true;
            } catch (ShopifyGraphQlException $e) {
                $failed++;
                $failures[] = $sku.': '.$e->getMessage();
            }
        }

        foreach ($this->offers->listAll() as $offer) {
            $sku = trim((string) ($offer->product?->sku ?: $offer->plamod_sku));
            if ($sku === '' || ! isset($taggedSkus[strtolower($sku)])) {
                continue;
            }

            $gid = $this->productGidForSku($sku);
            if ($gid === null) {
                continue;
            }

            try {
                $this->restoreOfferMetafields($gid, $offer);
            } catch (ShopifyGraphQlException $e) {
                $failed++;
                $failures[] = $sku.': '.$e->getMessage();
            }
        }

        return [
            'retagged' => $retagged,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }

    private function restoreOfferMetafields(string $productGid, \App\Models\StorePreorder $offer): void
    {
        $this->writer->setOfferMetafields(
            $productGid,
            (new StorePreorderShopifyPushOverride($offer))->metafields(),
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{gid: string, sku: string}
     */
    private function rowFromNode(array $node): array
    {
        $sku = '';
        foreach ($node['variants']['nodes'] ?? [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $candidate = trim((string) ($variant['sku'] ?? ''));
            if ($candidate !== '') {
                $sku = $candidate;
                break;
            }
        }

        return [
            'gid' => trim((string) ($node['id'] ?? '')),
            'sku' => $sku,
        ];
    }

    private function apply(string $productGid, string $sku): string
    {
        if ($productGid === '') {
            return 'skipped';
        }
        if ($sku !== '' && $this->offers->findByPlamodSku($sku) !== null) {
            return 'kept';
        }
        if ($sku !== '' && $this->erpProductExists($sku)) {
            $this->writer->untag($productGid, $sku);

            return 'untagged';
        }
        if ($sku !== '' && $this->hasOrder($sku)) {
            $this->writer->untag($productGid, $sku);

            return 'untagged';
        }
        $this->writer->delete($productGid, $sku !== '' ? $sku : $productGid);

        return 'deleted';
    }

    private function erpProductExists(string $sku): bool
    {
        return $this->products->findBySkus([$sku])->isNotEmpty();
    }

    private function hasOrder(string $sku): bool
    {
        $local = ShopifyOrderLineItem::query()->where('sku', '=', $sku)->exists();
        if ($local) {
            return true;
        }

        $response = $this->client->query(ShopifyAdminGraphQlQueries::ORDERS_EXIST_BY_QUERY, [
            'query' => 'sku:'.$sku,
        ]);
        $nodes = $response['data']['orders']['nodes'] ?? [];

        return is_array($nodes) && $nodes !== [];
    }

    private function productGidForSku(string $sku): ?string
    {
        $response = $this->client->query(ShopifyAdminGraphQlQueries::PRODUCTS_BY_QUERY, [
            'query' => 'sku:'.$sku,
            'first' => 1,
            'after' => null,
        ]);
        $gid = trim((string) ($response['data']['products']['nodes'][0]['id'] ?? ''));

        return $gid !== '' ? $gid : null;
    }
}
