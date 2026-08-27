<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Support\Products\Storefront\ModelKitShelfCatalog;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Support\Facades\Log;

final class ShopifyStorefrontPilotCollectionService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly ShopifyPublishProductToAllChannelsService $publishAllChannels,
    ) {}

    /**
     * @var array<string, array{handle: string, title: string, tag: string}>
     */
    private const DEPARTMENT_COLLECTIONS = [
        'tapes' => [
            'handle' => 'tapes',
            'title' => 'Tapes',
            'tag' => StorefrontTag::DEPT_TAPES,
        ],
        'decals' => [
            'handle' => 'decals',
            'title' => 'Decals',
            'tag' => StorefrontTag::DEPT_DECALS,
        ],
        'sanding' => [
            'handle' => 'sanding',
            'title' => 'Sanding',
            'tag' => StorefrontTag::DEPT_SANDING,
        ],
        'cutting' => [
            'handle' => 'nippers-and-knives',
            'title' => 'Nippers & knives',
            'tag' => StorefrontTag::DEPT_CUTTING,
        ],
        'paints' => [
            'handle' => 'paints',
            'title' => 'Paints',
            'tag' => StorefrontTag::DEPT_PAINTS,
        ],
        'panel-liners' => [
            'handle' => 'panel-liners',
            'title' => 'Panel liners',
            'tags' => [
                StorefrontTag::DEPT_PANEL_LINERS,
                'ts:paint:product:panel-line',
            ],
            'disjunctive' => true,
        ],
        'markers' => [
            'handle' => 'markers',
            'title' => 'Markers',
            'tag' => StorefrontTag::DEPT_MARKERS,
        ],
        'brushes' => [
            'handle' => 'brushes',
            'title' => 'Brushes',
            'tag' => StorefrontTag::DEPT_BRUSHES,
        ],
        'drills' => [
            'handle' => 'drills',
            'title' => 'Drills & bits',
            'tag' => StorefrontTag::DEPT_DRILLS,
        ],
        'tweezers' => [
            'handle' => 'tweezers',
            'title' => 'Tweezers',
            'tag' => StorefrontTag::DEPT_TWEEZERS,
        ],
        'scribing' => [
            'handle' => 'scribing-tools',
            'title' => 'Scribing tools',
            'tag' => StorefrontTag::DEPT_SCRIBING,
        ],
        'adhesives' => [
            'handle' => 'adhesives',
            'title' => 'Adhesives',
            'tag' => StorefrontTag::DEPT_ADHESIVES,
        ],
        'workshop-misc' => [
            'handle' => 'workshop-misc',
            'title' => 'Other',
            'tag' => StorefrontTag::DEPT_WORKSHOP_MISC,
        ],
        'airbrush' => [
            'handle' => 'airbrush',
            'title' => 'Airbrush',
            'tag' => StorefrontTag::DEPT_AIRBRUSH,
        ],
        'weathering' => [
            'handle' => 'weathering',
            'title' => 'Weathering',
            'tag' => StorefrontTag::DEPT_WEATHERING,
        ],
    ];

    /**
     * @return array<string, array{gid: string, handle: string, title: string, product_count: int, url: string}>
     */
    public function ensurePilotCollections(): array
    {
        return $this->ensureDepartmentCollections(['tapes', 'decals']);
    }

    /**
     * @return array<int, string>
     */
    public function enabledDepartmentKeys(): array
    {
        /** @var array<int, string> $enabled */
        $enabled = config('storefront_classification.enabled_departments', []);

        return array_values(array_filter(
            $enabled,
            static fn (string $department): bool => isset(self::DEPARTMENT_COLLECTIONS[$department]),
        ));
    }

    /**
     * @return array<string, array{gid: string, handle: string, title: string, product_count: int, url: string}>
     */
    public function ensureAllEnabledDepartmentCollections(): array
    {
        return $this->ensureDepartmentCollections($this->enabledDepartmentKeys());
    }

    /**
     * @param  array<int, string>  $departments
     * @return array<string, array{gid: string, handle: string, title: string, product_count: int, url: string}>
     */
    public function ensureDepartmentCollections(array $departments): array
    {
        $this->scopeGuard->assertWriteProductsScope();

        $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');
        $out = [];

        foreach ($departments as $department) {
            $meta = self::DEPARTMENT_COLLECTIONS[$department] ?? null;
            if ($meta === null) {
                continue;
            }

            $handle = $meta['handle'];
            if (($meta['disjunctive'] ?? false) && isset($meta['tags'])) {
                /** @var list<string> $tags */
                $tags = $meta['tags'];
                $gid = $this->upsertSmartCollectionOrTags($handle, $meta['title'], $tags, true);
            } else {
                $gid = $this->upsertSmartCollection($handle, $meta['title'], $meta['tag']);
            }
            $this->publishAllChannels->publishToAllChannels($gid, 'collection:'.$handle);
            $preview = $this->collectionPreview($gid);
            $count = is_int($preview['productsCount']['count'] ?? null)
                ? (int) $preview['productsCount']['count']
                : 0;

            $out[$department] = [
                'gid' => $gid,
                'handle' => $handle,
                'title' => $meta['title'],
                'product_count' => $count,
                'url' => $baseUrl.'/collections/'.$handle,
            ];

            Log::channel('shopify')->info('shopify.write.storefront_pilot_collection.ready', [
                'department' => $department,
                'handle' => $handle,
                'gid' => $gid,
                'product_count' => $count,
            ]);
        }

        return $out;
    }

    /**
     * @return array<string, array{gid: string, handle: string, title: string, product_count: int, url: string}>
     */
    public function ensureModelKitShelfCollections(): array
    {
        $this->scopeGuard->assertWriteProductsScope();

        $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');
        $out = [];

        foreach (ModelKitShelfCatalog::shelves() as $key => $meta) {
            $handle = $meta['handle'];
            if (($meta['disjunctive'] ?? false) && isset($meta['tags'])) {
                /** @var list<string> $tags */
                $tags = $meta['tags'];
                $gid = $this->upsertSmartCollectionOrTags($handle, $meta['title'], $tags, true);
            } else {
                $gid = $this->upsertSmartCollection($handle, $meta['title'], (string) $meta['tag']);
            }

            $this->publishAllChannels->publishToAllChannels($gid, 'collection:'.$handle);
            $preview = $this->collectionPreview($gid);
            $count = is_int($preview['productsCount']['count'] ?? null)
                ? (int) $preview['productsCount']['count']
                : 0;

            $out[$key] = [
                'gid' => $gid,
                'handle' => $handle,
                'title' => $meta['title'],
                'product_count' => $count,
                'url' => $baseUrl.'/collections/'.$handle,
            ];

            Log::channel('shopify')->info('shopify.write.model_kit_shelf_collection.ready', [
                'shelf' => $key,
                'handle' => $handle,
                'gid' => $gid,
                'product_count' => $count,
            ]);
        }

        return $out;
    }

    /**
     * Model kits at or below the beginner price cap (Shopify variant price rule + mk dept tag).
     *
     * @return array{gid: string, handle: string, title: string, product_count: int, url: string}
     */
    public function ensureBeginnerKitsCollection(float $maxPriceCad = 35.0): array
    {
        $this->scopeGuard->assertWriteProductsScope();

        $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');
        $handle = 'beginner-kits';
        $title = 'Beginner Kits';
        $priceCap = number_format($maxPriceCad + 0.01, 2, '.', '');

        $gid = $this->upsertSmartCollectionWithRules($handle, $title, [
            [
                'column' => 'TAG',
                'relation' => 'EQUALS',
                'condition' => StorefrontTag::MK_DEPT_MODEL_KITS,
            ],
            [
                'column' => 'VARIANT_PRICE',
                'relation' => 'LESS_THAN',
                'condition' => $priceCap,
            ],
        ], false);

        $this->publishAllChannels->publishToAllChannels($gid, 'collection:'.$handle);
        $preview = $this->collectionPreview($gid);
        $count = is_int($preview['productsCount']['count'] ?? null)
            ? (int) $preview['productsCount']['count']
            : 0;

        Log::channel('shopify')->info('shopify.write.beginner_kits_collection.ready', [
            'handle' => $handle,
            'gid' => $gid,
            'product_count' => $count,
            'max_price_cad' => $maxPriceCad,
        ]);

        return [
            'gid' => $gid,
            'handle' => $handle,
            'title' => $title,
            'product_count' => $count,
            'url' => $baseUrl.'/collections/'.$handle,
        ];
    }

    /**
     * @return array<string, array{gid: string, handle: string, title: string, product_count: int, url: string}>
     */
    public function ensureToolsAndSuppliesHubCollection(): array
    {
        $this->scopeGuard->assertWriteProductsScope();

        $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');
        $handle = 'tools-and-supplies';
        $title = 'All tools & supplies';
        $tags = StorefrontTag::toolsAndSuppliesHubDepartmentTags();

        $gid = $this->upsertSmartCollectionOrTags($handle, $title, $tags, true);
        $this->publishAllChannels->publishToAllChannels($gid, 'collection:'.$handle);
        $preview = $this->collectionPreview($gid);
        $count = is_int($preview['productsCount']['count'] ?? null)
            ? (int) $preview['productsCount']['count']
            : 0;

        Log::channel('shopify')->info('shopify.write.storefront_hub_collection.ready', [
            'handle' => $handle,
            'gid' => $gid,
            'product_count' => $count,
            'department_rule_count' => count($tags),
        ]);

        return [
            'gid' => $gid,
            'handle' => $handle,
            'title' => $title,
            'product_count' => $count,
            'url' => $baseUrl.'/collections/'.$handle,
        ];
    }

    private function upsertSmartCollection(string $handle, string $title, string $departmentTag): string
    {
        return $this->upsertSmartCollectionOrTags($handle, $title, [$departmentTag], false);
    }

    /**
     * @param  list<string>  $tags
     */
    private function upsertSmartCollectionOrTags(string $handle, string $title, array $tags, bool $appliedDisjunctively): string
    {
        $rules = [];
        foreach ($tags as $tag) {
            $rules[] = [
                'column' => 'TAG',
                'relation' => 'EQUALS',
                'condition' => $tag,
            ];
        }

        $existing = $this->collectionByHandle($handle);
        $input = [
            'title' => $title,
            'handle' => $handle,
            'ruleSet' => [
                'appliedDisjunctively' => $appliedDisjunctively,
                'rules' => $rules,
            ],
        ];

        if ($existing !== null) {
            $input['id'] = $existing;

            return $this->mutateCollection(ShopifyAdminGraphQlMutations::COLLECTION_UPDATE, $input, 'collectionUpdate');
        }

        return $this->mutateCollection(ShopifyAdminGraphQlMutations::COLLECTION_CREATE, $input, 'collectionCreate');
    }

    /**
     * @param  list<array{column: string, relation: string, condition: string}>  $rules
     */
    private function upsertSmartCollectionWithRules(
        string $handle,
        string $title,
        array $rules,
        bool $appliedDisjunctively,
    ): string {
        $existing = $this->collectionByHandle($handle);
        $input = [
            'title' => $title,
            'handle' => $handle,
            'ruleSet' => [
                'appliedDisjunctively' => $appliedDisjunctively,
                'rules' => $rules,
            ],
        ];

        if ($existing !== null) {
            $input['id'] = $existing;

            return $this->mutateCollection(ShopifyAdminGraphQlMutations::COLLECTION_UPDATE, $input, 'collectionUpdate');
        }

        return $this->mutateCollection(ShopifyAdminGraphQlMutations::COLLECTION_CREATE, $input, 'collectionCreate');
    }

    private function collectionByHandle(string $handle): ?string
    {
        $response = $this->client->query(ShopifyAdminGraphQlQueries::COLLECTION_BY_HANDLE, [
            'handle' => $handle,
        ]);

        $node = is_array($response['data']['collectionByHandle'] ?? null)
            ? $response['data']['collectionByHandle']
            : null;
        if ($node === null) {
            return null;
        }

        $gid = is_string($node['id'] ?? null) ? trim($node['id']) : '';

        return $gid !== '' ? $gid : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function mutateCollection(string $mutation, array $input, string $payloadKey): string
    {
        $response = $this->client->query($mutation, ['input' => $input]);
        $payload = is_array($response['data'][$payloadKey] ?? null) ? $response['data'][$payloadKey] : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException("Shopify {$payloadKey} returned no payload.");
        }

        $userErrors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($userErrors !== []) {
            $messages = [];
            foreach ($userErrors as $error) {
                if (is_array($error) && is_string($error['message'] ?? null) && trim($error['message']) !== '') {
                    $messages[] = trim($error['message']);
                }
            }

            throw new ShopifyGraphQlException(
                $messages !== [] ? implode('; ', $messages) : "Shopify {$payloadKey} returned user errors.",
            );
        }

        $collection = is_array($payload['collection'] ?? null) ? $payload['collection'] : null;
        $gid = is_array($collection) && is_string($collection['id'] ?? null) ? trim($collection['id']) : '';
        if ($gid === '') {
            throw new ShopifyGraphQlException("Shopify {$payloadKey} returned no collection id.");
        }

        return $gid;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionPreview(string $gid): array
    {
        $response = $this->client->query(ShopifyAdminGraphQlQueries::COLLECTION_PRODUCTS_PREVIEW, [
            'id' => $gid,
            'first' => 25,
        ]);

        return is_array($response['data']['collection'] ?? null)
            ? $response['data']['collection']
            : [];
    }
}
