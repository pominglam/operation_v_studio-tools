import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

import { api } from '../../../lib/api';
import ProductPoLinesDrawer from '../ProductPoLinesDrawer.vue';

vi.mock('../../../lib/api', () => ({
    api: {
        get: vi.fn().mockResolvedValue({ data: { lines: [] } }),
    },
}));

describe('ProductPoLinesDrawer', () => {
    it('uses a wide desktop panel and allows horizontal table scrolling', async () => {
        const wrapper = mount(ProductPoLinesDrawer, {
            props: {
                open: true,
                productId: 'product-uuid',
                productSku: 'SKU-1',
                productName: 'Product one',
            },
            global: {
                stubs: {
                    RouterLink: true,
                    Teleport: true,
                },
            },
        });

        await flushPromises();

        expect(vi.mocked(api.get)).toHaveBeenCalled();
        expect(wrapper.get('[data-testid="product-po-lines-panel"]').classes()).toContain(
            'max-w-7xl',
        );
        expect(wrapper.get('[data-testid="product-po-lines-table-scroll"]').classes()).toContain(
            'overflow-x-auto',
        );
        expect(wrapper.get('table').classes()).toContain('min-w-[64rem]');
    });
});
