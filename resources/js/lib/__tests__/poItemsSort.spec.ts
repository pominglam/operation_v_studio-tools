import { describe, expect, it } from 'vitest';

import { comparePoItems, sortPoItems, type PoItemSortRow } from '../poItemsSort';

function row(partial: Partial<PoItemSortRow> & Pick<PoItemSortRow, 'sku'>): PoItemSortRow {
    return {
        product_name: null,
        vendor: 'Plamod',
        unit_cost: null,
        available: null,
        maintain: null,
        not_arrived: null,
        reorder: null,
        total_ordered: null,
        total_sold: null,
        selling_price: null,
        latest_landed_unit_cost: null,
        multiplier: null,
        qty_ordered: null,
        qty_shipped: null,
        qty_received: null,
        ...partial,
    };
}

describe('poItemsSort', () => {
    it('sorts SKUs ascending and descending', () => {
        const items = [row({ sku: 'B-2' }), row({ sku: 'A-1' }), row({ sku: 'C-3' })];

        expect(sortPoItems(items, 'sku', 'asc', null, null).map((item) => item.sku)).toEqual([
            'A-1',
            'B-2',
            'C-3',
        ]);
        expect(sortPoItems(items, 'sku', 'desc', null, null).map((item) => item.sku)).toEqual([
            'C-3',
            'B-2',
            'A-1',
        ]);
    });

    it('sorts numeric columns with nulls last', () => {
        const items = [
            row({ sku: 'a', available: null }),
            row({ sku: 'b', available: 5 }),
            row({ sku: 'c', available: 1 }),
        ];

        expect(sortPoItems(items, 'available', 'asc', null, null).map((item) => item.sku)).toEqual([
            'c',
            'b',
            'a',
        ]);
    });

    it('sorts landed cost using unit cost plus per-unit fees', () => {
        const items = [
            row({ sku: 'cheap', unit_cost: '1.00' }),
            row({ sku: 'expensive', unit_cost: '5.00' }),
        ];

        expect(
            comparePoItems(items[0], items[1], 'landed', 50, 25) < 0,
        ).toBe(true);
        expect(
            sortPoItems(items, 'landed', 'asc', 50, 25).map((item) => item.sku),
        ).toEqual(['cheap', 'expensive']);
    });
});
