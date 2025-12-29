import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

import ShopifyContentExportCard from '../ShopifyContentExportCard.vue';

vi.mock('../../../lib/api', () => {
    return {
        api: {
            get: vi.fn(),
            post: vi.fn(),
        },
    };
});

describe('ShopifyContentExportCard', () => {
    it('loads tunnel status and prepares export, showing download link', async () => {
        const { api } = await import('../../../lib/api');
        (api.get as any).mockResolvedValueOnce({
            data: {
                running: true,
                tunnel_url: 'https://abc.trycloudflare.com',
                container_id: 'cid',
                error: null,
                reachable: true,
                reachable_http_status: 404,
            },
        });
        (api.post as any).mockResolvedValueOnce({
            data: {
                export_id: 'e1',
                download_url: '/api/v1/products/exports/shopify-content/download/e1',
                exported_products: 1,
                exported_rows: 1,
                skipped_missing_handle: [],
                skipped_duplicate_handle: [],
                images_enabled: true,
                tunnel_base_url: 'https://abc.trycloudflare.com',
                tunnel: { running: true, tunnel_url: 'https://abc.trycloudflare.com', container_id: 'cid', error: null },
            },
        });

        const wrapper = mount(ShopifyContentExportCard);
        await new Promise((r) => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        const btn = wrapper.find('[data-testid="shopify-content-prepare"]');
        expect(btn.exists()).toBe(true);
        await btn.trigger('click');
        await new Promise((r) => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        const link = wrapper.find('[data-testid="shopify-content-download"]');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toContain('/download/e1');
        expect(wrapper.text()).toContain('Exported');
    });
});


