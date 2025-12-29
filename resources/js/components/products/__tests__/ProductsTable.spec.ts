import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

import ProductsTable from '../ProductsTable.vue';

describe('ProductsTable', () => {
    it('renames columns and opens Plamod drawer from Info cell; removes Plamod button', async () => {
        const onOpenPlamod = vi.fn();

        const wrapper = mount(ProductsTable, {
            props: {
                loading: false,
                products: [
                    {
                        id: 'p-1',
                        sku: 'SKU-1',
                        barcode: null,
                        description: 'Test',
                        type: null,
                        vendor: null,
                        price: null,
                        order: null,
                        filled: null,
                        available: null,
                        extended: null,
                        published_on_shopify: true,
                        selling_price: '55.99',
                        pdp: { has_description: true, plamod_image_count: 1 },
                    },
                ],
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => 0,
                onUpdate: async () => undefined,
                onOpenPlamod,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                },
            },
        });

        expect(wrapper.text()).toContain('Info');
        expect(wrapper.text()).toContain('Published on Shopify');

        const plamodButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Plamod');
        expect(plamodButtons.length).toBe(0);

        const infoBtn = wrapper.find('[data-testid="product-info-open"]');
        expect(infoBtn.exists()).toBe(true);
        expect(infoBtn.classes()).toContain('cursor-pointer');
        await infoBtn.trigger('click');

        expect(onOpenPlamod).toHaveBeenCalledWith('p-1');
    });

    it('includes Stedi in vendor select options while editing', async () => {
        const wrapper = mount(ProductsTable, {
            props: {
                loading: false,
                vendorOptions: ['Plamod', 'Stedi', 'MSMN'],
                products: [
                    {
                        id: 'p-1',
                        sku: 'SKU-1',
                        barcode: null,
                        description: 'Test',
                        type: null,
                        vendor: null,
                        price: null,
                        order: null,
                        filled: null,
                        available: null,
                        extended: null,
                        published_on_shopify: false,
                        selling_price: null,
                        pdp: { has_description: false, plamod_image_count: 0 },
                    },
                ],
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => 0,
                onUpdate: async () => undefined,
                onOpenPlamod: () => undefined,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                },
            },
        });

        const editBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Edit');
        expect(editBtn, 'Expected Edit button').toBeTruthy();
        await editBtn!.trigger('click');

        const selects = wrapper.findAll('select');
        expect(selects.length).toBeGreaterThan(0);
        expect(wrapper.text()).toContain('Stedi');
    });
});


