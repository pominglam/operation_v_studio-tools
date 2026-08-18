import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { nextTick } from 'vue';

import PurchaseOrderDetailPage from '../PurchaseOrderDetailPage.vue';

vi.mock('../../lib/api', () => {
    return {
        api: {
            get: vi.fn(),
            post: vi.fn(),
            patch: vi.fn(),
            put: vi.fn(),
            delete: vi.fn(),
        },
    };
});

import { api } from '../../lib/api';

function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

function baseItem(id: number, sku: string, available: number) {
    return {
        id,
        product_id: `p-${id}`,
        product_name: `Product ${sku}`,
        product_barcode: null,
        product_handle: null,
        sku,
        vendor: 'Plamod',
        unit_cost: '1.00',
        vendor_unit_cost: null,
        qty_ordered: 1,
        qty_shipped: 1,
        qty_received: 1,
        available,
        maintain: 1,
        not_arrived: 0,
        reorder: 0,
        total_ordered: 0,
        total_sold: 0,
        latest_landed_unit_cost: null,
        selling_price: null,
        multiplier: null,
    };
}

describe('PurchaseOrderDetailPage items sort', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('reorders rows when clicking Available column header', async () => {
        const poId = '00000000-0000-0000-0000-000000123456';

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        getMock.mockImplementation(async (url: string) => {
            if (url === `/api/v1/purchase-orders/${poId}`) {
                return {
                    status: 200,
                    data: {
                        data: {
                            id: poId,
                            vendor: 'Plamod',
                            supplier_order_id: null,
                            vendor_currency_code: 'CAD',
                            ordered_date: null,
                            shipped_date: null,
                            estimated_arrival_date: null,
                            received_date: null,
                            fully_on_shelves_date: null,
                            shipping_total: null,
                            surcharge_total: null,
                            product_total: null,
                            vendor_product_total: null,
                            fx_rate_to_cad: null,
                            fx_rate_cad_to_vendor: null,
                            notes: null,
                            workflow_checklist: null,
                            status: 'draft',
                            counts: { items: 3 },
                            items: [
                                baseItem(11, 'HIGH', 30),
                                baseItem(12, 'LOW', 2),
                                baseItem(13, 'MID', 10),
                            ],
                            created_at: null,
                        },
                    },
                };
            }

            if (url === '/api/v1/inventory-check') {
                return {
                    status: 200,
                    data: { data: [], meta: { last_page: 1 } },
                };
            }

            throw new Error(`unexpected GET ${url}`);
        });

        const router = createRouter({
            history: createMemoryHistory(),
            routes: [
                {
                    path: '/purchase-orders/:id',
                    name: 'purchase-order-detail',
                    component: PurchaseOrderDetailPage,
                },
            ],
        });
        await router.push({ name: 'purchase-order-detail', params: { id: poId } });
        await router.isReady();

        const wrapper = mount(PurchaseOrderDetailPage, {
            global: {
                plugins: [router],
                stubs: {
                    BulkUpdatePoItemsDialog: true,
                    BulkRecrawlDialog: true,
                    BulkExportDialog: true,
                    ImportHandlesCard: true,
                    ImportInventoryQuantityOverrideCard: true,
                },
            },
        });

        await nextTick();
        await flush();
        await nextTick();

        const skuOrder = (): string[] =>
            wrapper
                .findAll('tbody tr')
                .filter((row) => row.text().trim() !== 'No items match your search.')
                .map((row) => row.findAll('td')[1]?.text()?.trim() ?? '');

        expect(skuOrder()).toEqual(['HIGH', 'LOW', 'MID']);

        await wrapper.get('[data-testid="po-items-sort-available"]').trigger('click');
        await nextTick();

        expect(skuOrder()).toEqual(['LOW', 'MID', 'HIGH']);
        expect(wrapper.get('[data-testid="po-items-sort-available"]').text()).toContain('▲');

        await wrapper.get('[data-testid="po-items-sort-available"]').trigger('click');
        await nextTick();

        expect(skuOrder()).toEqual(['HIGH', 'MID', 'LOW']);
        expect(wrapper.get('[data-testid="po-items-sort-available"]').text()).toContain('▼');
    });
});
