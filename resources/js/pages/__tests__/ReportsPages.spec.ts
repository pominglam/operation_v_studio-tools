import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import InventoryByMainTypeReportPage from '../InventoryByMainTypeReportPage.vue';
import ReportsLayoutPage from '../ReportsLayoutPage.vue';
import StaffOrdersReportPage from '../StaffOrdersReportPage.vue';

const apiGet = vi.fn();

vi.mock('../../lib/api', () => ({
    api: {
        get: (...args: unknown[]) => apiGet(...args),
    },
}));

function createReportsRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            {
                path: '/reports',
                component: ReportsLayoutPage,
                children: [
                    {
                        path: 'staff-orders',
                        name: 'reports-staff-orders',
                        component: StaffOrdersReportPage,
                    },
                    {
                        path: 'inventory-by-main-type',
                        name: 'reports-inventory-by-main-type',
                        component: InventoryByMainTypeReportPage,
                    },
                ],
            },
        ],
    });
}

describe('InventoryByMainTypeReportPage', () => {
    beforeEach(() => {
        apiGet.mockReset();
    });

    it('loads inventory by type report on mount', async () => {
        apiGet.mockResolvedValue({
            data: {
                data: {
                    data_source: 'products',
                    scope: 'on_hand_available_qty',
                    currency: 'CAD',
                    rows: [
                        {
                            type: 'HG',
                            type_label: 'HG',
                            main_type: 'model kit',
                            catalog_skus: 784,
                            skus_on_hand: 219,
                            quantity_on_hand: 434,
                            not_arrived: 120,
                            estimated_landed_value: '5000.00',
                            estimated_not_landed_value: '800.00',
                            skus_missing_landed_cost: 0,
                            units_received: 100,
                            units_sold: 50,
                        },
                    ],
                    totals: {
                        catalog_skus: 784,
                        skus_on_hand: 219,
                        quantity_on_hand: 434,
                        not_arrived: 120,
                        estimated_landed_value: '5000.00',
                        estimated_not_landed_value: '800.00',
                        skus_missing_landed_cost: 0,
                    },
                },
            },
        });

        mount(InventoryByMainTypeReportPage);
        await flushPromises();

        expect(apiGet).toHaveBeenCalledWith('/api/v1/reports/inventory-by-main-type');
    });

    it('renders storefront navbar groups in order and allows collapsing them', async () => {
        const makeRow = (type: string, mainType: string, quantity: number) => ({
            type,
            type_label: type,
            main_type: mainType,
            catalog_skus: 1,
            skus_on_hand: 1,
            quantity_on_hand: quantity,
            not_arrived_skus: 0,
            not_arrived: 0,
            estimated_landed_value: '10.00',
            estimated_not_landed_value: '0.00',
            skus_missing_landed_cost: 0,
            units_received: 0,
            units_sold: 0,
        });
        apiGet.mockResolvedValue({
            data: {
                data: {
                    data_source: 'products',
                    scope: 'on_hand_available_qty',
                    currency: 'CAD',
                    rows: [
                        makeRow('pliers', 'tools', 20),
                        makeRow('HG', 'model kit', 10),
                        makeRow('misc-item', 'misc', 30),
                    ],
                    totals: {
                        catalog_skus: 3,
                        skus_on_hand: 3,
                        quantity_on_hand: 60,
                        not_arrived_skus: 0,
                        not_arrived: 0,
                        estimated_landed_value: '30.00',
                        estimated_not_landed_value: '0.00',
                        skus_missing_landed_cost: 0,
                    },
                },
            },
        });

        const wrapper = mount(InventoryByMainTypeReportPage);
        await flushPromises();

        const text = wrapper.text();
        expect(text.indexOf('Model kits')).toBeLessThan(text.indexOf('Tools & Supplies'));
        expect(text.indexOf('Tools & Supplies')).toBeLessThan(text.indexOf('Miscellaneous'));
        expect(wrapper.find('[data-testid="type-row-hg"]').exists()).toBe(true);

        await wrapper.get('button[aria-label="Collapse Model kits"]').trigger('click');

        expect(wrapper.find('[data-testid="type-row-hg"]').exists()).toBe(false);
        expect(
            wrapper.get('button[aria-label="Expand Model kits"]').attributes('aria-expanded'),
        ).toBe('false');
    });
});

describe('ReportsLayoutPage', () => {
    it('renders report navigation links', async () => {
        const router = createReportsRouter();
        router.push('/reports/staff-orders');
        await router.isReady();

        const wrapper = mount(ReportsLayoutPage, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Staff orders');
        expect(wrapper.text()).toContain('Inventory by type');
    });
});
