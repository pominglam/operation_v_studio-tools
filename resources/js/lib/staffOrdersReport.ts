export type StaffOrdersReportColumn = {
    key: string;
    label: string;
};

export type StaffOrdersReportRow = {
    date: string;
    total: number;
    [bucket: string]: string | number;
};

export type StaffOrdersReportRevenueRow = {
    date: string;
    total: string;
    [bucket: string]: string;
};

export type StaffOrdersReport = {
    from_month: string;
    to_month: string;
    month?: string | null;
    timezone: string;
    data_source?: string;
    columns: StaffOrdersReportColumn[];
    rows: StaffOrdersReportRow[];
    totals: Record<string, number>;
    revenue_rows: StaffOrdersReportRevenueRow[];
    revenue_totals: Record<string, string>;
    revenue_currency?: string;
    orders_scanned: number;
    orders_missing_attribution?: number;
    orders_missing_subtotal?: number;
};

/** Mirror reads are fast; keep a modest guard for slow networks. */
export const STAFF_ORDERS_REPORT_TIMEOUT_MS = 60_000;

export function formatStaffOrdersRevenue(amount: string, currency = 'CAD'): string {
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

export function parseStaffOrdersReportResponse(payload: unknown): StaffOrdersReport {
    if (payload === null || typeof payload !== 'object') {
        throw new Error('Staff orders report response was empty.');
    }

    const body = payload as { data?: StaffOrdersReport };
    const report = body.data;
    if (report === null || typeof report !== 'object') {
        throw new Error('Staff orders report payload is missing.');
    }

    if (!Array.isArray(report.rows)) {
        throw new Error('Staff orders report rows are missing.');
    }

    if (report.rows.length === 0) {
        throw new Error('Staff orders report returned zero day rows.');
    }

    if (!Array.isArray(report.columns) || report.columns.length === 0) {
        throw new Error('Staff orders report columns are missing.');
    }

    if (!Array.isArray(report.revenue_rows)) {
        throw new Error('Staff orders report revenue rows are missing.');
    }

    if (report.revenue_rows.length === 0) {
        throw new Error('Staff orders report returned zero revenue day rows.');
    }

    return report;
}
