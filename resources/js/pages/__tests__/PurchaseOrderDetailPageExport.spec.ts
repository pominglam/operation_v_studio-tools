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
    return new Promise((r) => setTimeout(r, 0));
}

describe('PurchaseOrderDetailPage export', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        sessionStorage.clear();
    });

    it('uses shopify-content prepare endpoint for Shopify content export', async () => {
        const poId = '00000000-0000-0000-0000-000000123456';
        const ids = ['p-1', 'p-2'];

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        const postMock = api.post as unknown as ReturnType<typeof vi.fn>;

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
                            counts: { items: 2 },
                            items: [
                                {
                                    id: 1,
                                    product_id: ids[0],
                                    product_name: 'P1',
                                    product_barcode: null,
                                    product_handle: null,
                                    sku: 'SKU-1',
                                    vendor: 'Plamod',
                                    unit_cost: null,
                                    vendor_unit_cost: null,
                                    qty_ordered: 1,
                                    qty_shipped: null,
                                    qty_received: null,
                                    available: 1,
                                    maintain: 2,
                                    not_arrived: 0,
                                    reorder: 1,
                                    total_ordered: 0,
                                    total_sold: 0,
                                    latest_landed_unit_cost: null,
                                    selling_price: null,
                                    multiplier: null,
                                },
                                {
                                    id: 2,
                                    product_id: ids[1],
                                    product_name: 'P2',
                                    product_barcode: null,
                                    product_handle: null,
                                    sku: 'SKU-2',
                                    vendor: 'Plamod',
                                    unit_cost: null,
                                    vendor_unit_cost: null,
                                    qty_ordered: 1,
                                    qty_shipped: null,
                                    qty_received: null,
                                    available: 1,
                                    maintain: 2,
                                    not_arrived: 0,
                                    reorder: 1,
                                    total_ordered: 0,
                                    total_sold: 0,
                                    latest_landed_unit_cost: null,
                                    selling_price: null,
                                    multiplier: null,
                                },
                            ],
                            created_at: null,
                            updated_at: null,
                        },
                    },
                };
            }
            throw new Error(`unexpected GET ${url}`);
        });

        postMock.mockImplementation(async (url: string) => {
            if (url === '/api/v1/products/exports/shopify-content/prepare') {
                // Return empty download_url so the page throws before window.location.assign (not mockable in jsdom).
                return { status: 200, data: { download_url: '' } };
            }
            throw new Error(`unexpected POST ${url}`);
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
                    ImportHandlesCard: true,
                    ImportInventoryQuantityOverrideCard: true,
                    BulkExportDialog: {
                        props: ['open'],
                        emits: ['confirm', 'cancel'],
                        template:
                            '<div><button v-if="open" data-testid="confirm" @click="$emit(\'confirm\', { exportType: \'shopify_content\' })">confirm</button></div>',
                    },
                },
            },
        });

        await nextTick();
        await flush();
        await nextTick();

        // Open export dialog.
        const exportBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Export products to Shopify (get handles)'));
        expect(exportBtn).toBeTruthy();
        await exportBtn!.trigger('click');

        // Confirm via stubbed dialog.
        await wrapper.get('[data-testid="confirm"]').trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            '/api/v1/products/exports/shopify-content/prepare',
            { ids },
            expect.any(Object),
        );

        // Error is shown because download_url was empty, but the key assertion is that we hit the correct endpoint.
        expect(wrapper.text()).toContain('export_failed');
    });

});
