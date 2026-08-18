import { describe, expect, it } from 'vitest';
import {
    calculatePlamodRestockExistingBudget,
    calculatePlamodRestockSuggestedSummary,
    defaultPlamodRestockPageState,
    filterPlamodRestockExistingRows,
    filterPlamodRestockNewRows,
    formatCostBreakdown,
    formatCostDeltaBadge,
    formatLineTotal,
    formatPlamodInstockSyncCompleteMessage,
    formatProductPrice,
    plamodRestockRowMatchesSearch,
    releaseDateLabel,
    sortPlamodRestockExistingRows,
    sortPlamodRestockNewRows,
    uniquePlamodRestockExistingTypes,
    uniquePlamodRestockSeries,
    type PlamodRestockCostBreakdown,
    type PlamodRestockExistingRow,
    type PlamodRestockNewRow,
} from '../plamodRestock';

describe('plamodRestock helpers', () => {
    it('formats cost breakdown with landed primary', () => {
        const cost: PlamodRestockCostBreakdown = {
            product: '11.80',
            shipping: '0.59',
            landed: '12.39',
        };
        expect(formatCostBreakdown(cost)).toEqual({
            landed: '$12.39',
            detail: '$11.80 + $0.59 ship',
        });
    });

    it('formats product price and line total', () => {
        const cost: PlamodRestockCostBreakdown = {
            product: '10.00',
            shipping: '0.50',
            landed: '10.50',
        };
        expect(formatProductPrice(cost)).toBe('$10.00');
        expect(formatLineTotal(cost)).toBe('$10.00');
        expect(formatProductPrice(null)).toBe('—');
    });

    it('formats plamod instock sync completion with imported vs expected', () => {
        expect(
            formatPlamodInstockSyncCompleteMessage({
                rows_upserted: 708,
                expected_row_count: 709,
            }),
        ).toBe('PLAMOD in-stock catalog refreshed: 708 of ~709 SKUs (99.9%).');

        expect(formatPlamodInstockSyncCompleteMessage({ rows_upserted: 689 })).toBe(
            'PLAMOD in-stock catalog refreshed: 689 SKUs.',
        );
    });

    it('formats line total as product cost only', () => {
        expect(
            formatLineTotal({
                product: '109.72',
                shipping: '5.48',
                landed: '115.20',
            }),
        ).toBe('$109.72');
    });

    it('sorts existing restock rows by sku', () => {
        const rows = sortPlamodRestockExistingRows(
            [
                {
                    product_uuid: 'b',
                    sku: '200',
                    product_name: 'B',
                    barcode: null,
                    type: '30MM',
                    release_date: null,
                    release_date_label: null,
                    is_recent_release: false,
                    available_qty: 0,
                    maintain_qty: 1,
                    not_arrived_qty: 0,
                    preorder_committed_qty: 0,
                    preorder_shipments: [],
                    reorder_qty: 1,
                    reorder_qty_override: null,
                    is_reorder_overridden: false,
                    proposed_qty: 1,
                    last_landed_cost: null,
                    new_landed_cost: null,
                    line_total: null,
                    cost_delta_high: false,
                    cost_delta_percent: null,
                    plamod_pdp_url: null,
                },
                {
                    product_uuid: 'a',
                    sku: '100',
                    product_name: 'A',
                    barcode: null,
                    type: 'ACTION BASE',
                    release_date: null,
                    release_date_label: null,
                    is_recent_release: false,
                    available_qty: 0,
                    maintain_qty: 1,
                    not_arrived_qty: 0,
                    preorder_committed_qty: 0,
                    preorder_shipments: [],
                    reorder_qty: 1,
                    reorder_qty_override: null,
                    is_reorder_overridden: false,
                    proposed_qty: 1,
                    last_landed_cost: null,
                    new_landed_cost: null,
                    line_total: null,
                    cost_delta_high: false,
                    cost_delta_percent: null,
                    plamod_pdp_url: null,
                },
            ],
            'sku',
            'asc',
        );

        expect(rows.map((row) => row.sku)).toEqual(['100', '200']);
    });

    it('sorts existing restock rows by preorder committed qty', () => {
        const rows = sortPlamodRestockExistingRows(
            [
                {
                    product_uuid: 'a',
                    sku: 'LOW',
                    product_name: 'Low',
                    barcode: null,
                    type: null,
                    release_date: null,
                    release_date_label: null,
                    is_recent_release: false,
                    available_qty: 0,
                    maintain_qty: 0,
                    not_arrived_qty: 0,
                    preorder_committed_qty: 1,
                    preorder_shipments: [],
                    reorder_qty: 0,
                    reorder_qty_override: null,
                    is_reorder_overridden: false,
                    proposed_qty: 0,
                    last_landed_cost: null,
                    new_landed_cost: null,
                    line_total: null,
                    cost_delta_high: false,
                    cost_delta_percent: null,
                    plamod_pdp_url: null,
                },
                {
                    product_uuid: 'b',
                    sku: 'HIGH',
                    product_name: 'High',
                    barcode: null,
                    type: null,
                    release_date: null,
                    release_date_label: null,
                    is_recent_release: false,
                    available_qty: 0,
                    maintain_qty: 0,
                    not_arrived_qty: 0,
                    preorder_committed_qty: 5,
                    preorder_shipments: [],
                    reorder_qty: 0,
                    reorder_qty_override: null,
                    is_reorder_overridden: false,
                    proposed_qty: 0,
                    last_landed_cost: null,
                    new_landed_cost: null,
                    line_total: null,
                    cost_delta_high: false,
                    cost_delta_percent: null,
                    plamod_pdp_url: null,
                },
            ],
            'preorder_committed_qty',
            'desc',
        );

        expect(rows.map((row) => row.sku)).toEqual(['HIGH', 'LOW']);
    });

    it('filters existing rows by ERP product type and calculates the visible budget', () => {
        const rows: PlamodRestockExistingRow[] = [
            {
                product_uuid: 'a',
                sku: 'ACTION-1',
                product_name: 'Action Base',
                barcode: null,
                type: 'ACTION BASE',
                release_date: null,
                release_date_label: null,
                is_recent_release: false,
                available_qty: 0,
                maintain_qty: 2,
                not_arrived_qty: 0,
                preorder_committed_qty: 0,
                preorder_shipments: [],
                reorder_qty: 2,
                reorder_qty_override: null,
                is_reorder_overridden: false,
                proposed_qty: 2,
                last_landed_cost: null,
                new_landed_cost: { product: '5.00', shipping: '0.25', landed: '5.25' },
                line_total: { product: '10.00', shipping: '0.00', landed: '10.00' },
                cost_delta_high: false,
                cost_delta_percent: null,
                plamod_pdp_url: null,
            },
            {
                product_uuid: 'b',
                sku: '30MM-1',
                product_name: '30MM Kit',
                barcode: null,
                type: '30MM',
                release_date: null,
                release_date_label: null,
                is_recent_release: false,
                available_qty: 0,
                maintain_qty: 1,
                not_arrived_qty: 0,
                preorder_committed_qty: 0,
                preorder_shipments: [],
                reorder_qty: 1,
                reorder_qty_override: null,
                is_reorder_overridden: false,
                proposed_qty: 1,
                last_landed_cost: null,
                new_landed_cost: { product: '20.00', shipping: '1.00', landed: '21.00' },
                line_total: { product: '20.00', shipping: '0.00', landed: '20.00' },
                cost_delta_high: false,
                cost_delta_percent: null,
                plamod_pdp_url: null,
            },
        ];

        expect(uniquePlamodRestockExistingTypes(rows)).toEqual(['30MM', 'ACTION BASE']);
        const filtered = filterPlamodRestockExistingRows(rows, {
            search: '',
            type: 'ACTION BASE',
        });
        expect(filtered.map((row) => row.sku)).toEqual(['ACTION-1']);
        expect(calculatePlamodRestockExistingBudget(filtered, 5)).toEqual({
            skuCount: 1,
            units: 2,
            product: '10.00',
            shipping: '0.50',
            landed: '10.50',
            linesWithMissingPrice: 0,
        });
    });

    it('counts unique existing products with a positive system suggestion', () => {
        expect(
            calculatePlamodRestockSuggestedSummary([
                { sku: 'A', reorder_qty: 2 },
                { sku: 'A', reorder_qty: 2 },
                { sku: 'B', reorder_qty: 0 },
                { sku: 'C', reorder_qty: 3 },
            ]),
        ).toEqual({
            uniqueProducts: 2,
            units: 5,
        });
    });

    it('formats cost delta badge', () => {
        expect(formatCostDeltaBadge(14.3)).toBe('↑ 14.3%');
        expect(formatCostDeltaBadge(-4.2)).toBe('↓ 4.2%');
    });

    it('prefers plamod release label over iso date', () => {
        expect(
            releaseDateLabel({
                release_date_label: 'Dec 1999',
                release_date: '1999-12-01',
            }),
        ).toBe('Dec 1999');
    });

    it('matches restock rows by sku, barcode, or product name terms', () => {
        const row = {
            sku: '5066293',
            product_name: 'SDW HEROES MUSHA GUNDAM THE 78th',
            barcode: '4573102662930',
        };

        expect(plamodRestockRowMatchesSearch(row, '')).toBe(true);
        expect(plamodRestockRowMatchesSearch(row, '5066293')).toBe(true);
        expect(plamodRestockRowMatchesSearch(row, 'musha gundam')).toBe(true);
        expect(plamodRestockRowMatchesSearch(row, '4573102662930')).toBe(true);
        expect(plamodRestockRowMatchesSearch(row, 'entry grade')).toBe(false);
    });

    it('defaults new section page state to recent-first sort', () => {
        expect(defaultPlamodRestockPageState()).toMatchObject({
            activeTab: 'existing',
            existingSearch: '',
            newSortBy: 'release_date',
            newSortDir: 'desc',
            filterUndecidedOnly: false,
            filterRecentOnly: false,
            filterSeries: '',
        });
    });

    it('sorts and filters new restock rows', () => {
        const rows: PlamodRestockNewRow[] = [
            {
                sku: '200',
                product_name: 'Older',
                barcode: null,
                series: 'HGUC',
                category: 'Plastic Model Kits',
                release_date: '2024-01-01',
                release_date_label: 'Jan 2024',
                is_recent_release: false,
                status: 'undecided',
                order_qty: null,
                planned_maintain_qty: null,
                last_landed_cost: null,
                new_landed_cost: { product: '20.00', shipping: '1.00', landed: '21.00' },
                line_total: null,
                cost_delta_high: false,
                cost_delta_percent: null,
                price_missing: false,
                image_url: null,
                plamod_pdp_url: null,
            },
            {
                sku: '100',
                product_name: 'Recent',
                barcode: null,
                series: '30MF',
                category: 'Plastic Model Kits',
                release_date: '2026-06-01',
                release_date_label: 'Jun 2026',
                is_recent_release: true,
                status: 'included',
                order_qty: 2,
                planned_maintain_qty: 3,
                last_landed_cost: null,
                new_landed_cost: { product: '10.00', shipping: '0.50', landed: '10.50' },
                line_total: { product: '20.00', shipping: '0.00', landed: '20.00' },
                cost_delta_high: false,
                cost_delta_percent: null,
                price_missing: false,
                image_url: 'https://cdn.example/100.jpg',
                plamod_pdp_url: 'https://plamod.com/100',
            },
        ];

        expect(uniquePlamodRestockSeries(rows)).toEqual(['30MF', 'HGUC']);
        expect(
            filterPlamodRestockNewRows(rows, {
                search: '',
                undecidedOnly: true,
                laterOnly: false,
                dismissedOnly: false,
                includedOnly: false,
                recentOnly: false,
                series: '',
            }).map((row) => row.sku),
        ).toEqual(['200']);
        expect(
            filterPlamodRestockNewRows(rows, {
                search: '',
                undecidedOnly: false,
                laterOnly: true,
                dismissedOnly: false,
                includedOnly: false,
                recentOnly: false,
                series: '',
            }).map((row) => row.sku),
        ).toEqual([]);
        expect(
            filterPlamodRestockNewRows(rows, {
                search: '',
                undecidedOnly: true,
                laterOnly: false,
                dismissedOnly: false,
                includedOnly: true,
                recentOnly: false,
                series: '',
            }).map((row) => row.sku),
        ).toEqual(['200', '100']);
        expect(
            sortPlamodRestockNewRows(rows, 'release_date', 'desc').map((row) => row.sku),
        ).toEqual(['100', '200']);
    });
});
