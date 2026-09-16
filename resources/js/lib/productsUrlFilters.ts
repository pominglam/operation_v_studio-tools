import type { LocationQuery } from 'vue-router';

export const PRODUCTS_FILTERS_FROM_URL = 'url';
export const PRODUCTS_FILTER_EMPTY_MAIN_TYPE = '__empty__';
export const PRODUCTS_FILTER_EMPTY_TYPE = '__empty__';
export const PRODUCTS_FILTER_EMPTY_DEPARTMENT = '__empty__';
export const PRODUCTS_FILTER_EMPTY_PRODUCT_LINE = '__empty__';
export const PRODUCTS_FILTER_EMPTY_WORKSHOP_SHELF = '__empty__';
export const PRODUCTS_FILTER_EMPTY_GRADE = '__empty__';
export const PRODUCTS_FILTER_EMPTY_SUBLINE = '__empty__';

export type ProductsUrlFilterState = {
    mainTypes: string[];
    types: string[];
    departments?: string[];
    productLines?: string[];
    workshopShelves?: string[];
    grades?: string[];
    sublines?: string[];
    archived: 'active' | 'all' | 'archived';
    availableMin: string;
    availableMax: string;
    notArrived: string;
    notArrivedMin: string;
    notArrivedIncludeDraftOrders: boolean;
    missingLandedCost: boolean;
    hasLandedCost: boolean;
};

export function isProductsFiltersFromUrl(query: LocationQuery): boolean {
    return query.filters_from === PRODUCTS_FILTERS_FROM_URL;
}

function parseRouteQueryStringArray(query: LocationQuery, key: string): string[] {
    const raw = query[key];
    if (Array.isArray(raw)) {
        return raw
            .map(String)
            .map((value) => value.trim())
            .filter(Boolean);
    }
    if (typeof raw === 'string' && raw.trim() !== '') {
        return [raw.trim()];
    }

    return [];
}

export function parseRouteQueryMainTypes(query: LocationQuery): string[] {
    return [
        ...parseRouteQueryStringArray(query, 'main_types'),
        ...parseRouteQueryStringArray(query, 'main_types[]'),
    ];
}

export function parseRouteQueryTypes(query: LocationQuery): string[] {
    return [
        ...parseRouteQueryStringArray(query, 'types'),
        ...parseRouteQueryStringArray(query, 'types[]'),
    ];
}

export function parseRouteQueryDepartments(query: LocationQuery): string[] {
    return [
        ...parseRouteQueryStringArray(query, 'departments'),
        ...parseRouteQueryStringArray(query, 'departments[]'),
    ];
}

export function parseRouteQueryProductLines(query: LocationQuery): string[] {
    return [
        ...parseRouteQueryStringArray(query, 'product_lines'),
        ...parseRouteQueryStringArray(query, 'product_lines[]'),
    ];
}

export function parseRouteQueryWorkshopShelves(query: LocationQuery): string[] {
    return [
        ...parseRouteQueryStringArray(query, 'workshop_shelves'),
        ...parseRouteQueryStringArray(query, 'workshop_shelves[]'),
    ];
}

export function parseRouteQueryGrades(query: LocationQuery): string[] {
    return [
        ...parseRouteQueryStringArray(query, 'grades'),
        ...parseRouteQueryStringArray(query, 'grades[]'),
    ];
}

export function parseRouteQuerySublines(query: LocationQuery): string[] {
    return [
        ...parseRouteQueryStringArray(query, 'sublines'),
        ...parseRouteQueryStringArray(query, 'sublines[]'),
    ];
}

function parseRouteQueryFlag(query: LocationQuery, key: string): boolean {
    const raw = query[key];
    if (raw === undefined) {
        return false;
    }
    if (Array.isArray(raw)) {
        return raw.some((value) => parseTruthyQueryValue(String(value)));
    }

    return parseTruthyQueryValue(String(raw));
}

function parseTruthyQueryValue(raw: string): boolean {
    const normalized = raw.trim().toLowerCase();
    return normalized === '1' || normalized === 'true' || normalized === 'yes';
}

function parseRouteQueryString(query: LocationQuery, key: string): string {
    const raw = query[key];
    if (typeof raw === 'string') {
        return raw.trim();
    }
    if (Array.isArray(raw) && raw.length > 0) {
        return String(raw[0]).trim();
    }

    return '';
}

function parseArchivedFilter(query: LocationQuery): 'active' | 'all' | 'archived' {
    const raw = parseRouteQueryString(query, 'archived');
    if (raw === 'all' || raw === 'archived') {
        return raw;
    }

    return 'active';
}

export function parseProductsUrlFilterState(query: LocationQuery): ProductsUrlFilterState | null {
    if (!isProductsFiltersFromUrl(query)) {
        return null;
    }

    const departments = parseRouteQueryDepartments(query);
    const productLines = parseRouteQueryProductLines(query);
    const workshopShelves = parseRouteQueryWorkshopShelves(query);
    const grades = parseRouteQueryGrades(query);
    const sublines = parseRouteQuerySublines(query);

    return {
        mainTypes: parseRouteQueryMainTypes(query),
        types: parseRouteQueryTypes(query),
        ...(departments.length > 0 ? { departments } : {}),
        ...(productLines.length > 0 ? { productLines } : {}),
        ...(workshopShelves.length > 0 ? { workshopShelves } : {}),
        ...(grades.length > 0 ? { grades } : {}),
        ...(sublines.length > 0 ? { sublines } : {}),
        archived: parseArchivedFilter(query),
        availableMin: parseRouteQueryString(query, 'available_min'),
        availableMax: parseRouteQueryString(query, 'available_max'),
        notArrived: parseRouteQueryString(query, 'not_arrived'),
        notArrivedMin: parseRouteQueryString(query, 'not_arrived_min'),
        notArrivedIncludeDraftOrders: parseRouteQueryFlag(
            query,
            'not_arrived_include_draft_orders',
        ),
        missingLandedCost: parseRouteQueryFlag(query, 'missing_landed_cost'),
        hasLandedCost: parseRouteQueryFlag(query, 'has_landed_cost'),
    };
}

export function defaultProductsUrlFilterState(): ProductsUrlFilterState {
    return {
        mainTypes: [],
        types: [],
        archived: 'active',
        availableMin: '',
        availableMax: '',
        notArrived: '',
        notArrivedMin: '',
        notArrivedIncludeDraftOrders: true,
        missingLandedCost: false,
        hasLandedCost: false,
    };
}
