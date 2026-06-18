import { describe, expect, it } from 'vitest';

import {
    applyPushOptionsToPreview,
    type BulkPushShopifyPreviewBase,
    type ShopifyProductPushOptions,
} from '../bulkPushShopifyPreview';

function basePreview(): BulkPushShopifyPreviewBase {
    return {
        location_gid: 'gid://shopify/Location/1',
        location_name: 'Main Store',
        write_products_scope_ok: true,
        write_inventory_scope_ok: true,
        write_publications_scope_ok: true,
        images_enabled: true,
        tunnel_url: 'https://example.trycloudflare.com',
        products: [
            {
                product_uuid: 'uuid-update',
                sku: 'SKU-UPDATE',
                description: 'Update me',
                handle: 'update-me',
                erp_available_qty: 5,
                erp_hold_qty: 0,
                shopify_push_qty: 5,
                shopify_available_qty: 2,
                selling_price: '19.99',
                has_selling_price: true,
                published_on_shopify: true,
                push_action: 'update',
                option_independent_skip: null,
                push_eligible: true,
                skip_reason: null,
            },
            {
                product_uuid: 'uuid-create',
                sku: 'SKU-CREATE',
                description: 'Create me',
                handle: null,
                erp_available_qty: 1,
                erp_hold_qty: 0,
                shopify_push_qty: 1,
                shopify_available_qty: null,
                selling_price: '9.99',
                has_selling_price: true,
                published_on_shopify: false,
                push_action: 'create',
                option_independent_skip: null,
                push_eligible: true,
                skip_reason: null,
            },
            {
                product_uuid: 'uuid-blocked',
                sku: 'SKU-BLOCKED',
                description: 'Blocked mirror',
                handle: 'blocked',
                erp_available_qty: 0,
                erp_hold_qty: 0,
                shopify_push_qty: 0,
                shopify_available_qty: null,
                selling_price: null,
                has_selling_price: false,
                published_on_shopify: false,
                push_action: 'create',
                option_independent_skip: 'missing_shopify_mirror',
                push_eligible: false,
                skip_reason: 'missing_shopify_mirror',
            },
        ],
    };
}

describe('bulkPushShopifyPreview', () => {
    it('recomputes counts instantly when toggling quantity-only update options', () => {
        const options: ShopifyProductPushOptions = {
            info: false,
            images: false,
            quantities: true,
            price: false,
            publish_status: false,
            sales_channels: false,
        };

        const preview = applyPushOptionsToPreview(basePreview(), options);

        expect(preview.push_count).toBe(1);
        expect(preview.update_count).toBe(1);
        expect(preview.create_count).toBe(0);
        expect(preview.skip_count).toBe(2);
        expect(preview.product_uuids).toEqual(['uuid-update']);
    });

    it('requires info and price for create rows when those toggles are off', () => {
        const preview = applyPushOptionsToPreview(basePreview(), {
            info: false,
            images: false,
            quantities: true,
            price: true,
            publish_status: false,
            sales_channels: false,
        });

        const createRow = preview.products.find((row) => row.product_uuid === 'uuid-create');
        expect(createRow?.skip_reason).toBe('create_requires_info');
        expect(preview.push_count).toBe(1);
    });

    it('returns no eligible products when all field toggles are off', () => {
        const preview = applyPushOptionsToPreview(basePreview(), {
            info: false,
            images: false,
            quantities: false,
            price: false,
            publish_status: false,
            sales_channels: false,
        });

        expect(preview.push_count).toBe(0);
        expect(preview.products.every((row) => row.skip_reason === 'no_fields_selected' || row.option_independent_skip)).toBe(true);
    });
});
