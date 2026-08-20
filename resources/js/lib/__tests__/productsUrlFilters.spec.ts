import { describe, expect, it } from 'vitest';

import {
    isProductsFiltersFromUrl,
    parseProductsUrlFilterState,
    PRODUCTS_FILTERS_FROM_URL,
} from '../productsUrlFilters';

describe('productsUrlFilters', () => {
    it('detects filters_from=url', () => {
        expect(isProductsFiltersFromUrl({ filters_from: PRODUCTS_FILTERS_FROM_URL })).toBe(true);
        expect(isProductsFiltersFromUrl({ filters_from: 'local' })).toBe(false);
    });

    it('parses main_types and types from repeated and bracketed query keys', () => {
        const state = parseProductsUrlFilterState({
            filters_from: PRODUCTS_FILTERS_FROM_URL,
            'main_types[]': 'model kit',
            'types[]': 'HG',
            available_min: '1',
            not_arrived_min: '1',
            missing_landed_cost: '1',
            has_landed_cost: 'true',
            not_arrived_include_draft_orders: '1',
        });

        expect(state).toEqual({
            mainTypes: ['model kit'],
            types: ['HG'],
            archived: 'active',
            availableMin: '1',
            availableMax: '',
            notArrived: '',
            notArrivedMin: '1',
            notArrivedIncludeDraftOrders: true,
            missingLandedCost: true,
            hasLandedCost: true,
        });
    });

    it('returns null when filters_from is absent', () => {
        expect(parseProductsUrlFilterState({ types: 'HG' })).toBeNull();
    });
});
