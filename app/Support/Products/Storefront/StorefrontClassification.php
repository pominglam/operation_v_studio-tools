<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

final readonly class StorefrontClassification
{
    /**
     * @param  array<int, string>  $legacyTags
     * @param  array<int, string>  $storefrontTags
     * @param  array<int, string>  $shopifyTags
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public ?string $department,
        public array $legacyTags,
        public array $storefrontTags,
        public array $shopifyTags,
        public array $warnings,
    ) {}

    public function shopifyTagsCsv(): string
    {
        if ($this->shopifyTags === []) {
            return '';
        }

        return implode(', ', $this->shopifyTags);
    }

    /**
     * @return array{
     *   department: string|null,
     *   legacy_tags: array<int, string>,
     *   storefront_tags: array<int, string>,
     *   shopify_tags: array<int, string>,
     *   warnings: array<int, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'department' => $this->department,
            'legacy_tags' => $this->legacyTags,
            'storefront_tags' => $this->storefrontTags,
            'shopify_tags' => $this->shopifyTags,
            'warnings' => $this->warnings,
        ];
    }
}
