import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

import ProductsTable from '../ProductsTable.vue';

describe('ProductsTable', () => {
    it('renames columns and opens Plamod drawer from Info cell; removes Plamod button', async () => {
        const onOpenPlamod = vi.fn();
        const onToggleReady = vi.fn(async () => undefined);
        const onToggleLatestArrival = vi.fn(async () => undefined);
        const onSelectAllMatching = vi.fn(async () => ['p-1']);
        const onUpdateAvailable = vi.fn(async () => undefined);
        const onUpdateMaintain = vi.fn(async () => undefined);

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
                        maintain: null,
                        extended: null,
                        published_on_shopify: true,
                        is_ready: false,
                        latest_landed_unit_cost: '12.34',
                        selling_price: '55.99',
                        pdp: { has_description: true, plamod_image_count: 1 },
                    },
                ],
                totalMatching: 1,
                selectionScopeKey: 'scope-1',
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => ({ queued: 0, batchId: '' }),
                onBulkExportSelected: async () => undefined,
                onBulkRecrawlSelected: async () => undefined,
                onUpdate: async () => undefined,
                onUpdateAvailable,
                onUpdateMaintain,
                onToggleReady,
                onToggleLatestArrival,
                onToggleCritical: async () => undefined,
                onToggleDiscontinue: async () => undefined,
                onSelectAllMatching,
                onOpenPlamod,
                onOpenPoLines: () => undefined,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                    BulkExportDialog: true,
                    BulkRecrawlDialog: true,
                },
            },
        });

        expect(wrapper.text()).toContain('Info');
        expect(wrapper.text()).toContain('Published on Shopify');
        expect(wrapper.text()).toContain('Selling price');
        expect(wrapper.text()).toContain('55.99');
        expect(wrapper.text()).not.toContain('Cost (Latest)');

        const plamodButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Plamod');
        expect(plamodButtons.length).toBe(0);

        await wrapper.find('[data-testid="toggle-cost-visibility"]').trigger('click');
        expect(wrapper.text()).toContain('Cost (Latest)');
        expect(wrapper.text()).toContain('12.34');

        const infoBtn = wrapper.find('[data-testid="product-info-open"]');
        expect(infoBtn.exists()).toBe(true);
        expect(infoBtn.classes()).toContain('cursor-pointer');
        await infoBtn.trigger('click');

        expect(onOpenPlamod).toHaveBeenCalledWith('p-1');
    });

    it('includes Stedi in vendor select options while editing', async () => {
        const onSelectAllMatching = vi.fn(async () => ['p-1']);
        const onToggleLatestArrival = vi.fn(async () => undefined);
        const onUpdateAvailable = vi.fn(async () => undefined);
        const onUpdateMaintain = vi.fn(async () => undefined);
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
                        maintain: null,
                        extended: null,
                        published_on_shopify: false,
                        is_ready: true,
                        selling_price: null,
                        pdp: { has_description: false, plamod_image_count: 0 },
                    },
                ],
                totalMatching: 1,
                selectionScopeKey: 'scope-1',
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => ({ queued: 0, batchId: '' }),
                onBulkExportSelected: async () => undefined,
                onBulkRecrawlSelected: async () => undefined,
                onUpdate: async () => undefined,
                onUpdateAvailable,
                onUpdateMaintain,
                onOpenPlamod: () => undefined,
                onToggleReady: async () => undefined,
                onToggleLatestArrival,
                onToggleCritical: async () => undefined,
                onToggleDiscontinue: async () => undefined,
                onSelectAllMatching,
                onOpenPoLines: () => undefined,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                    BulkExportDialog: true,
                    BulkRecrawlDialog: true,
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

    it('calls onToggleReady when ready checkbox is toggled', async () => {
        const onToggleReady = vi.fn(async () => undefined);
        const onSelectAllMatching = vi.fn(async () => ['p-1']);
        const onToggleLatestArrival = vi.fn(async () => undefined);
        const onUpdateAvailable = vi.fn(async () => undefined);
        const onUpdateMaintain = vi.fn(async () => undefined);

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
                        published_on_shopify: false,
                        is_ready: false,
                        available: null,
                        maintain: null,
                        pdp: { has_description: false, plamod_image_count: 0 },
                    },
                ],
                totalMatching: 1,
                selectionScopeKey: 'scope-1',
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => ({ queued: 0, batchId: '' }),
                onBulkExportSelected: async () => undefined,
                onBulkRecrawlSelected: async () => undefined,
                onUpdate: async () => undefined,
                onUpdateAvailable,
                onUpdateMaintain,
                onToggleReady,
                onToggleLatestArrival,
                onToggleCritical: async () => undefined,
                onToggleDiscontinue: async () => undefined,
                onSelectAllMatching,
                onOpenPlamod: () => undefined,
                onOpenPoLines: () => undefined,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                    BulkExportDialog: true,
                    BulkRecrawlDialog: true,
                },
            },
        });

        const cb = wrapper.find('[data-testid="product-ready-toggle"]');
        expect(cb.exists()).toBe(true);
        await cb.setValue(true);
        expect(onToggleReady).toHaveBeenCalledWith('p-1', true);
    });

    it('calls onToggleLatestArrival when latest arrival checkbox is toggled', async () => {
        const onToggleLatestArrival = vi.fn(async () => undefined);
        const onUpdateAvailable = vi.fn(async () => undefined);
        const onUpdateMaintain = vi.fn(async () => undefined);

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
                        published_on_shopify: false,
                        is_ready: false,
                        latest_arrival: false,
                        available: null,
                        maintain: null,
                        pdp: { has_description: false, plamod_image_count: 0 },
                    },
                ],
                totalMatching: 1,
                selectionScopeKey: 'scope-1',
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => ({ queued: 0, batchId: '' }),
                onBulkExportSelected: async () => undefined,
                onBulkRecrawlSelected: async () => undefined,
                onUpdate: async () => undefined,
                onUpdateAvailable,
                onUpdateMaintain,
                onToggleReady: async () => undefined,
                onToggleLatestArrival,
                onToggleCritical: async () => undefined,
                onToggleDiscontinue: async () => undefined,
                onSelectAllMatching: async () => ['p-1'],
                onOpenPlamod: () => undefined,
                onOpenPoLines: () => undefined,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                    BulkExportDialog: true,
                    BulkRecrawlDialog: true,
                },
            },
        });

        const cb = wrapper.find('[data-testid="product-latest-arrival-toggle"]');
        expect(cb.exists()).toBe(true);
        await cb.setValue(true);
        expect(onToggleLatestArrival).toHaveBeenCalledWith('p-1', true);
    });

    it('calls onToggleCritical and onToggleDiscontinue from product name area', async () => {
        const onToggleCritical = vi.fn(async () => undefined);
        const onToggleDiscontinue = vi.fn(async () => undefined);

        const wrapper = mount(ProductsTable, {
            props: {
                loading: false,
                products: [
                    {
                        id: 'p-1',
                        sku: 'SKU-1',
                        barcode: null,
                        description: 'Test product',
                        type: null,
                        vendor: null,
                        published_on_shopify: false,
                        is_ready: false,
                        is_critical: false,
                        is_discontinued: false,
                        available: null,
                        maintain: null,
                        pdp: { has_description: false, plamod_image_count: 0 },
                    },
                ],
                totalMatching: 1,
                selectionScopeKey: 'scope-1',
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => ({ queued: 0, batchId: '' }),
                onBulkExportSelected: async () => undefined,
                onBulkRecrawlSelected: async () => undefined,
                onUpdate: async () => undefined,
                onUpdateAvailable: async () => undefined,
                onUpdateMaintain: async () => undefined,
                onToggleReady: async () => undefined,
                onToggleLatestArrival: async () => undefined,
                onToggleCritical,
                onToggleDiscontinue,
                onSelectAllMatching: async () => ['p-1'],
                onOpenPlamod: () => undefined,
                onOpenPoLines: () => undefined,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                    BulkExportDialog: true,
                    BulkRecrawlDialog: true,
                },
            },
        });

        expect(wrapper.find('[data-testid="product-critical-toggle"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Discontinue');

        const critical = wrapper.find('[data-testid="product-critical-toggle"]');
        expect(critical.exists()).toBe(true);
        await critical.setValue(true);
        expect(onToggleCritical).toHaveBeenCalledWith('p-1', true);

        const discontinue = wrapper.find('[data-testid="product-discontinue-toggle"]');
        expect(discontinue.exists()).toBe(true);
        await discontinue.setValue(true);
        expect(onToggleDiscontinue).toHaveBeenCalledWith('p-1', true);
    });

    it('renders inline available/maintain inputs and calls update callbacks', async () => {
        const onUpdateAvailable = vi.fn(async () => undefined);
        const onUpdateMaintain = vi.fn(async () => undefined);

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
                        published_on_shopify: false,
                        is_ready: false,
                        latest_arrival: false,
                        available: 2,
                        maintain: 3,
                        pdp: { has_description: false, plamod_image_count: 0 },
                    },
                ],
                totalMatching: 1,
                selectionScopeKey: 'scope-1',
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => ({ queued: 0, batchId: '' }),
                onBulkExportSelected: async () => undefined,
                onBulkRecrawlSelected: async () => undefined,
                onUpdate: async () => undefined,
                onUpdateAvailable,
                onUpdateMaintain,
                onToggleReady: async () => undefined,
                onToggleLatestArrival: async () => undefined,
                onToggleCritical: async () => undefined,
                onToggleDiscontinue: async () => undefined,
                onSelectAllMatching: async () => ['p-1'],
                onOpenPlamod: () => undefined,
                onOpenPoLines: () => undefined,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                    BulkExportDialog: true,
                    BulkRecrawlDialog: true,
                },
            },
        });

        const available = wrapper.find('[data-testid="product-available-input:p-1"]');
        expect(available.exists()).toBe(true);
        await available.trigger('focus');
        await available.setValue('5');
        await available.trigger('blur');

        const maintain = wrapper.find('[data-testid="product-maintain-input:p-1"]');
        expect(maintain.exists()).toBe(true);
        await maintain.trigger('focus');
        await maintain.setValue('6');
        await maintain.trigger('blur');

        await new Promise((r) => setTimeout(r, 0));

        expect(onUpdateAvailable).toHaveBeenCalledWith('p-1', 5);
        expect(onUpdateMaintain).toHaveBeenCalledWith('p-1', 6);
    });

    it('offers Select all products across pages after selecting the current page', async () => {
        const onSelectAllMatching = vi.fn(async () => ['p-1', 'p-2', 'p-3', 'p-4']);
        const onToggleLatestArrival = vi.fn(async () => undefined);
        const onUpdateAvailable = vi.fn(async () => undefined);
        const onUpdateMaintain = vi.fn(async () => undefined);

        const wrapper = mount(ProductsTable, {
            props: {
                loading: false,
                products: [
                    {
                        id: 'p-1',
                        sku: 'SKU-1',
                        barcode: null,
                        description: 'Test 1',
                        type: null,
                        vendor: null,
                        published_on_shopify: false,
                        is_ready: false,
                        available: null,
                        maintain: null,
                        pdp: { has_description: false, plamod_image_count: 0 },
                    },
                    {
                        id: 'p-2',
                        sku: 'SKU-2',
                        barcode: null,
                        description: 'Test 2',
                        type: null,
                        vendor: null,
                        published_on_shopify: false,
                        is_ready: false,
                        available: null,
                        maintain: null,
                        pdp: { has_description: false, plamod_image_count: 0 },
                    },
                ],
                totalMatching: 4,
                selectionScopeKey: 'scope-1',
                sortBy: 'sku',
                sortDir: 'asc',
                onSortChange: () => undefined,
                onRefresh: async () => undefined,
                onBulkDelete: async () => 0,
                onBulkUpdate: async () => 0,
                onBulkRenamePlamodAssets: async () => ({ queued: 0, batchId: '' }),
                onBulkExportSelected: async () => undefined,
                onBulkRecrawlSelected: async () => undefined,
                onUpdate: async () => undefined,
                onUpdateAvailable,
                onUpdateMaintain,
                onToggleReady: async () => undefined,
                onToggleLatestArrival,
                onToggleCritical: async () => undefined,
                onToggleDiscontinue: async () => undefined,
                onSelectAllMatching,
                onOpenPlamod: () => undefined,
                onOpenPoLines: () => undefined,
            },
            global: {
                stubs: {
                    ConfirmDialog: true,
                    BulkUpdateDialog: true,
                    BulkExportDialog: true,
                    BulkRecrawlDialog: true,
                },
            },
        });

        const selectAllOnPage = wrapper.find('thead input[type="checkbox"]');
        expect(selectAllOnPage.exists()).toBe(true);
        await selectAllOnPage.setValue(true);

        const selectAllMatchingBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Select all 4 products'));
        expect(selectAllMatchingBtn, 'Expected Select all 4 products button').toBeTruthy();

        await selectAllMatchingBtn!.trigger('click');
        expect(onSelectAllMatching).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('4');
        expect(wrapper.text()).toContain('selected');
    });
});
