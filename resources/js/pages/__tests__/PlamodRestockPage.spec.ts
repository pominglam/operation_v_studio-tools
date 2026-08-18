import { mount, flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import PlamodRestockPage from '../PlamodRestockPage.vue';
import {
    PLAMOD_RESTOCK_CART_DISMISSED_RUN_KEY,
    PLAMOD_RESTOCK_PAGE_STATE_KEY,
} from '../../lib/plamodRestock';

const apiGet = vi.fn();
const apiPost = vi.fn();
const apiPut = vi.fn();
const apiPatch = vi.fn();

vi.mock('../../lib/api', () => ({
    api: {
        get: (...args: unknown[]) => apiGet(...args),
        post: (...args: unknown[]) => apiPost(...args),
        put: (...args: unknown[]) => apiPut(...args),
        patch: (...args: unknown[]) => apiPatch(...args),
    },
}));

describe('PlamodRestockPage', () => {
    beforeEach(() => {
        window.localStorage.removeItem(PLAMOD_RESTOCK_PAGE_STATE_KEY);
        window.localStorage.removeItem(PLAMOD_RESTOCK_CART_DISMISSED_RUN_KEY);
        apiGet.mockReset();
        apiPost.mockReset();
        apiPut.mockReset();
        apiPatch.mockReset();

        apiGet.mockImplementation(async (url: string) => {
            if (url === '/api/v1/plamod/restock/proposal') {
                return {
                    data: {
                        ok: true,
                        data: {
                            snapshot: {
                                sync_log_id: 1,
                                synced_at: '2026-07-27T12:00:00Z',
                                item_count: 2,
                            },
                            shipping_percent: 5,
                            exclusions: {
                                excluded_series: [],
                                excluded_product_terms: [],
                            },
                            existing: [
                                {
                                    product_uuid: 'uuid-1',
                                    sku: '111',
                                    product_name: 'Existing',
                                    barcode: null,
                                    type: 'ACTION BASE',
                                    release_date: '2025-04-01',
                                    release_date_label: 'Apr 2025',
                                    is_recent_release: true,
                                    available_qty: 1,
                                    maintain_qty: 4,
                                    not_arrived_qty: 0,
                                    preorder_committed_qty: 4,
                                    preorder_shipments: [
                                        {
                                            offer_id: '5496',
                                            quantity: 2,
                                            eta_date: '2025-10-15',
                                            eta_label: 'Oct 15',
                                            po_due_date: null,
                                        },
                                        {
                                            offer_id: '5972',
                                            quantity: 2,
                                            eta_date: '2026-02-28',
                                            eta_label: 'Feb 28',
                                            po_due_date: null,
                                        },
                                    ],
                                    reorder_qty: 3,
                                    reorder_qty_override: null,
                                    is_reorder_overridden: false,
                                    proposed_qty: 3,
                                    last_landed_cost: {
                                        product: '10.00',
                                        shipping: '0.50',
                                        landed: '10.50',
                                    },
                                    new_landed_cost: {
                                        product: '12.00',
                                        shipping: '0.60',
                                        landed: '12.60',
                                    },
                                    line_total: {
                                        product: '36.00',
                                        shipping: '1.80',
                                        landed: '37.80',
                                    },
                                    cost_delta_high: true,
                                    cost_delta_percent: 14.3,
                                    plamod_pdp_url: 'https://plamod.com/retailer/products/111',
                                },
                            ],
                            new_products: [
                                {
                                    sku: '222',
                                    product_name: 'New kit',
                                    barcode: '1234567890123',
                                    series: 'HGUC',
                                    category: 'Plastic Model Kits',
                                    release_date: null,
                                    release_date_label: null,
                                    is_recent_release: false,
                                    status: 'undecided',
                                    order_qty: null,
                                    planned_maintain_qty: null,
                                    last_landed_cost: null,
                                    new_landed_cost: {
                                        product: '15.00',
                                        shipping: '0.75',
                                        landed: '15.75',
                                    },
                                    line_total: null,
                                    cost_delta_high: false,
                                    cost_delta_percent: null,
                                    price_missing: false,
                                    image_url: 'https://cdn.example/new.jpg',
                                    plamod_pdp_url: 'https://plamod.com/retailer/products/222',
                                },
                            ],
                            totals: {
                                unique_products: 1,
                                units: 3,
                                product: '36.00',
                                shipping: '1.80',
                                landed: '37.80',
                                lines_with_missing_price: 0,
                                existing: {
                                    unique_products: 1,
                                    units: 3,
                                    product: '36.00',
                                    shipping: '1.80',
                                    landed: '37.80',
                                    lines_with_missing_price: 0,
                                },
                                new_products: {
                                    unique_products: 0,
                                    units: 0,
                                    product: '0.00',
                                    shipping: '0.00',
                                    landed: '0.00',
                                    lines_with_missing_price: 0,
                                },
                            },
                            meta: {
                                existing_count: 1,
                                new_count: 1,
                                dismissed_count: 0,
                                undecided_new_count: 1,
                                included_new_count: 0,
                                new_missing_price_count: 0,
                            },
                        },
                    },
                };
            }
            if (url === '/api/v1/plamod/restock/sync-status') {
                return {
                    data: {
                        data: {
                            status: 'completed',
                            counts: {
                                rows_upserted: 708,
                                expected_row_count: 709,
                            },
                        },
                    },
                };
            }
            return { data: { data: { status: 'never' } } };
        });
    });

    it('loads proposal and renders existing and new tables', async () => {
        const wrapper = mount(PlamodRestockPage);
        await flushPromises();

        expect(apiGet).toHaveBeenCalledWith('/api/v1/plamod/restock/proposal', expect.any(Object));
        expect(
            wrapper.get('[data-testid="restock-tab-existing"]').attributes('aria-selected'),
        ).toBe('true');
        expect(wrapper.get('[data-testid="restock-existing-panel"]').attributes('style')).toBe(
            undefined,
        );
        expect(wrapper.get('[data-testid="restock-new-panel"]').attributes('style')).toContain(
            'display: none',
        );
        expect(wrapper.get('[data-testid="restock-existing-table"]').text()).toContain('Existing');
        expect(wrapper.get('[data-testid="restock-new-table"]').text()).toContain('New kit');
        expect(wrapper.get('[data-testid="restock-existing-cart-column"]').text()).toBe('Cart');
        expect(wrapper.get('[data-testid="restock-existing-suggested-summary"]').text()).toContain(
            'System suggests 1 unique product · 3 units',
        );
        expect(wrapper.get('[data-testid="restock-preorder-qty-4"]').text()).toBe('4');
        await wrapper.get('[data-testid="restock-preorder-qty-4"]').trigger('click');
        expect(wrapper.get('[data-testid="restock-preorder-breakdown"]').text()).toContain(
            'Offer 5496',
        );
        expect(wrapper.get('[data-testid="restock-preorder-breakdown"]').text()).toContain(
            'Offer 5972',
        );
        expect(wrapper.get('[data-testid="restock-new-bulk-column"]').text()).toBe('Bulk');
        expect(wrapper.get('[data-testid="restock-new-cart-column"]').text()).toBe('Cart');
        expect(wrapper.text()).toContain('$12.00');
        expect(wrapper.text()).toContain('$36.00');
        expect(wrapper.text()).toContain('708 of ~709 SKUs');
        expect(wrapper.get('[data-testid="restock-cost-existing"]').text()).toContain('$37.80');
        expect(wrapper.get('[data-testid="restock-cost-new"]').text()).toContain('$0.00');
        expect(wrapper.get('[data-testid="restock-cost-total"]').text()).toContain(
            'Unique products1',
        );
        expect(wrapper.get('[data-testid="restock-cost-existing"]').text()).toContain(
            'Unique products1',
        );
        expect(wrapper.get('[data-testid="restock-cost-new"]').text()).toContain(
            'Unique products0',
        );

        await wrapper.get('[data-testid="restock-existing-type-filter"]').setValue('ACTION BASE');
        expect(wrapper.get('[data-testid="restock-existing-type-budget"]').text()).toContain(
            'ACTION BASE budget',
        );
        expect(wrapper.get('[data-testid="restock-existing-type-budget"]').text()).toContain(
            '3 unit(s)',
        );
        expect(wrapper.get('[data-testid="restock-existing-type-budget"]').text()).toContain(
            'Product $36.00',
        );

        await wrapper.get('[data-testid="restock-tab-new"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="restock-tab-new"]').attributes('aria-selected')).toBe(
            'true',
        );
        expect(wrapper.get('[data-testid="restock-existing-panel"]').attributes('style')).toContain(
            'display: none',
        );
        expect(wrapper.get('[data-testid="restock-new-panel"]').attributes('style')).not.toContain(
            'display: none',
        );
        expect(
            JSON.parse(window.localStorage.getItem(PLAMOD_RESTOCK_PAGE_STATE_KEY) ?? '{}'),
        ).toEqual(expect.objectContaining({ activeTab: 'new' }));
    });

    it('shows sync progress while refresh is running', async () => {
        apiGet.mockImplementation(async (url: string) => {
            if (url === '/api/v1/plamod/restock/proposal') {
                return {
                    data: {
                        ok: true,
                        data: {
                            snapshot: {
                                sync_log_id: 1,
                                synced_at: '2026-07-27T12:00:00Z',
                                item_count: 2,
                            },
                            shipping_percent: 5,
                            exclusions: {
                                excluded_series: [],
                                excluded_product_terms: [],
                            },
                            existing: [],
                            new_products: [],
                            totals: {
                                unique_products: 0,
                                units: 0,
                                product: '0.00',
                                shipping: '0.00',
                                landed: '0.00',
                                lines_with_missing_price: 0,
                            },
                            meta: {
                                existing_count: 0,
                                new_count: 0,
                                dismissed_count: 0,
                                undecided_new_count: 0,
                                included_new_count: 0,
                            },
                        },
                    },
                };
            }
            if (url === '/api/v1/plamod/restock/sync-status') {
                return {
                    data: {
                        data: {
                            status: 'running',
                            counts: {
                                phase: 'export',
                                filters_processed: 3,
                                filters_total: 49,
                                current_filter: 'HGUC',
                            },
                        },
                    },
                };
            }
            return { data: {} };
        });

        const wrapper = mount(PlamodRestockPage);
        await flushPromises();

        expect(wrapper.get('[data-testid="restock-sync-progress"]').text()).toContain(
            'Exporting filters 3/49',
        );
        expect(wrapper.get('[data-testid="restock-sync-progress"]').text()).toContain('HGUC');
    });

    it('queues plamod refresh', async () => {
        apiPost.mockResolvedValueOnce({ data: { data: { ok: true, sync_log_id: 9 } } });

        const wrapper = mount(PlamodRestockPage);
        await flushPromises();

        await wrapper.get('[data-testid="restock-refresh-plamod"]').trigger('click');
        await flushPromises();

        expect(apiPost).toHaveBeenCalledWith('/api/v1/plamod/restock/sync');
    });

    it('resumes cart polling after loading an active run', async () => {
        vi.useFakeTimers();
        const defaultGet = apiGet.getMockImplementation();
        apiGet.mockImplementation(async (url: string) => {
            if (url === '/api/v1/plamod/restock/cart-run-status') {
                return {
                    data: {
                        data: {
                            status: 'running',
                            cart_run_id: 42,
                            counts: { phase: 'adding', items_processed: 1, items_total: 2 },
                        },
                    },
                };
            }
            return defaultGet?.(url);
        });

        const wrapper = mount(PlamodRestockPage);
        await flushPromises();
        expect(
            wrapper.get('[data-testid="restock-refresh-plamod"]').attributes('disabled'),
        ).toBeDefined();

        const callsBefore = apiGet.mock.calls.filter(
            ([url]) => url === '/api/v1/plamod/restock/cart-run-status',
        ).length;
        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();
        const callsAfter = apiGet.mock.calls.filter(
            ([url]) => url === '/api/v1/plamod/restock/cart-run-status',
        ).length;
        expect(callsAfter).toBeGreaterThan(callsBefore);

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('dismisses a completed cart report until a newer run exists', async () => {
        const defaultGet = apiGet.getMockImplementation();
        apiGet.mockImplementation(async (url: string) => {
            if (url === '/api/v1/plamod/restock/cart-run-status') {
                return {
                    data: {
                        data: {
                            status: 'completed',
                            cart_run_id: 43,
                            all_verified: false,
                            report: {
                                summary: {
                                    requested_lines: 1,
                                    verified: 0,
                                    partial: 0,
                                    over_added: 0,
                                    missing: 1,
                                    add_failed: 0,
                                    already_satisfied: 0,
                                    all_verified: false,
                                },
                                lines: [
                                    {
                                        sku: '111',
                                        requested_qty: 3,
                                        verification_status: 'missing',
                                        error_message: 'Requested 3 but PLAMOD MOQ is 4.',
                                    },
                                ],
                            },
                        },
                    },
                };
            }
            return defaultGet?.(url);
        });

        const wrapper = mount(PlamodRestockPage);
        await flushPromises();
        expect(wrapper.find('[data-testid="restock-cart-report"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="restock-cart-report"]').text()).toContain(
            'PLAMOD message: Requested 3 but PLAMOD MOQ is 4.',
        );

        await wrapper.get('[data-testid="restock-cart-dismiss"]').trigger('click');

        expect(wrapper.find('[data-testid="restock-cart-report"]').exists()).toBe(false);
        expect(window.localStorage.getItem(PLAMOD_RESTOCK_CART_DISMISSED_RUN_KEY)).toBe('43');
    });

    it('filters only existing products from the existing-products search bar', async () => {
        const wrapper = mount(PlamodRestockPage);
        await flushPromises();

        expect(wrapper.get('[data-testid="restock-existing-table"]').text()).toContain('Existing');
        expect(wrapper.get('[data-testid="restock-new-table"]').text()).toContain('New kit');

        await wrapper.get('[data-testid="restock-existing-search"]').setValue('Existing');
        await flushPromises();

        expect(wrapper.get('[data-testid="restock-existing-table"]').text()).toContain('Existing');
        expect(wrapper.get('[data-testid="restock-new-table"]').text()).toContain('New kit');
        expect(wrapper.text()).toContain('1 of 1 rows');

        await wrapper
            .get('[data-testid="restock-existing-search"]')
            .setValue('No matching product');
        await flushPromises();

        expect(wrapper.get('[data-testid="restock-existing-table"]').text()).not.toContain(
            'Existing',
        );
        expect(wrapper.get('[data-testid="restock-new-table"]').text()).toContain('New kit');
    });

    it('keeps multiple new-product status filters selected', async () => {
        const wrapper = mount(PlamodRestockPage);
        await flushPromises();
        await wrapper.get('[data-testid="restock-tab-new"]').trigger('click');

        await wrapper.get('[data-testid="restock-filter-included"]').setValue(true);
        await flushPromises();
        await wrapper.get('[data-testid="restock-filter-undecided"]').setValue(true);
        await flushPromises();

        expect(
            (wrapper.get('[data-testid="restock-filter-undecided"]').element as HTMLInputElement)
                .checked,
        ).toBe(true);
        expect(
            (wrapper.get('[data-testid="restock-filter-included"]').element as HTMLInputElement)
                .checked,
        ).toBe(true);
        expect(apiGet).toHaveBeenLastCalledWith('/api/v1/plamod/restock/proposal', {
            params: { hide_dismissed: 1, only_included_new: 0 },
        });
    });

    it('ignores a stale proposal response after filters change again', async () => {
        const defaultGet = apiGet.getMockImplementation();
        const baselineResponse = await defaultGet?.('/api/v1/plamod/restock/proposal');
        const staleResponse = structuredClone(baselineResponse);
        const latestResponse = structuredClone(baselineResponse);
        staleResponse.data.data.new_products[0].product_name = 'Stale dismissed result';
        latestResponse.data.data.new_products[0].product_name = 'Latest hidden result';

        const wrapper = mount(PlamodRestockPage);
        await flushPromises();
        await wrapper.get('[data-testid="restock-tab-new"]').trigger('click');

        let resolveStale!: (value: unknown) => void;
        let resolveLatest!: (value: unknown) => void;
        const stalePromise = new Promise((resolve) => {
            resolveStale = resolve;
        });
        const latestPromise = new Promise((resolve) => {
            resolveLatest = resolve;
        });

        apiGet.mockImplementation(
            async (url: string, config?: { params?: { hide_dismissed?: number } }) => {
                if (url === '/api/v1/plamod/restock/proposal') {
                    return config?.params?.hide_dismissed === 0 ? stalePromise : latestPromise;
                }

                return defaultGet?.(url);
            },
        );

        await wrapper.get('[data-testid="restock-filter-dismissed"]').setValue(true);
        await wrapper.get('[data-testid="restock-filter-dismissed"]').setValue(false);
        await wrapper.get('[data-testid="restock-hide-dismissed"]').setValue(true);

        resolveLatest(latestResponse);
        await flushPromises();
        resolveStale(staleResponse);
        await flushPromises();

        expect(wrapper.get('[data-testid="restock-new-table"]').text()).toContain(
            'Latest hidden result',
        );
        expect(wrapper.get('[data-testid="restock-new-table"]').text()).not.toContain(
            'Stale dismissed result',
        );
    });

    it('renders erp product link and maintain input', async () => {
        const wrapper = mount(PlamodRestockPage);
        await flushPromises();

        const erpLink = wrapper.get('[data-testid="restock-erp-product-link"]');
        expect(erpLink.attributes('href')).toContain('/products?search=111');
        expect(erpLink.attributes('target')).toBe('_blank');
        expect(wrapper.get('[data-testid="restock-maintain-111"]').exists()).toBe(true);
    });

    it('opens include dialog with blank qty defaults', async () => {
        const wrapper = mount(PlamodRestockPage);
        await flushPromises();

        const includeButton = wrapper
            .findAll('button')
            .find((btn) => btn.text().trim() === 'Include');
        expect(includeButton).toBeDefined();
        await includeButton!.trigger('click');
        await flushPromises();

        const dialog = wrapper.get('[data-testid="restock-include-dialog"]');
        const inputs = dialog.findAll('input[type="number"]');
        expect((inputs[0]!.element as HTMLInputElement).value).toBe('');
        expect((inputs[1]!.element as HTMLInputElement).value).toBe('');
    });

    it('persists zero order qty for an included new product', async () => {
        const wrapper = mount(PlamodRestockPage);
        await flushPromises();
        const vm = wrapper.vm as unknown as {
            proposal: {
                new_products: Array<{
                    sku: string;
                    status: string;
                    order_qty: number | null;
                    planned_maintain_qty: number | null;
                }>;
            };
            newOrderDrafts: Record<string, string>;
            newMaintainDrafts: Record<string, string>;
            saveNewIncludedQtys: (sku: string) => Promise<void>;
        };
        const row = vm.proposal.new_products[0]!;
        row.status = 'included';
        row.order_qty = 2;
        row.planned_maintain_qty = 1;
        vm.newOrderDrafts[row.sku] = '0';
        vm.newMaintainDrafts[row.sku] = '1';
        apiPut.mockResolvedValue({ data: { ok: true } });

        await vm.saveNewIncludedQtys(row.sku);

        expect(apiPut).toHaveBeenCalledWith('/api/v1/plamod/restock/decisions/222', {
            status: 'included',
            order_qty: 0,
            planned_maintain_qty: 1,
        });
    });

    it('shows bulk bar when new rows are selected', async () => {
        const wrapper = mount(PlamodRestockPage);
        await flushPromises();

        await wrapper.get('[data-testid="restock-new-select-222"]').setValue(true);
        await flushPromises();

        expect(wrapper.get('[data-testid="restock-new-bulk-bar"]').text()).toContain('1 selected');
    });

    it('shows one mismatch headline without duplicating it as an error', async () => {
        apiPost.mockResolvedValue({
            data: {
                data: {
                    ok: true,
                    order_matches_cart: false,
                    error_summary:
                        'Full order verification incomplete: 0/1 lines match, 1 partial.',
                    verified_at: '2026-08-15T07:03:48.632Z',
                    summary: {
                        requested_lines: 1,
                        verified: 0,
                        partial: 1,
                        over_added: 0,
                        missing: 0,
                        add_failed: 0,
                        already_satisfied: 0,
                        all_verified: false,
                        extra_cart_lines: 0,
                        order_matches_cart: false,
                    },
                    report: {
                        cart_url: 'https://plamod.com/retailer/cart',
                        verified_at: '2026-08-15T07:03:48.632Z',
                        cart_item_badge_count: 1,
                        cart_lines_detected: 1,
                        summary: {
                            requested_lines: 1,
                            verified: 0,
                            partial: 1,
                            over_added: 0,
                            missing: 0,
                            add_failed: 0,
                            already_satisfied: 0,
                            all_verified: false,
                            extra_cart_lines: 0,
                            order_matches_cart: false,
                        },
                        lines: [
                            {
                                sku: '111',
                                product_name: 'Existing',
                                requested_qty: 3,
                                preorder_arrived_qty: 2,
                                target_instock_qty: 3,
                                cart_qty_after: 2,
                                verification_status: 'partial',
                                error_message: 'Requested 3 but PLAMOD MOQ is 4.',
                            },
                        ],
                    },
                },
            },
        });

        const wrapper = mount(PlamodRestockPage);
        await flushPromises();
        await wrapper.get('[data-testid="restock-verify-full-order"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="restock-order-verify-headline"]').text()).toContain(
            '0/1 lines match',
        );
        expect(wrapper.find('[data-testid="restock-order-verify-error"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="restock-order-verify-row-111"]').text()).toContain(
            '3232Partial',
        );
        expect(wrapper.get('[data-testid="restock-order-verify-report"]').text()).toContain(
            'Planned qty',
        );
        expect(wrapper.get('[data-testid="restock-order-verify-report"]').text()).toContain(
            'Arrived preorder qty',
        );
        expect(wrapper.get('[data-testid="restock-order-verify-report"]').text()).toContain(
            'Required in-stock qty',
        );
        expect(wrapper.get('[data-testid="restock-order-verify-report"]').text()).toContain(
            'In-stock cart qty',
        );
        expect(wrapper.get('[data-testid="restock-order-verify-row-111"]').text()).toContain(
            '2 arrived preorder units shown separately; 1 additional in-stock unit still missing.',
        );
        expect(wrapper.get('[data-testid="restock-order-verify-row-111"]').text()).toContain(
            'PLAMOD message: Requested 3 but PLAMOD MOQ is 4.',
        );
        const productLink = wrapper.get('[data-testid="restock-order-verify-product-111"]');
        expect(productLink.attributes('href')).toBe('https://plamod.com/retailer/products/111');
        expect(productLink.attributes('target')).toBe('_blank');
        expect(productLink.attributes('rel')).toBe('noopener noreferrer');
        expect(wrapper.get('[data-testid="restock-order-verify-fix-mismatches"]').text()).toContain(
            'Fix mismatches (1)',
        );
    });

    it('saves a product-line rule that automatically hides future matches', async () => {
        const wrapper = mount(PlamodRestockPage);
        await flushPromises();

        await wrapper.get('[data-testid="restock-exclusion-product-term"]').setValue('ACTION BASE');
        await wrapper.get('[data-testid="restock-add-product-exclusion"]').trigger('click');
        await flushPromises();

        expect(apiPut).toHaveBeenCalledWith('/api/v1/plamod/restock/settings', {
            shipping_percent: 5,
            excluded_series: [],
            excluded_product_terms: ['ACTION BASE'],
        });
        expect(apiGet).toHaveBeenCalledWith('/api/v1/plamod/restock/proposal', {
            params: { hide_dismissed: 1, only_included_new: 0 },
        });
    });
});
