import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { nextTick } from 'vue';

import InventoryCheckDetailPage from '../InventoryCheckDetailPage.vue';

vi.mock('../../lib/api', () => {
    return {
        api: {
            get: vi.fn(),
            post: vi.fn(),
            patch: vi.fn(),
        },
    };
});

import { api } from '../../lib/api';

function flush(): Promise<void> {
    return new Promise((r) => setTimeout(r, 0));
}

describe('InventoryCheckDetailPage export', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('uses shopify-content prepare endpoint for inventory-check products export', async () => {
        const checkId = '5b2c8bc1-dab8-4747-bbbb-3bf2ce1dc08e';
        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        const postMock = api.post as unknown as ReturnType<typeof vi.fn>;

        getMock.mockImplementation(async (url: string) => {
            if (url === `/api/v1/inventory-check/${checkId}`) {
                return {
                    status: 200,
                    data: {
                        data: {
                            id: checkId,
                            name: null,
                            source: 'upload',
                            uploaded_file_path: null,
                            workflow_state: 'applied',
                            created_by_role: 'employee',
                            applied_at: null,
                            counts: {
                                items: 3,
                                matched: 2,
                                unmatched: 1,
                                ambiguous: 0,
                                applied: 2,
                            },
                            items: [
                                {
                                    id: 1,
                                    product_id: 'p-1',
                                    handle: null,
                                    vendor: 'Dspiae',
                                    sku: 'SKU-1',
                                    type: 'PAINT',
                                    product_name: 'Product 1',
                                    english_name: null,
                                    available_amount: 1,
                                    selling_price: '9.99',
                                    quantity_in_store: 1,
                                    difference: 0,
                                    notes: null,
                                    match_status: 'matched',
                                    match_error: null,
                                    applied: true,
                                    applied_at: null,
                                },
                                {
                                    id: 2,
                                    product_id: null,
                                    handle: null,
                                    vendor: 'Dspiae',
                                    sku: 'NO-MATCH',
                                    type: 'PAINT',
                                    product_name: 'No match',
                                    english_name: null,
                                    available_amount: null,
                                    selling_price: null,
                                    quantity_in_store: 1,
                                    difference: null,
                                    notes: null,
                                    match_status: 'unmatched',
                                    match_error: 'No active product found',
                                    applied: false,
                                    applied_at: null,
                                },
                                {
                                    id: 3,
                                    product_id: 'p-2',
                                    handle: null,
                                    vendor: 'Dspiae',
                                    sku: 'SKU-2',
                                    type: 'PAINT',
                                    product_name: 'Product 2',
                                    english_name: null,
                                    available_amount: 2,
                                    selling_price: '10.99',
                                    quantity_in_store: 2,
                                    difference: 0,
                                    notes: null,
                                    match_status: 'matched',
                                    match_error: null,
                                    applied: true,
                                    applied_at: null,
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
                // Empty URL avoids calling window.location.assign in jsdom.
                return { status: 200, data: { download_url: '' } };
            }
            throw new Error(`unexpected POST ${url}`);
        });

        const router = createRouter({
            history: createMemoryHistory(),
            routes: [
                {
                    path: '/inventory-check/:id',
                    name: 'inventory-check-detail',
                    component: InventoryCheckDetailPage,
                },
            ],
        });
        await router.push({ name: 'inventory-check-detail', params: { id: checkId } });
        await router.isReady();

        const wrapper = mount(InventoryCheckDetailPage, {
            global: {
                plugins: [router],
                stubs: {
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

        const exportBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Export products'));
        expect(exportBtn).toBeTruthy();
        await exportBtn!.trigger('click');
        await wrapper.get('[data-testid="confirm"]').trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            '/api/v1/products/exports/shopify-content/prepare',
            { ids: ['p-1', 'p-2'] },
            expect.any(Object),
        );
        expect(wrapper.text()).toContain('Export failed (HTTP 200).');
    });

    it('sends increment quantity mode when applying inventory check', async () => {
        const checkId = '5b2c8bc1-dab8-4747-bbbb-3bf2ce1dc08e';
        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        const postMock = api.post as unknown as ReturnType<typeof vi.fn>;

        getMock.mockImplementation(async (url: string) => {
            if (url === `/api/v1/inventory-check/${checkId}`) {
                return {
                    status: 200,
                    data: {
                        data: {
                            id: checkId,
                            name: null,
                            source: 'upload',
                            uploaded_file_path: null,
                            workflow_state: 'ready_for_review',
                            created_by_role: 'employee',
                            applied_at: null,
                            counts: {
                                items: 1,
                                matched: 1,
                                unmatched: 0,
                                ambiguous: 0,
                                applied: 0,
                            },
                            items: [
                                {
                                    id: 1,
                                    product_id: 'p-1',
                                    handle: null,
                                    vendor: 'Dspiae',
                                    sku: 'SKU-1',
                                    type: 'PAINT',
                                    product_name: 'Product 1',
                                    english_name: null,
                                    available_amount: 1,
                                    selling_price: '9.99',
                                    quantity_in_store: 2,
                                    difference: 1,
                                    notes: null,
                                    match_status: 'matched',
                                    match_error: null,
                                    applied: false,
                                    applied_at: null,
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
            if (url === `/api/v1/inventory-check/${checkId}/apply`) {
                return { status: 200, data: { data: { applied: 1, skipped: 0, session: {} } } };
            }
            throw new Error(`unexpected POST ${url}`);
        });

        const router = createRouter({
            history: createMemoryHistory(),
            routes: [
                {
                    path: '/inventory-check/:id',
                    name: 'inventory-check-detail',
                    component: InventoryCheckDetailPage,
                },
            ],
        });
        await router.push({ name: 'inventory-check-detail', params: { id: checkId } });
        await router.isReady();

        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = mount(InventoryCheckDetailPage, {
            global: {
                plugins: [router],
                stubs: {
                    BulkExportDialog: true,
                },
            },
        });

        await nextTick();
        await flush();
        await nextTick();

        const modeSelect = wrapper
            .findAll('select')
            .find((s) => s.find('option[value="increment"]').exists());
        expect(modeSelect).toBeTruthy();
        await modeSelect!.setValue('increment');

        const applyBtn = wrapper.findAll('button').find((b) => b.text().includes('Apply quantities'));
        expect(applyBtn).toBeTruthy();
        await applyBtn!.trigger('click');
        await flush();

        expect(confirmSpy).toHaveBeenCalled();
        expect(postMock).toHaveBeenCalledWith(`/api/v1/inventory-check/${checkId}/apply`, {
            apply_quantity: true,
            apply_name: true,
            apply_quantity_mode: 'increment',
        });
    });
});
