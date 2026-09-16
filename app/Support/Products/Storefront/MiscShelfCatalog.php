<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

/**
 * Miscellaneous mega-menu shelves: Shopify handle → smart collection rules on misc:* tags.
 *
 * `other-products` is an operator catch-all (not wired in the mega menu). Assign keychain,
 * CCS Toys, or a model-kit shelf before customer-facing nav when a SKU lands here.
 *
 * @phpstan-type ShelfMeta array{handle: string, title: string, tag?: string, tags?: list<string>, disjunctive?: bool}
 */
final class MiscShelfCatalog
{
    /**
     * @return array<string, ShelfMeta>
     */
    public static function shelves(): array
    {
        return [
            'keychain' => self::singleTag('keychain', 'Keychains', StorefrontTag::MISC_LINE_KEYCHAIN),
            'ccs-toys' => self::singleTag('ccs-toys', 'CCS Toys', StorefrontTag::MISC_LINE_CCS_TOYS),
            'other-products' => self::singleTag('other-products', 'Other products', StorefrontTag::MISC_LINE_OTHER),
        ];
    }

    /**
     * @return ShelfMeta
     */
    private static function singleTag(string $handle, string $title, string $tag): array
    {
        return [
            'handle' => $handle,
            'title' => $title,
            'tag' => $tag,
        ];
    }
}
