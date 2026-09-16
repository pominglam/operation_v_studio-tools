import type { LocationQuery } from 'vue-router';

export const PRODUCTS_LIST_VIEW_SORT_KEYS = [
    'sku',
    'barcode',
    'description',
    'main_type',
    'type',
    'department',
    'manufacturer',
    'franchise',
    'product_line',
    'subline',
    'grade',
    'series',
    'scale',
    'vendor',
    'latest_landed_unit_cost',
    'received_date',
    'selling_price',
    'total_ordered',
    'total_sold',
    'available',
    'demand',
    'maintain',
    'not_arrived',
    'reorder',
    'po_total_cost',
    'is_urgent',
] as const;

export type ProductsListViewSortKey = (typeof PRODUCTS_LIST_VIEW_SORT_KEYS)[number];
export type ProductsListSearchMode = 'single' | 'bulk';
export type ProductsListReadyFilter = 'all' | 'ready' | 'not_ready';
export type ProductsListPublishedFilter = 'all' | 'published' | 'not_published';
export type ProductsListArchivedFilter = 'active' | 'all' | 'archived';
export type ProductsListStorePreorderFilter = 'exclude' | 'open' | 'all';
export type ProductsListPoNovelty = 'all' | 'new' | 'existing';

export type ProductsListViewSnapshot = {
    search: string;
    searchMode: ProductsListSearchMode;
    bulkSearchText: string;
    perPage: number;
    sortBy: ProductsListViewSortKey;
    sortDir: 'asc' | 'desc';
    selectedMainTypes: string[];
    selectedTypes: string[];
    selectedDepartments: string[];
    selectedManufacturers: string[];
    selectedFranchises: string[];
    selectedProductLines: string[];
    selectedWorkshopShelves: string[];
    selectedSublines: string[];
    selectedGrades: string[];
    selectedScales: string[];
    selectedSeries: string[];
    selectedVendors: string[];
    selectedMissing: string[];
    selectedProductFlags: string[];
    selectedShipmentMethods: string[];
    readyFilter: ProductsListReadyFilter;
    publishedFilter: ProductsListPublishedFilter;
    archivedFilter: ProductsListArchivedFilter;
    storePreorderFilter: ProductsListStorePreorderFilter;
    availableMinFilter: string;
    availableMaxFilter: string;
    notArrivedFilter: string;
    notArrivedMinFilter: string;
    notArrivedIncludeDraftOrders: boolean;
    missingLandedCostFilter: boolean;
    hasLandedCostFilter: boolean;
    reorderFilter: string;
    reorderGtOne: boolean;
    sellingPriceMinFilter: string;
    sellingPriceMaxFilter: string;
    purchaseOrderUuids: string[];
    poProductNovelty: ProductsListPoNovelty;
};

export const PRODUCTS_LIST_VIEW_KEYS = Object.keys(defaultProductsListView()).sort();

export function defaultProductsListView(): ProductsListViewSnapshot {
    return {
        search: '',
        searchMode: 'single',
        bulkSearchText: '',
        perPage: 200,
        sortBy: 'received_date',
        sortDir: 'desc',
        selectedMainTypes: [],
        selectedTypes: [],
        selectedDepartments: [],
        selectedManufacturers: [],
        selectedFranchises: [],
        selectedProductLines: [],
        selectedWorkshopShelves: [],
        selectedSublines: [],
        selectedGrades: [],
        selectedScales: [],
        selectedSeries: [],
        selectedVendors: [],
        selectedMissing: [],
        selectedProductFlags: [],
        selectedShipmentMethods: [],
        readyFilter: 'all',
        publishedFilter: 'all',
        archivedFilter: 'active',
        storePreorderFilter: 'exclude',
        availableMinFilter: '',
        availableMaxFilter: '',
        notArrivedFilter: '',
        notArrivedMinFilter: '',
        notArrivedIncludeDraftOrders: true,
        missingLandedCostFilter: false,
        hasLandedCostFilter: false,
        reorderFilter: '',
        reorderGtOne: false,
        sellingPriceMinFilter: '',
        sellingPriceMaxFilter: '',
        purchaseOrderUuids: [],
        poProductNovelty: 'all',
    };
}

export function isProductsListViewSortKey(value: unknown): value is ProductsListViewSortKey {
    return (
        typeof value === 'string' &&
        PRODUCTS_LIST_VIEW_SORT_KEYS.includes(value as ProductsListViewSortKey)
    );
}

export function cloneProductsListView(
    snapshot: ProductsListViewSnapshot,
): ProductsListViewSnapshot {
    return {
        ...snapshot,
        selectedMainTypes: [...snapshot.selectedMainTypes],
        selectedTypes: [...snapshot.selectedTypes],
        selectedDepartments: [...snapshot.selectedDepartments],
        selectedManufacturers: [...snapshot.selectedManufacturers],
        selectedFranchises: [...snapshot.selectedFranchises],
        selectedProductLines: [...snapshot.selectedProductLines],
        selectedWorkshopShelves: [...snapshot.selectedWorkshopShelves],
        selectedSublines: [...snapshot.selectedSublines],
        selectedGrades: [...snapshot.selectedGrades],
        selectedScales: [...snapshot.selectedScales],
        selectedSeries: [...snapshot.selectedSeries],
        selectedVendors: [...snapshot.selectedVendors],
        selectedMissing: [...snapshot.selectedMissing],
        selectedProductFlags: [...snapshot.selectedProductFlags],
        selectedShipmentMethods: [...snapshot.selectedShipmentMethods],
        purchaseOrderUuids: [...snapshot.purchaseOrderUuids],
    };
}

function sortedCopy(values: string[]): string[] {
    return [...values]
        .map((value) => value.trim())
        .filter(Boolean)
        .sort();
}

export function productsListViewsEqual(
    left: ProductsListViewSnapshot,
    right: ProductsListViewSnapshot,
): boolean {
    return (
        left.search === right.search &&
        left.searchMode === right.searchMode &&
        left.bulkSearchText === right.bulkSearchText &&
        left.perPage === right.perPage &&
        left.sortBy === right.sortBy &&
        left.sortDir === right.sortDir &&
        left.readyFilter === right.readyFilter &&
        left.publishedFilter === right.publishedFilter &&
        left.archivedFilter === right.archivedFilter &&
        left.storePreorderFilter === right.storePreorderFilter &&
        left.availableMinFilter === right.availableMinFilter &&
        left.availableMaxFilter === right.availableMaxFilter &&
        left.notArrivedFilter === right.notArrivedFilter &&
        left.notArrivedMinFilter === right.notArrivedMinFilter &&
        left.notArrivedIncludeDraftOrders === right.notArrivedIncludeDraftOrders &&
        left.missingLandedCostFilter === right.missingLandedCostFilter &&
        left.hasLandedCostFilter === right.hasLandedCostFilter &&
        left.reorderFilter === right.reorderFilter &&
        left.reorderGtOne === right.reorderGtOne &&
        left.sellingPriceMinFilter === right.sellingPriceMinFilter &&
        left.sellingPriceMaxFilter === right.sellingPriceMaxFilter &&
        left.poProductNovelty === right.poProductNovelty &&
        JSON.stringify(sortedCopy(left.selectedMainTypes)) ===
            JSON.stringify(sortedCopy(right.selectedMainTypes)) &&
        JSON.stringify(sortedCopy(left.selectedTypes)) ===
            JSON.stringify(sortedCopy(right.selectedTypes)) &&
        JSON.stringify(sortedCopy(left.selectedDepartments)) ===
            JSON.stringify(sortedCopy(right.selectedDepartments)) &&
        JSON.stringify(sortedCopy(left.selectedManufacturers)) ===
            JSON.stringify(sortedCopy(right.selectedManufacturers)) &&
        JSON.stringify(sortedCopy(left.selectedFranchises)) ===
            JSON.stringify(sortedCopy(right.selectedFranchises)) &&
        JSON.stringify(sortedCopy(left.selectedProductLines)) ===
            JSON.stringify(sortedCopy(right.selectedProductLines)) &&
        JSON.stringify(sortedCopy(left.selectedWorkshopShelves)) ===
            JSON.stringify(sortedCopy(right.selectedWorkshopShelves)) &&
        JSON.stringify(sortedCopy(left.selectedSublines)) ===
            JSON.stringify(sortedCopy(right.selectedSublines)) &&
        JSON.stringify(sortedCopy(left.selectedGrades)) ===
            JSON.stringify(sortedCopy(right.selectedGrades)) &&
        JSON.stringify(sortedCopy(left.selectedScales)) ===
            JSON.stringify(sortedCopy(right.selectedScales)) &&
        JSON.stringify(sortedCopy(left.selectedSeries)) ===
            JSON.stringify(sortedCopy(right.selectedSeries)) &&
        JSON.stringify(sortedCopy(left.selectedVendors)) ===
            JSON.stringify(sortedCopy(right.selectedVendors)) &&
        JSON.stringify(sortedCopy(left.selectedMissing)) ===
            JSON.stringify(sortedCopy(right.selectedMissing)) &&
        JSON.stringify(sortedCopy(left.selectedProductFlags)) ===
            JSON.stringify(sortedCopy(right.selectedProductFlags)) &&
        JSON.stringify(sortedCopy(left.selectedShipmentMethods)) ===
            JSON.stringify(sortedCopy(right.selectedShipmentMethods)) &&
        JSON.stringify(sortedCopy(left.purchaseOrderUuids)) ===
            JSON.stringify(sortedCopy(right.purchaseOrderUuids))
    );
}

export function parseProductsListViewSnapshot(raw: unknown): ProductsListViewSnapshot | null {
    if (raw === null || typeof raw !== 'object') return null;
    const saved = raw as Partial<ProductsListViewSnapshot> & {
        availableFilter?: string;
        purchaseOrderUuid?: string;
    };
    const defaults = defaultProductsListView();

    return {
        search: typeof saved.search === 'string' ? saved.search : defaults.search,
        searchMode: saved.searchMode === 'bulk' ? 'bulk' : 'single',
        bulkSearchText:
            typeof saved.bulkSearchText === 'string'
                ? saved.bulkSearchText
                : defaults.bulkSearchText,
        perPage:
            typeof saved.perPage === 'number' && saved.perPage > 0
                ? saved.perPage
                : defaults.perPage,
        sortBy: isProductsListViewSortKey(saved.sortBy) ? saved.sortBy : defaults.sortBy,
        sortDir:
            saved.sortDir === 'asc' || saved.sortDir === 'desc' ? saved.sortDir : defaults.sortDir,
        selectedMainTypes: stringArray(saved.selectedMainTypes, defaults.selectedMainTypes),
        selectedTypes: stringArray(saved.selectedTypes, defaults.selectedTypes),
        selectedDepartments: stringArray(saved.selectedDepartments, defaults.selectedDepartments),
        selectedManufacturers: stringArray(
            saved.selectedManufacturers,
            defaults.selectedManufacturers,
        ),
        selectedFranchises: stringArray(saved.selectedFranchises, defaults.selectedFranchises),
        selectedProductLines: stringArray(
            saved.selectedProductLines,
            defaults.selectedProductLines,
        ),
        selectedWorkshopShelves: stringArray(
            saved.selectedWorkshopShelves,
            defaults.selectedWorkshopShelves,
        ),
        selectedSublines: stringArray(saved.selectedSublines, defaults.selectedSublines),
        selectedGrades: stringArray(saved.selectedGrades, defaults.selectedGrades),
        selectedScales: stringArray(saved.selectedScales, defaults.selectedScales),
        selectedSeries: stringArray(saved.selectedSeries, defaults.selectedSeries),
        selectedVendors: stringArray(saved.selectedVendors, defaults.selectedVendors),
        selectedMissing: stringArray(saved.selectedMissing, defaults.selectedMissing),
        selectedProductFlags: stringArray(
            saved.selectedProductFlags,
            defaults.selectedProductFlags,
        ),
        selectedShipmentMethods: stringArray(
            saved.selectedShipmentMethods,
            defaults.selectedShipmentMethods,
        ),
        readyFilter:
            saved.readyFilter === 'ready' || saved.readyFilter === 'not_ready'
                ? saved.readyFilter
                : defaults.readyFilter,
        publishedFilter:
            saved.publishedFilter === 'published' || saved.publishedFilter === 'not_published'
                ? saved.publishedFilter
                : defaults.publishedFilter,
        archivedFilter:
            saved.archivedFilter === 'all' || saved.archivedFilter === 'archived'
                ? saved.archivedFilter
                : defaults.archivedFilter,
        storePreorderFilter:
            saved.storePreorderFilter === 'open' || saved.storePreorderFilter === 'all'
                ? saved.storePreorderFilter
                : defaults.storePreorderFilter,
        availableMinFilter:
            typeof saved.availableMinFilter === 'string'
                ? saved.availableMinFilter
                : typeof saved.availableFilter === 'string'
                  ? saved.availableFilter
                  : defaults.availableMinFilter,
        availableMaxFilter:
            typeof saved.availableMaxFilter === 'string'
                ? saved.availableMaxFilter
                : defaults.availableMaxFilter,
        notArrivedFilter:
            typeof saved.notArrivedFilter === 'string'
                ? saved.notArrivedFilter
                : defaults.notArrivedFilter,
        notArrivedMinFilter:
            typeof saved.notArrivedMinFilter === 'string'
                ? saved.notArrivedMinFilter
                : defaults.notArrivedMinFilter,
        notArrivedIncludeDraftOrders:
            typeof saved.notArrivedIncludeDraftOrders === 'boolean'
                ? saved.notArrivedIncludeDraftOrders
                : defaults.notArrivedIncludeDraftOrders,
        missingLandedCostFilter:
            typeof saved.missingLandedCostFilter === 'boolean'
                ? saved.missingLandedCostFilter
                : defaults.missingLandedCostFilter,
        hasLandedCostFilter:
            typeof saved.hasLandedCostFilter === 'boolean'
                ? saved.hasLandedCostFilter
                : defaults.hasLandedCostFilter,
        reorderFilter:
            typeof saved.reorderFilter === 'string' ? saved.reorderFilter : defaults.reorderFilter,
        reorderGtOne:
            typeof saved.reorderGtOne === 'boolean' ? saved.reorderGtOne : defaults.reorderGtOne,
        sellingPriceMinFilter:
            typeof saved.sellingPriceMinFilter === 'string'
                ? saved.sellingPriceMinFilter
                : defaults.sellingPriceMinFilter,
        sellingPriceMaxFilter:
            typeof saved.sellingPriceMaxFilter === 'string'
                ? saved.sellingPriceMaxFilter
                : defaults.sellingPriceMaxFilter,
        purchaseOrderUuids: Array.isArray(saved.purchaseOrderUuids)
            ? stringArray(saved.purchaseOrderUuids, defaults.purchaseOrderUuids)
            : typeof saved.purchaseOrderUuid === 'string' && saved.purchaseOrderUuid.trim() !== ''
              ? [saved.purchaseOrderUuid.trim()]
              : defaults.purchaseOrderUuids,
        poProductNovelty:
            saved.poProductNovelty === 'new' || saved.poProductNovelty === 'existing'
                ? saved.poProductNovelty
                : defaults.poProductNovelty,
    };
}

export const PRODUCTS_LIST_FILTER_QUERY_KEYS = [
    'filters_from',
    'main_types',
    'main_types[]',
    'types',
    'types[]',
    'departments',
    'departments[]',
    'manufacturers',
    'manufacturers[]',
    'franchises',
    'franchises[]',
    'product_lines',
    'product_lines[]',
    'workshop_shelves',
    'workshop_shelves[]',
    'sublines',
    'sublines[]',
    'grades',
    'grades[]',
    'scales',
    'scales[]',
    'series_values',
    'series_values[]',
    'vendors',
    'vendors[]',
    'missing',
    'missing[]',
    'product_flags',
    'product_flags[]',
    'shipment_methods',
    'shipment_methods[]',
    'archived',
    'available_min',
    'available_max',
    'not_arrived',
    'not_arrived_min',
    'not_arrived_include_draft_orders',
    'missing_landed_cost',
    'has_landed_cost',
    'purchase_order_uuid',
    'purchase_order_uuids',
    'purchase_order_uuids[]',
    'po_product_novelty',
    'search',
    'search_terms',
    'search_terms[]',
    'ready',
    'published',
    'store_preorder',
    'reorder',
    'reorder_gt_one',
    'selling_price_min',
    'selling_price_max',
    'sort_by',
    'sort_dir',
] as const;

export function productsQueryWithoutListFilters(query: LocationQuery): LocationQuery {
    const next: LocationQuery = {};
    for (const [key, value] of Object.entries(query)) {
        if ((PRODUCTS_LIST_FILTER_QUERY_KEYS as readonly string[]).includes(key)) {
            continue;
        }
        next[key] = value;
    }
    return next;
}

function stringArray(value: unknown, fallback: string[]): string[] {
    if (!Array.isArray(value)) return [...fallback];
    return value.map((item) => String(item));
}
