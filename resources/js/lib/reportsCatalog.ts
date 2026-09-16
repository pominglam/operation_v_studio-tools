export type ReportDefinition = {
    id: string;
    routeName: string;
    path: string;
    label: string;
    description: string;
};

export const REPORT_DEFINITIONS: ReportDefinition[] = [
    {
        id: 'staff-orders',
        routeName: 'reports-staff-orders',
        path: '/reports/staff-orders',
        label: 'Staff orders',
        description:
            'Daily order counts and revenue before tax by POS staff and sales channel for one calendar month.',
    },
    {
        id: 'inventory-by-main-type',
        routeName: 'reports-inventory-by-main-type',
        path: '/reports/inventory-by-main-type',
        label: 'Inventory by type',
        description:
            'On-hand units grouped by taxonomy department and product line (or Tools & Supplies shelf), with unique SKU counts and estimated landed value.',
    },
    {
        id: 'customer-retention',
        routeName: 'reports-customer-retention',
        path: '/reports/customer-retention',
        label: 'Customer retention',
        description:
            'Identified Shopify customers: month-by-month New vs returning (chart + table), AOV, store-rhythm cadence, and store-quintile RFM.',
    },
];

export function reportDefinitionForRouteName(
    routeName: string | null | undefined,
): ReportDefinition | null {
    if (!routeName) {
        return null;
    }

    return REPORT_DEFINITIONS.find((report) => report.routeName === routeName) ?? null;
}
