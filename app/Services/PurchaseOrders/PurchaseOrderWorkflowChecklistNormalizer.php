<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

final class PurchaseOrderWorkflowChecklistNormalizer
{
    private const string LEGACY_MARK_FLAGS = 'mark_latest_arrival_and_published_on_shopify';

    /** @var array<int, string> */
    private const array META_KEYS = [
        'select_and_arrange_product_images_deferred',
        'crawl_desc_image_price_skipped',
    ];

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, bool>
     */
    public function normalize(?array $raw): array
    {
        $defaults = $this->defaults();
        $next = $defaults;
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if ($key === self::LEGACY_MARK_FLAGS) {
                    continue;
                }
                if (array_key_exists($key, $defaults)) {
                    $next[$key] = (bool) $value;
                }
            }
            if (array_key_exists(self::LEGACY_MARK_FLAGS, $raw)) {
                $legacy = (bool) $raw[self::LEGACY_MARK_FLAGS];
                if (! array_key_exists('mark_published_on_shopify', $raw)) {
                    $next['mark_published_on_shopify'] = $legacy;
                }
                if (! array_key_exists('mark_latest_arrival', $raw)) {
                    $next['mark_latest_arrival'] = $legacy;
                }
            }
            foreach (self::META_KEYS as $metaKey) {
                if (array_key_exists($metaKey, $raw)) {
                    $next[$metaKey] = (bool) $raw[$metaKey];
                }
            }
        }

        return $next;
    }

    /**
     * @return array<string, bool>
     */
    public function defaults(): array
    {
        return [
            'import_po' => false,
            'crawl_desc_image_price' => false,
            'select_and_arrange_product_images' => false,
            'set_selling_price' => false,
            'ensure_all_products_have_barcode' => false,
            'export_to_shopify_get_handles' => false,
            'import_handle_only' => false,
            'update_product_available_with_shopify_current_inventory_quantity' => false,
            'import_product_available_quantity' => false,
            'mark_published_on_shopify' => false,
            'mark_latest_arrival' => false,
        ];
    }
}
