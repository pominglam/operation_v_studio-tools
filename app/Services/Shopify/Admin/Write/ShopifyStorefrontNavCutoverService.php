<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final class ShopifyStorefrontNavCutoverService
{
    public const string MENU_HANDLE = 'main-menu';

    /**
     * @return list<array{handle: string, title: string, footer: bool}>
     */
    public static function toolsSuppliesNavChildren(): array
    {
        return StorefrontTag::toolsAndSuppliesNavMenuChildren();
    }

    /**
     * @var list<string>
     */
    private const KEEP_TOP_LEVEL_TITLES = [
        'model kits',
        'miscellaneous',
    ];

    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
    ) {}

    /**
     * @return array{id: string, handle: string, title: string, items: array<int, array<string, mixed>>}
     */
    public function fetchMainMenu(): array
    {
        $this->scopeGuard->assertWriteOnlineStoreNavigationScope();

        $response = $this->client->query(ShopifyAdminGraphQlQueries::MENU_BY_HANDLE, [
            'query' => 'handle:'.self::MENU_HANDLE,
        ]);

        $nodes = is_array($response['data']['menus']['nodes'] ?? null)
            ? $response['data']['menus']['nodes']
            : [];
        $menu = is_array($nodes[0] ?? null) ? $nodes[0] : null;
        if ($menu === null) {
            throw new ShopifyGraphQlException('Shopify main-menu not found.');
        }

        $id = is_string($menu['id'] ?? null) ? trim($menu['id']) : '';
        $handle = is_string($menu['handle'] ?? null) ? trim($menu['handle']) : '';
        $title = is_string($menu['title'] ?? null) ? trim($menu['title']) : '';
        if ($id === '' || $handle === '') {
            throw new ShopifyGraphQlException('Shopify main-menu response missing id/handle.');
        }

        return [
            'id' => $id,
            'handle' => $handle,
            'title' => $title,
            'items' => is_array($menu['items'] ?? null) ? $menu['items'] : [],
        ];
    }

    public function exportMainMenuRollback(): string
    {
        $menu = $this->fetchMainMenu();
        $directory = storage_path('app/private/shopify/nav-rollback');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/main-menu-'.now()->format('Ymd_His').'.json';
        File::put($path, json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        Log::channel('shopify')->info('shopify.write.nav_cutover.exported', [
            'path' => $path,
            'item_count' => count($menu['items']),
        ]);

        return $path;
    }

    /**
     * @param  array<string, string>  $collectionGidsByHandle
     * @return list<array<string, mixed>>
     */
    public function buildCutoverTopLevelItems(array $currentItems, array $collectionGidsByHandle): array
    {
        $kept = [];
        foreach ($currentItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = strtolower(trim((string) ($item['title'] ?? '')));
            if (in_array($title, self::KEEP_TOP_LEVEL_TITLES, true)) {
                $kept[] = $this->menuItemToUpdateInput($item);
            }
        }

        $toolsSupplies = $this->buildToolsSuppliesTopLevelItem($collectionGidsByHandle);

        $ordered = [];
        foreach ($kept as $item) {
            if (strtolower(trim((string) ($item['title'] ?? ''))) === 'model kits') {
                $ordered[] = $item;
            }
        }

        $ordered[] = $toolsSupplies;

        foreach ($kept as $item) {
            if (strtolower(trim((string) ($item['title'] ?? ''))) === 'miscellaneous') {
                $ordered[] = $item;
            }
        }

        if ($ordered === []) {
            throw new ShopifyGraphQlException('Could not locate Model kits / Miscellaneous items in main-menu.');
        }

        return $ordered;
    }

    /**
     * @param  array<string, string>  $collectionGidsByHandle
     * @return array<string, mixed>
     */
    public function buildToolsSuppliesTopLevelItem(array $collectionGidsByHandle): array
    {
        $children = [];
        foreach (self::toolsSuppliesNavChildren() as $child) {
            $handle = $child['handle'];
            $gid = $collectionGidsByHandle[$handle] ?? '';
            if ($gid === '') {
                throw new ShopifyGraphQlException("Missing collection GID for handle \"{$handle}\".");
            }

            $children[] = [
                'title' => $child['title'],
                'type' => 'COLLECTION',
                'resourceId' => $gid,
                'url' => '/collections/'.$handle,
                'items' => [],
            ];
        }

        $hubGid = $collectionGidsByHandle['tools-and-supplies'] ?? '';

        return [
            'title' => 'Tools & Supplies',
            'type' => 'COLLECTION',
            'resourceId' => $hubGid !== '' ? $hubGid : null,
            'url' => '/collections/tools-and-supplies',
            'items' => $children,
        ];
    }

    /**
     * @param  array<string, string>  $collectionGidsByHandle
     */
    public function applyMainMenuCutover(array $collectionGidsByHandle): void
    {
        $this->scopeGuard->assertWriteOnlineStoreNavigationScope();

        $menu = $this->fetchMainMenu();
        $items = $this->buildCutoverTopLevelItems($menu['items'], $collectionGidsByHandle);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::MENU_UPDATE, [
            'id' => $menu['id'],
            'title' => $menu['title'],
            'handle' => $menu['handle'],
            'items' => $items,
        ]);

        $payload = is_array($response['data']['menuUpdate'] ?? null)
            ? $response['data']['menuUpdate']
            : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify menuUpdate returned no payload.');
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
                $messages !== [] ? implode('; ', $messages) : 'Shopify menuUpdate returned user errors.',
            );
        }

        Log::channel('shopify')->info('shopify.write.nav_cutover.applied', [
            'menu_id' => $menu['id'],
            'top_level_items' => count($items),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function resolveCollectionGidsByHandle(): array
    {
        $this->scopeGuard->assertWriteProductsScope();

        $handles = array_map(
            static fn (array $child): string => $child['handle'],
            self::toolsSuppliesNavChildren(),
        );

        $gids = [];
        foreach ($handles as $handle) {
            $response = $this->client->query(ShopifyAdminGraphQlQueries::COLLECTION_BY_HANDLE, [
                'handle' => $handle,
            ]);
            $node = is_array($response['data']['collectionByHandle'] ?? null)
                ? $response['data']['collectionByHandle']
                : null;
            $gid = is_array($node) && is_string($node['id'] ?? null) ? trim($node['id']) : '';
            if ($gid === '') {
                throw new ShopifyGraphQlException("Collection not found for handle \"{$handle}\".");
            }

            $gids[$handle] = $gid;
        }

        return $gids;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function menuItemToUpdateInput(array $item): array
    {
        $input = [
            'title' => (string) ($item['title'] ?? ''),
            'type' => (string) ($item['type'] ?? 'HTTP'),
            'url' => (string) ($item['url'] ?? ''),
            'items' => [],
        ];

        if (is_string($item['id'] ?? null) && trim($item['id']) !== '') {
            $input['id'] = trim($item['id']);
        }

        if (is_string($item['resourceId'] ?? null) && trim($item['resourceId']) !== '') {
            $input['resourceId'] = trim($item['resourceId']);
        }

        $children = is_array($item['items'] ?? null) ? $item['items'] : [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $input['items'][] = $this->menuItemToUpdateInput($child);
            }
        }

        return $input;
    }
}
