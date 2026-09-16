import {
    PRODUCTS_FILTERS_FROM_URL,
    PRODUCTS_FILTER_EMPTY_DEPARTMENT,
    PRODUCTS_FILTER_EMPTY_MAIN_TYPE,
    PRODUCTS_FILTER_EMPTY_PRODUCT_LINE,
    PRODUCTS_FILTER_EMPTY_TYPE,
    PRODUCTS_FILTER_EMPTY_GRADE,
    PRODUCTS_FILTER_EMPTY_SUBLINE,
    PRODUCTS_FILTER_EMPTY_WORKSHOP_SHELF,
} from './productsUrlFilters';

export function mainTypeToProductsFilter(mainType: string): string {
    return mainType.trim() === '' ? PRODUCTS_FILTER_EMPTY_MAIN_TYPE : mainType;
}

export function typeToProductsFilter(type: string): string {
    return type.trim() === '' ? PRODUCTS_FILTER_EMPTY_TYPE : type;
}

export type InventoryReportUniqueSkuSlice =
    'catalog_skus' | 'skus_on_hand' | 'skus_missing_landed_cost' | 'not_arrived_skus';

export type InventoryReportProductsDrillDownTarget = {
    departments?: string[];
    productLines?: string[];
    workshopShelves?: string[];
    grades?: string[];
    sublines?: string[];
    types?: string[];
};

function emptyOrValue(value: string, emptyToken: string): string {
    return value.trim() === '' ? emptyToken : value;
}

export function buildInventoryByMainTypeProductsUrl(
    slice: InventoryReportUniqueSkuSlice,
    target: InventoryReportProductsDrillDownTarget | null,
): string {
    const params = new URLSearchParams();
    params.set('filters_from', PRODUCTS_FILTERS_FROM_URL);
    params.set('archived', 'active');

    if (target !== null) {
        for (const department of target.departments ?? []) {
            params.append(
                'departments[]',
                emptyOrValue(department, PRODUCTS_FILTER_EMPTY_DEPARTMENT),
            );
        }
        for (const productLine of target.productLines ?? []) {
            params.append(
                'product_lines[]',
                emptyOrValue(productLine, PRODUCTS_FILTER_EMPTY_PRODUCT_LINE),
            );
        }
        for (const shelf of target.workshopShelves ?? []) {
            params.append(
                'workshop_shelves[]',
                emptyOrValue(shelf, PRODUCTS_FILTER_EMPTY_WORKSHOP_SHELF),
            );
        }
        for (const grade of target.grades ?? []) {
            params.append('grades[]', emptyOrValue(grade, PRODUCTS_FILTER_EMPTY_GRADE));
        }
        for (const subline of target.sublines ?? []) {
            params.append('sublines[]', emptyOrValue(subline, PRODUCTS_FILTER_EMPTY_SUBLINE));
        }
        for (const type of target.types ?? []) {
            params.append('types[]', emptyOrValue(type, PRODUCTS_FILTER_EMPTY_TYPE));
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

function uniqueNonEmpty(values: string[]): string[] {
    return [...new Set(values.map((value) => value.trim()).filter(Boolean))];
}

export function drillDownTargetFromReportRow(row: {
    department?: string;
    main_type: string;
    type: string;
    product_line?: string;
    workshop_shelf?: string;
    grade?: string;
    subline?: string;
    drill_down_departments?: string[];
    drill_down_product_lines?: string[];
    drill_down_workshop_shelves?: string[];
    drill_down_grades?: string[];
    drill_down_sublines?: string[];
    drill_down_types?: string[];
}): InventoryReportProductsDrillDownTarget {
    const department = row.department || row.main_type;
    const departments = row.drill_down_departments ?? [department];
    const productLines = row.drill_down_product_lines ?? [row.product_line ?? ''];
    const workshopShelves = row.drill_down_workshop_shelves ?? [row.workshop_shelf ?? ''];
    const grades = row.drill_down_grades ?? [row.grade ?? ''];
    const sublines = row.drill_down_sublines ?? [row.subline ?? ''];
    const types = row.drill_down_types ?? [row.type];

    if (uniqueNonEmpty(productLines).length > 0) {
        return {
            departments,
            productLines,
            workshopShelves: [],
            grades: [],
            sublines: [],
            types: [],
        };
    }
    if (uniqueNonEmpty(workshopShelves).length > 0) {
        return {
            departments,
            productLines: [],
            workshopShelves,
            grades: [],
            sublines: [],
            types: [],
        };
    }
    if (uniqueNonEmpty(grades).length > 0) {
        return {
            departments,
            productLines: [],
            workshopShelves: [],
            grades,
            sublines: uniqueNonEmpty(sublines).length > 0 ? sublines : [],
            types: [],
        };
    }

    return { departments, productLines: [], workshopShelves: [], grades: [], sublines: [], types };
}
