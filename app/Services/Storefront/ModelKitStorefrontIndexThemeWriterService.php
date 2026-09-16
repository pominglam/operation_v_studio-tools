<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Storefront\ModelKitStorefrontIndexWriteException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;

final class ModelKitStorefrontIndexThemeWriterService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
    ) {}

    /**
     * @return array<int, string>
     */
    public function themeIds(): array
    {
        $configured = config('shopify.mk_storefront_index.theme_ids', []);
        if (! is_array($configured)) {
            return [];
        }

        $ids = [];
        foreach ($configured as $themeId) {
            $trimmed = trim((string) $themeId);
            if ($trimmed !== '') {
                $ids[] = $trimmed;
            }
        }

        return array_values(array_unique($ids));
    }

    public function filename(): string
    {
        $filename = trim((string) config('shopify.mk_storefront_index.filename', 'snippets/ovs-model-kit-index-cache.liquid'));

        return $filename !== '' ? $filename : 'snippets/ovs-model-kit-index-cache.liquid';
    }

    /**
     * @return array<int, string>
     */
    public function upsert(string $liquid): array
    {
        $themeIds = $this->themeIds();
        if ($themeIds === []) {
            throw new ModelKitStorefrontIndexWriteException('No Shopify theme IDs configured for MK index cache.');
        }

        foreach ($themeIds as $themeId) {
            $this->upsertTheme($themeId, $liquid);
        }

        return $themeIds;
    }

    private function upsertTheme(string $themeId, string $liquid): void
    {
        $response = $this->client->query(ShopifyAdminGraphQlMutations::THEME_FILES_UPSERT, [
            'themeId' => $this->themeGid($themeId),
            'files' => [[
                'filename' => $this->filename(),
                'body' => [
                    'type' => 'TEXT',
                    'value' => $liquid,
                ],
            ]],
        ]);

        $this->assertUpsertOk($themeId, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertUpsertOk(string $themeId, array $response): void
    {
        $graphErrors = $response['errors'] ?? [];
        if (is_array($graphErrors) && $graphErrors !== []) {
            $message = (string) ($graphErrors[0]['message'] ?? 'themeFilesUpsert GraphQL error');
            throw new ModelKitStorefrontIndexWriteException("MK index cache write failed for theme {$themeId}: {$message}");
        }

        $userErrors = $response['data']['themeFilesUpsert']['userErrors'] ?? [];
        if (is_array($userErrors) && $userErrors !== []) {
            $message = (string) ($userErrors[0]['message'] ?? 'themeFilesUpsert user error');
            throw new ModelKitStorefrontIndexWriteException("MK index cache write failed for theme {$themeId}: {$message}");
        }
    }

    private function themeGid(string $themeId): string
    {
        $id = trim($themeId);
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return 'gid://shopify/OnlineStoreTheme/'.$id;
    }
}
