import { describe, expect, it } from 'vitest';
import { computed } from 'vue';

import { useClientTableSort } from '../useClientTableSort';

describe('useClientTableSort', () => {
    it('sorts rows ascending and toggles to descending', () => {
        const sort = useClientTableSort<'cost'>();
        const rows = [
            { sku: 'HIGH', cost: '30.00' },
            { sku: 'LOW', cost: '10.00' },
        ];

        const sorted = computed(() => {
            const column = sort.sortBy.value;
            const direction = sort.sortDir.value;
            if (column === null) return rows;

            return [...rows].sort((left, right) => {
                const result = Number(left.cost) - Number(right.cost);
                return direction === 'asc' ? result : -result;
            });
        });

        expect(sorted.value.map((row) => row.sku)).toEqual(['HIGH', 'LOW']);

        sort.toggleSort('cost');

        expect(sorted.value.map((row) => row.sku)).toEqual(['LOW', 'HIGH']);
        expect(sort.sortIndicator('cost')).toBe(' ▲');

        sort.toggleSort('cost');

        expect(sorted.value.map((row) => row.sku)).toEqual(['HIGH', 'LOW']);
        expect(sort.sortIndicator('cost')).toBe(' ▼');
    });
});
