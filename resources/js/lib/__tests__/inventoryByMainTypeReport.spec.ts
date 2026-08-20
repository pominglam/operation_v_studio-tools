import { describe, expect, it } from 'vitest';

import {
    compareInventoryByMainTypeRows,
    formatInventoryLandedValue,
    groupInventoryRowsByStorefrontNavbar,
    mergeInventoryRowsByType,
    parseInventoryByMainTypeReportResponse,
    type InventoryByMainTypeReportRow,
} from '../inventoryByMainTypeReport';

describe('inventoryByMainTypeReport', () => {
    it('parses a valid report payload', () => {
        const report = parseInventoryByMainTypeReportResponse({
            data: {
                data_source: 'products',
                scope: 'active_products_on_hand_available_qty',
                currency: 'CAD',
                not_arrived_includes_draft_pos: true,
                rows: [
                    {
                        type: 'HG',
                        type_label: 'HG',
                        main_type: 'model kit',
                        catalog_skus: 2,
                        skus_on_hand: 1,
                        quantity_on_hand: 3,
                        not_arrived_skus: 1,
                        not_arrived: 5,
                        estimated_landed_value: '12.00',
                        estimated_not_landed_value: '0.00',
                        skus_missing_landed_cost: 0,
                        units_received: 0,
                        units_sold: 0,
                    },
                ],
                totals: {
                    catalog_skus: 2,
                    skus_on_hand: 1,
                    quantity_on_hand: 3,
                    not_arrived: 5,
                    estimated_landed_value: '12.00',
                    estimated_not_landed_value: '0.00',
                    skus_missing_landed_cost: 0,
                },
            },
        });

        expect(report.rows).toHaveLength(1);
        expect(report.totals.quantity_on_hand).toBe(3);
    });

    it('formats landed value as currency', () => {
        expect(formatInventoryLandedValue('16427.42', 'CAD')).toMatch(/16,427\.42|16427\.42/);
    });

    it('sorts rows by quantity descending', () => {
        const kit: InventoryByMainTypeReportRow = {
            type: 'HG',
            type_label: 'HG',
            main_type: 'model kit',
            catalog_skus: 1,
            skus_on_hand: 1,
            quantity_on_hand: 10,
            not_arrived_skus: 0,
            not_arrived: 0,
            estimated_landed_value: '10.00',
            estimated_not_landed_value: '0.00',
            skus_missing_landed_cost: 0,
            units_received: 0,
            units_sold: 0,
        };
        const tools: InventoryByMainTypeReportRow = {
            type: 'pliers',
            type_label: 'pliers',
            main_type: 'tools',
            catalog_skus: 1,
            skus_on_hand: 1,
            quantity_on_hand: 2,
            not_arrived_skus: 0,
            not_arrived: 0,
            estimated_landed_value: '2.00',
            estimated_not_landed_value: '0.00',
            skus_missing_landed_cost: 0,
            units_received: 0,
            units_sold: 0,
        };

        expect(compareInventoryByMainTypeRows(kit, tools, 'quantity_on_hand', 'desc')).toBeLessThan(
            0,
        );
    });

    it('groups types by storefront navbar main_type buckets', () => {
        const row = (
            type: string,
            mainType: string,
        ): InventoryByMainTypeReportRow => ({
            type,
            type_label: type,
            main_type: mainType,
            catalog_skus: 1,
            skus_on_hand: 1,
            quantity_on_hand: 1,
            not_arrived_skus: 0,
            not_arrived: 0,
            estimated_landed_value: '1.00',
            estimated_not_landed_value: '0.00',
            skus_missing_landed_cost: 0,
            units_received: 0,
            units_sold: 0,
        });

        const groups = groupInventoryRowsByStorefrontNavbar(
            [
                row('misc-item', 'misc'),
                row('decals', 'water decals'),
                row('tape', 'supplies'),
                row('HG', 'model kit'),
                row('paint', 'paints'),
                row('pliers', 'tools'),
                row('unknown', 'uncategorized'),
            ],
            'type_label',
            'asc',
        );

        expect(groups.map((group) => group.label)).toEqual([
            'Model kits',
            'Tools & Supplies',
            'Water decals',
            'Miscellaneous',
            'Other',
        ]);
        expect(groups[1].rows.map((item) => item.type)).toEqual(['paint', 'pliers', 'tape']);
        expect(groups[2].rows.map((item) => item.type)).toEqual(['decals']);
        expect(groups[1].totals.catalog_skus).toBe(3);
        expect(groups[4].rows[0].type).toBe('unknown');
    });

    it('merges duplicate type labels within a navbar group', () => {
        const row = (
            type: string,
            mainType: string,
            catalogSkus: number,
        ): InventoryByMainTypeReportRow => ({
            type,
            type_label: type,
            main_type: mainType,
            catalog_skus: catalogSkus,
            skus_on_hand: catalogSkus,
            quantity_on_hand: catalogSkus,
            not_arrived_skus: 0,
            not_arrived: 0,
            estimated_landed_value: '1.00',
            estimated_not_landed_value: '0.00',
            skus_missing_landed_cost: 0,
            units_received: catalogSkus,
            units_sold: 0,
        });

        const merged = mergeInventoryRowsByType([
            row('PAINT', 'paints', 5),
            row('PAINT', 'tools', 2),
            row('PAINT', 'supplies', 1),
        ]);

        expect(merged).toHaveLength(1);
        expect(merged[0]?.type_label).toBe('PAINT');
        expect(merged[0]?.catalog_skus).toBe(8);
        expect(merged[0]?.drill_down_main_types).toEqual(['paints', 'tools', 'supplies']);
    });
});
