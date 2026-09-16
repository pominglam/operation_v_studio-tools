export const PRODUCTS_TABLE_COLUMN_KEYS = [
    'department',
    'manufacturer',
    'franchise',
    'product_line',
    'grade',
    'scale',
    'series',
    'vendor',
    'received_date',
    'selling_price',
    'cost',
    'total_ordered',
    'total_sold',
    'available',
    'hold',
    'demand',
    'maintain',
    'not_arrived',
    'reorder',
    'info',
    'ready',
    'latest_arrival',
    'published',
    'actions',
] as const;

export type ProductsTableColumnKey = (typeof PRODUCTS_TABLE_COLUMN_KEYS)[number];

export type ProductsTableColumnGroup = 'taxonomy' | 'pricing' | 'inventory' | 'status';

export type ProductsTableColumnOption = {
    key: ProductsTableColumnKey;
    label: string;
    group: ProductsTableColumnGroup;
};

export const PRODUCTS_TABLE_TAXONOMY_COLUMNS: ProductsTableColumnKey[] = [
    'department',
    'manufacturer',
    'franchise',
    'product_line',
    'grade',
    'scale',
    'series',
];

export const PRODUCTS_TABLE_COLUMN_OPTIONS: ProductsTableColumnOption[] = [
    { key: 'department', label: 'Department', group: 'taxonomy' },
    { key: 'manufacturer', label: 'Manufacturer', group: 'taxonomy' },
    { key: 'franchise', label: 'Franchise', group: 'taxonomy' },
    { key: 'product_line', label: 'Product line', group: 'taxonomy' },
    { key: 'grade', label: 'Grade', group: 'taxonomy' },
    { key: 'scale', label: 'Scale', group: 'taxonomy' },
    { key: 'series', label: 'Series', group: 'taxonomy' },
    { key: 'vendor', label: 'Vendor', group: 'pricing' },
    { key: 'received_date', label: 'Received', group: 'pricing' },
    { key: 'selling_price', label: 'Selling price', group: 'pricing' },
    { key: 'cost', label: 'Cost', group: 'pricing' },
    { key: 'total_ordered', label: 'Total ordered', group: 'inventory' },
    { key: 'total_sold', label: 'Total sold', group: 'inventory' },
    { key: 'available', label: 'Available', group: 'inventory' },
    { key: 'hold', label: 'Hold', group: 'inventory' },
    { key: 'demand', label: '4 wk sold', group: 'inventory' },
    { key: 'maintain', label: 'Maintain', group: 'inventory' },
    { key: 'not_arrived', label: 'Not arrived', group: 'inventory' },
    { key: 'reorder', label: 'Reorder', group: 'inventory' },
    { key: 'info', label: 'Info', group: 'status' },
    { key: 'ready', label: 'Ready', group: 'status' },
    { key: 'latest_arrival', label: 'Latest arrival', group: 'status' },
    { key: 'published', label: 'Published on Shopify', group: 'status' },
    { key: 'actions', label: 'Actions', group: 'status' },
];

export const PRODUCTS_TABLE_COLUMN_GROUPS: Array<{
    id: ProductsTableColumnGroup;
    label: string;
}> = [
    { id: 'taxonomy', label: 'Taxonomy' },
    { id: 'pricing', label: 'Pricing & vendor' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'status', label: 'Status' },
];

const COLUMN_KEY_SET = new Set<string>(PRODUCTS_TABLE_COLUMN_KEYS);

export function defaultProductsTableVisibleColumns(): ProductsTableColumnKey[] {
    return PRODUCTS_TABLE_COLUMN_KEYS.filter((key) => key !== 'cost');
}

export function parseProductsTableVisibleColumns(raw: unknown): ProductsTableColumnKey[] {
    if (!Array.isArray(raw)) {
        return defaultProductsTableVisibleColumns();
    }

    const seen = new Set<ProductsTableColumnKey>();
    for (const item of raw) {
        if (typeof item !== 'string' || !COLUMN_KEY_SET.has(item)) {
            continue;
        }
        seen.add(item as ProductsTableColumnKey);
    }

    return PRODUCTS_TABLE_COLUMN_KEYS.filter((key) => seen.has(key));
}

export function productsTableColumnsEqual(
    left: readonly string[],
    right: readonly string[],
): boolean {
    if (left.length !== right.length) {
        return false;
    }

    const rightSet = new Set(right);
    return left.every((key) => rightSet.has(key));
}

export function isProductsTableColumnVisible(
    visible: readonly string[],
    key: ProductsTableColumnKey,
): boolean {
    return visible.includes(key);
}

export function toggleProductsTableColumn(
    visible: readonly ProductsTableColumnKey[],
    key: ProductsTableColumnKey,
): ProductsTableColumnKey[] {
    if (visible.includes(key)) {
        return visible.filter((item) => item !== key);
    }

    return PRODUCTS_TABLE_COLUMN_KEYS.filter((item) => item === key || visible.includes(item));
}

export function setProductsTableTaxonomyVisible(
    visible: readonly ProductsTableColumnKey[],
    show: boolean,
): ProductsTableColumnKey[] {
    const withoutTaxonomy = visible.filter((key) => !PRODUCTS_TABLE_TAXONOMY_COLUMNS.includes(key));

    if (!show) {
        return withoutTaxonomy;
    }

    return PRODUCTS_TABLE_COLUMN_KEYS.filter(
        (key) => withoutTaxonomy.includes(key) || PRODUCTS_TABLE_TAXONOMY_COLUMNS.includes(key),
    );
}

export function productsTableTaxonomyVisible(visible: readonly string[]): boolean {
    return PRODUCTS_TABLE_TAXONOMY_COLUMNS.every((key) => visible.includes(key));
}
