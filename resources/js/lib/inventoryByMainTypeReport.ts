export type InventoryByMainTypeReportRow = {
    type: string;
    type_label: string;
    main_type: string;
    catalog_skus: number;
    skus_on_hand: number;
    quantity_on_hand: number;
    not_arrived_skus: number;
    not_arrived: number;
    estimated_landed_value: string;
    estimated_not_landed_value: string;
    skus_missing_landed_cost: number;
    units_received: number;
    units_sold: number;
    /** Set when multiple main_type rows were merged for the same type label. */
    drill_down_main_types?: string[];
    /** Set when multiple type variants were merged (e.g. case differences). */
    drill_down_types?: string[];
};

export type InventoryByMainTypeReportTotals = {
    catalog_skus: number;
    skus_on_hand: number;
    quantity_on_hand: number;
    not_arrived_skus: number;
    not_arrived: number;
    estimated_landed_value: string;
    estimated_not_landed_value: string;
    skus_missing_landed_cost: number;
    units_received: number;
    units_sold: number;
};

export type InventoryByMainTypeReport = {
    data_source: string;
    scope: string;
    currency: string;
    not_arrived_includes_draft_pos: boolean;
    rows: InventoryByMainTypeReportRow[];
    totals: InventoryByMainTypeReportTotals;
};

export type InventoryByMainTypeNavbarGroup = {
    key: string;
    label: string;
    rows: InventoryByMainTypeReportRow[];
    totals: InventoryByMainTypeReportTotals;
};

export type InventoryByMainTypeSortKey =
    | 'type_label'
    | 'catalog_skus'
    | 'skus_on_hand'
    | 'quantity_on_hand'
    | 'estimated_landed_value'
    | 'estimated_not_landed_value'
    | 'skus_missing_landed_cost'
    | 'not_arrived_skus'
    | 'not_arrived'
    | 'units_received'
    | 'units_sold';

const STOREFRONT_NAVBAR_GROUPS = [
    { key: 'model-kits', label: 'Model kits', mainTypes: ['model kit'] },
    {
        key: 'tools-supplies',
        label: 'Tools & Supplies',
        mainTypes: ['tools', 'supplies', 'paints'],
    },
    { key: 'water-decals', label: 'Water decals', mainTypes: ['water decals'] },
    { key: 'miscellaneous', label: 'Miscellaneous', mainTypes: ['misc'] },
] as const;

export function parseInventoryByMainTypeReportResponse(
    payload: unknown,
): InventoryByMainTypeReport {
    if (payload === null || typeof payload !== 'object') {
        throw new Error('Inventory by type report response was empty.');
    }

    const body = payload as { data?: InventoryByMainTypeReport };
    const report = body.data;
    if (!report || !Array.isArray(report.rows) || !report.totals) {
        throw new Error('Inventory by type report response was missing rows or totals.');
    }

    return report;
}

export function formatInventoryLandedValue(amount: string, currency = 'CAD'): string {
    const parsed = Number.parseFloat(amount);
    if (!Number.isFinite(parsed)) {
        return amount;
    }

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(parsed);
}

export function compareInventoryByMainTypeRows(
    left: InventoryByMainTypeReportRow,
    right: InventoryByMainTypeReportRow,
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): number {
    let result = 0;

    if (sortBy === 'type_label') {
        result = left.type_label.localeCompare(right.type_label, undefined, {
            sensitivity: 'base',
        });
    } else {
        const leftValue = left[sortBy];
        const rightValue = right[sortBy];
        result =
            typeof leftValue === 'number' && typeof rightValue === 'number'
                ? leftValue - rightValue
                : String(leftValue).localeCompare(String(rightValue), undefined, { numeric: true });
    }

    return sortDir === 'asc' ? result : -result;
}

export function groupInventoryRowsByStorefrontNavbar(
    rows: InventoryByMainTypeReportRow[],
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryByMainTypeNavbarGroup[] {
    const knownGroups = STOREFRONT_NAVBAR_GROUPS.map((definition) => ({
        key: definition.key,
        label: definition.label,
        rows: rows.filter((row) =>
            (definition.mainTypes as readonly string[]).includes(normalizeMainType(row.main_type)),
        ),
    }));
    const knownMainTypes = new Set(
        STOREFRONT_NAVBAR_GROUPS.flatMap((definition) => definition.mainTypes as readonly string[]),
    );
    const otherRows = rows.filter((row) => !knownMainTypes.has(normalizeMainType(row.main_type)));
    const groups = [...knownGroups, { key: 'other', label: 'Other', rows: otherRows }].filter(
        (group) => group.rows.length > 0,
    );

    const result = groups.map((group) => {
        const mergedRows = mergeInventoryRowsByType(group.rows);

        return {
            ...group,
            rows: sortGroupRows(mergedRows, sortBy, sortDir),
            totals: sumInventoryRows(mergedRows),
        };
    });

    if (sortBy === 'type_label') {
        return sortDir === 'asc' ? result : result.reverse();
    }

    return result.sort((left, right) =>
        compareInventoryByMainTypeRows(
            groupAsComparableRow(left),
            groupAsComparableRow(right),
            sortBy,
            sortDir,
        ),
    );
}

function normalizeMainType(mainType: string): string {
    return mainType.trim().toLocaleLowerCase();
}

function normalizeTypeKey(type: string): string {
    return type.trim().toLocaleLowerCase();
}

function uniqueNonEmpty(values: string[]): string[] {
    return [...new Set(values.map((value) => value.trim()).filter(Boolean))];
}

/** Same products.type label can appear under multiple main_type buckets within one navbar group. */
export function mergeInventoryRowsByType(
    rows: InventoryByMainTypeReportRow[],
): InventoryByMainTypeReportRow[] {
    const buckets = new Map<string, InventoryByMainTypeReportRow[]>();

    for (const row of rows) {
        const key = normalizeTypeKey(row.type);
        const bucket = buckets.get(key) ?? [];
        bucket.push(row);
        buckets.set(key, bucket);
    }

    return [...buckets.values()].map((bucket) => mergeTypeBucket(bucket));
}

function mergeTypeBucket(rows: InventoryByMainTypeReportRow[]): InventoryByMainTypeReportRow {
    const primary = [...rows].sort((left, right) => right.catalog_skus - left.catalog_skus)[0]!;
    const totals = sumInventoryRows(rows);
    const mainTypes = uniqueNonEmpty(rows.map((row) => row.main_type));
    const types = uniqueNonEmpty(rows.map((row) => row.type));

    return {
        ...primary,
        ...totals,
        type_label: primary.type_label,
        main_type: primary.main_type,
        drill_down_main_types: mainTypes.length > 1 ? mainTypes : undefined,
        drill_down_types: types.length > 1 ? types : undefined,
    };
}

function sortGroupRows(
    rows: InventoryByMainTypeReportRow[],
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryByMainTypeReportRow[] {
    const sorted = [...rows];
    sorted.sort((left, right) => compareInventoryByMainTypeRows(left, right, sortBy, sortDir));

    return sorted;
}

function sumInventoryRows(rows: InventoryByMainTypeReportRow[]): InventoryByMainTypeReportTotals {
    return rows.reduce<InventoryByMainTypeReportTotals>(
        (totals, row) => ({
            catalog_skus: totals.catalog_skus + row.catalog_skus,
            skus_on_hand: totals.skus_on_hand + row.skus_on_hand,
            quantity_on_hand: totals.quantity_on_hand + row.quantity_on_hand,
            not_arrived_skus: totals.not_arrived_skus + row.not_arrived_skus,
            not_arrived: totals.not_arrived + row.not_arrived,
            estimated_landed_value: addDecimalStrings(
                totals.estimated_landed_value,
                row.estimated_landed_value,
            ),
            estimated_not_landed_value: addDecimalStrings(
                totals.estimated_not_landed_value,
                row.estimated_not_landed_value,
            ),
            skus_missing_landed_cost:
                totals.skus_missing_landed_cost + row.skus_missing_landed_cost,
            units_received: totals.units_received + row.units_received,
            units_sold: totals.units_sold + row.units_sold,
        }),
        {
            catalog_skus: 0,
            skus_on_hand: 0,
            quantity_on_hand: 0,
            not_arrived_skus: 0,
            not_arrived: 0,
            estimated_landed_value: '0.00',
            estimated_not_landed_value: '0.00',
            skus_missing_landed_cost: 0,
            units_received: 0,
            units_sold: 0,
        },
    );
}

function addDecimalStrings(left: string, right: string): string {
    return (Number.parseFloat(left) + Number.parseFloat(right)).toFixed(2);
}

function groupAsComparableRow(group: InventoryByMainTypeNavbarGroup): InventoryByMainTypeReportRow {
    return {
        type: group.key,
        type_label: group.label,
        main_type: group.key,
        ...group.totals,
    };
}
