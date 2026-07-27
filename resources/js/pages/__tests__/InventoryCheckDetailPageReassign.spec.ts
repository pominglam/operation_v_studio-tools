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

describe('InventoryCheckDetailPage reassign', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('shows Reassign on matched rows when the session is editable', async () => {
        const checkId = '5b2c8bc1-dab8-4747-bbbb-3bf2ce1dc08e';
        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;

        getMock.mockImplementation(async (url: string) => {
            if (url === `/api/v1/inventory-check/${checkId}`) {
                return {
                    status: 200,
                    data: {
                        data: {
                            id: checkId,
                            name: null,
                            source: 'employee_scan',
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
                                    id: 42,
                                    product_id: 'prod-uuid-1',
                                    barcode_scanned: '6977151547019',
                                    handle: null,
                                    vendor: null,
                                    sku: '6977151547019',
                                    type: null,
                                    product_name: 'Model Gap Scraper 0.2/1.5mm GS-0215',
                                    english_name: null,
                                    available_amount: 3,
                                    selling_price: null,
                                    quantity_in_store: 3,
                                    difference: 0,
                                    notes: null,
                                    match_status: 'matched',
                                    match_error: null,
                                    applied: false,
                                    applied_at: null,
                                },
                            ],
                            created_at: null,
                        },
                    },
                };
            }
            if (url === '/api/v1/products/filter-options') {
                return { status: 200, data: { data: { vendors: [], main_types: [], types: [] } } };
            }
            throw new Error(`unexpected GET ${url}`);
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
                    BulkExportDialog: true,
                    InventoryCheckResolveProductDialog: true,
                },
            },
        });

        await nextTick();
        await flush();
        await nextTick();

        expect(wrapper.text()).toContain('Reassign');
        expect(wrapper.text()).not.toContain('Resolve');
    });
});
