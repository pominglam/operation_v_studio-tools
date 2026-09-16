import { describe, expect, it } from 'vitest';

import {
    PRODUCTS_LIST_VIEW_KEYS,
    defaultProductsListView,
    parseProductsListViewSnapshot,
    productsListViewsEqual,
    productsQueryWithoutListFilters,
} from '../productsListView';

describe('productsListView', () => {
    it('default snapshot includes every list filter and sort field operators can change', () => {
        expect(PRODUCTS_LIST_VIEW_KEYS).toEqual(
            [
                'archivedFilter',
                'availableMaxFilter',
                'availableMinFilter',
                'bulkSearchText',
                'hasLandedCostFilter',
                'missingLandedCostFilter',
                'notArrivedFilter',
                'notArrivedIncludeDraftOrders',
                'notArrivedMinFilter',
                'perPage',
                'poProductNovelty',
                'publishedFilter',
                'purchaseOrderUuids',
                'readyFilter',
                'reorderFilter',
                'reorderGtOne',
                'search',
                'searchMode',
                'selectedDepartments',
                'selectedFranchises',
                'selectedGrades',
                'selectedMainTypes',
                'selectedManufacturers',
                'selectedMissing',
                'selectedProductFlags',
                'selectedProductLines',
                'selectedScales',
                'selectedSeries',
                'selectedShipmentMethods',
                'selectedSublines',
                'selectedTypes',
                'selectedVendors',
                'selectedWorkshopShelves',
                'sellingPriceMaxFilter',
                'sellingPriceMinFilter',
                'sortBy',
                'sortDir',
                'storePreorderFilter',
            ].sort(),
        );
    });

    it('reset defaults clear fields that previously survived Reset filters', () => {
        const defaults = defaultProductsListView();

        expect(defaults.reorderGtOne).toBe(false);
        expect(defaults.storePreorderFilter).toBe('exclude');
        expect(defaults.notArrivedMinFilter).toBe('');
        expect(defaults.selectedWorkshopShelves).toEqual([]);
        expect(defaults.missingLandedCostFilter).toBe(false);
        expect(defaults.hasLandedCostFilter).toBe(false);
        expect(defaults.sortBy).toBe('received_date');
        expect(defaults.sortDir).toBe('desc');
        expect(defaults.perPage).toBe(200);
        expect(defaults.notArrivedIncludeDraftOrders).toBe(true);
    });

    it('parses a dirty page-state blob back to a complete snapshot', () => {
        const parsed = parseProductsListViewSnapshot({
            search: 'RG',
            reorderGtOne: true,
            storePreorderFilter: 'all',
            selectedDepartments: ['model_kits'],
            selectedWorkshopShelves: ['paints'],
            notArrivedMinFilter: '1',
            missingLandedCostFilter: true,
            sortBy: 'sku',
            sortDir: 'asc',
            purchaseOrderUuid: 'po-1',
        });

        expect(parsed).not.toBeNull();
        expect(parsed?.search).toBe('RG');
        expect(parsed?.reorderGtOne).toBe(true);
        expect(parsed?.storePreorderFilter).toBe('all');
        expect(parsed?.selectedDepartments).toEqual(['model_kits']);
        expect(parsed?.selectedWorkshopShelves).toEqual(['paints']);
        expect(parsed?.notArrivedMinFilter).toBe('1');
        expect(parsed?.missingLandedCostFilter).toBe(true);
        expect(parsed?.sortBy).toBe('sku');
        expect(parsed?.purchaseOrderUuids).toEqual(['po-1']);
        expect(parsed?.readyFilter).toBe('all');
    });

    it('strips list-filter query keys so Reset does not leave URL filters armed', () => {
        const next = productsQueryWithoutListFilters({
            filters_from: 'url',
            departments: 'model_kits',
            purchase_order_uuid: 'abc',
            reorder_gt_one: '1',
            store_preorder: 'all',
            v: 'keep-me',
        });

        expect(next).toEqual({ v: 'keep-me' });
    });

    it('treats the same filter set as equal regardless of multi-select order', () => {
        const left = defaultProductsListView();
        const right = defaultProductsListView();
        left.selectedDepartments = ['b', 'a'];
        right.selectedDepartments = ['a', 'b'];
        expect(productsListViewsEqual(left, right)).toBe(true);
        right.reorderGtOne = true;
        expect(productsListViewsEqual(left, right)).toBe(false);
    });
});
