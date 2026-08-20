import { describe, expect, it } from 'vitest';

import {
    buildInventoryByMainTypeCatalogSkusUrl,
    buildInventoryByMainTypeProductsUrl,
    drillDownTargetFromReportRow,
    mainTypeToProductsFilter,
    typeToProductsFilter,
} from '../inventoryByMainTypeProductsLinks';
import {
    PRODUCTS_FILTER_EMPTY_MAIN_TYPE,
    PRODUCTS_FILTER_EMPTY_TYPE,
} from '../productsUrlFilters';

describe('inventoryByMainTypeProductsLinks', () => {
    it('maps unset main type and type to the products empty sentinels', () => {
        expect(mainTypeToProductsFilter('')).toBe(PRODUCTS_FILTER_EMPTY_MAIN_TYPE);
        expect(mainTypeToProductsFilter('model kit')).toBe('model kit');
        expect(typeToProductsFilter('')).toBe(PRODUCTS_FILTER_EMPTY_TYPE);
        expect(typeToProductsFilter('HG')).toBe('HG');
    });

    it('builds catalog SKU drill-down URLs with main type and type filters', () => {
        const url = buildInventoryByMainTypeCatalogSkusUrl({
            mainTypes: ['model kit'],
            types: ['HG'],
        });
        expect(url).toContain('/products?');
        expect(url).toContain('filters_from=url');
        expect(url).toContain('archived=active');
        expect(url).toContain('main_types%5B%5D=model+kit');
        expect(url).toContain('types%5B%5D=HG');
    });

    it('builds drill-down URLs with multiple main types when a type spans buckets', () => {
        const url = buildInventoryByMainTypeProductsUrl('catalog_skus', {
            mainTypes: ['tools', 'supplies', 'paints'],
            types: ['PAINT'],
        });
        expect(url).toContain('main_types%5B%5D=tools');
        expect(url).toContain('main_types%5B%5D=supplies');
        expect(url).toContain('main_types%5B%5D=paints');
        expect(url).toContain('types%5B%5D=PAINT');
    });

    it('builds on-hand unique SKU drill-down URLs with available_min=1', () => {
        const url = buildInventoryByMainTypeProductsUrl('skus_on_hand', {
            mainTypes: ['tools'],
            types: ['pliers'],
        });
        expect(url).toContain('available_min=1');
        expect(url).toContain('archived=active');
        expect(url).toContain('main_types%5B%5D=tools');
        expect(url).toContain('types%5B%5D=pliers');
    });

    it('uses merged drill-down filters from report rows', () => {
        const target = drillDownTargetFromReportRow({
            main_type: 'tools',
            type: 'PAINT',
            drill_down_main_types: ['tools', 'supplies', 'paints'],
        });
        expect(target).toEqual({
            mainTypes: ['tools', 'supplies', 'paints'],
            types: ['PAINT'],
        });
    });

    it('builds totals drill-down URLs without main type or type filters', () => {
        const url = buildInventoryByMainTypeCatalogSkusUrl(null);
        expect(url).toContain('filters_from=url');
        expect(url).toContain('archived=active');
        expect(url).not.toContain('main_types');
        expect(url).not.toContain('types');
    });
});
