import {
    PRODUCTS_FILTERS_FROM_URL,
    PRODUCTS_FILTER_EMPTY_MAIN_TYPE,
    PRODUCTS_FILTER_EMPTY_TYPE,
} from './productsUrlFilters';

export type InventoryReportUniqueSkuSlice =
    | 'catalog_skus'
    | 'skus_on_hand'
    | 'skus_missing_landed_cost'
    | 'not_arrived_skus';

export type InventoryReportProductsDrillDownTarget = {
    mainTypes: string[];
    types: string[];
};

export function mainTypeToProductsFilter(mainType: string): string {
    return mainType.trim() === '' ? PRODUCTS_FILTER_EMPTY_MAIN_TYPE : mainType;
}

export function typeToProductsFilter(type: string): string {
    return type.trim() === '' ? PRODUCTS_FILTER_EMPTY_TYPE : type;
}

export function buildInventoryByMainTypeProductsUrl(
    slice: InventoryReportUniqueSkuSlice,
    target: InventoryReportProductsDrillDownTarget | null,
): string {
    const params = new URLSearchParams();
    params.set('filters_from', PRODUCTS_FILTERS_FROM_URL);
    params.set('archived', 'active');

    if (target !== null) {
        for (const mainType of target.mainTypes) {
            params.append('main_types[]', mainTypeToProductsFilter(mainType));
        }
        for (const type of target.types) {
            params.append('types[]', typeToProductsFilter(type));
        }
    }

    switch (slice) {
        case 'catalog_skus':
            break;
        case 'skus_on_hand':
            params.set('available_min', '1');
            break;
        case 'skus_missing_landed_cost':
            params.set('available_min', '1');
            params.set('missing_landed_cost', '1');
            break;
        case 'not_arrived_skus':
            params.set('not_arrived_min', '1');
            params.set('not_arrived_include_draft_orders', '1');
            break;
    }

    return `/products?${params.toString()}`;
}

export function buildInventoryByMainTypeCatalogSkusUrl(
    target: InventoryReportProductsDrillDownTarget | null,
): string {
    return buildInventoryByMainTypeProductsUrl('catalog_skus', target);
}

export function drillDownTargetFromReportRow(row: {
    main_type: string;
    type: string;
    drill_down_main_types?: string[];
    drill_down_types?: string[];
}): InventoryReportProductsDrillDownTarget {
    return {
        mainTypes: row.drill_down_main_types ?? [row.main_type],
        types: row.drill_down_types ?? [row.type],
    };
}
