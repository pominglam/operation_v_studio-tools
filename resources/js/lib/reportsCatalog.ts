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
            'On-hand units grouped by product main type, with unique SKU counts and estimated landed value.',
    },
];

export function reportDefinitionForRouteName(routeName: string | null | undefined): ReportDefinition | null {
    if (!routeName) {
        return null;
    }

    return REPORT_DEFINITIONS.find((report) => report.routeName === routeName) ?? null;
}
