import { describe, expect, it } from 'vitest';

import {
    formatStaffOrdersRevenue,
    parseStaffOrdersReportResponse,
    STAFF_ORDERS_REPORT_TIMEOUT_MS,
} from '../staffOrdersReport';

describe('staffOrdersReport', () => {
    it('uses a timeout longer than the default axios client', () => {
        expect(STAFF_ORDERS_REPORT_TIMEOUT_MS).toBeGreaterThanOrEqual(60_000);
    });

    it('parses a valid report payload', () => {
        const report = parseStaffOrdersReportResponse({
            data: {
                month: '2026-07',
                timezone: 'America/Toronto',
                columns: [{ key: 'date', label: 'Date' }],
                rows: [{ date: '2026-07-01', total: 1 }],
                totals: { total: 1 },
                revenue_rows: [{ date: '2026-07-01', total: '10.00' }],
                revenue_totals: { total: '10.00' },
                orders_scanned: 1,
            },
        });

        expect(report.month).toBe('2026-07');
        expect(report.rows).toHaveLength(1);
    });

    it('formats revenue as currency', () => {
        expect(formatStaffOrdersRevenue('42.75', 'CAD')).toMatch(/42\.75/);
    });

    it('rejects empty day rows', () => {
        expect(() =>
            parseStaffOrdersReportResponse({
                data: {
                    month: '2026-07',
                    timezone: 'America/Toronto',
                    columns: [{ key: 'date', label: 'Date' }],
                    rows: [],
                    revenue_rows: [],
                    totals: {},
                    orders_scanned: 0,
                },
            }),
        ).toThrow('zero day rows');
    });

    it('rejects empty revenue day rows', () => {
        expect(() =>
            parseStaffOrdersReportResponse({
                data: {
                    month: '2026-07',
                    timezone: 'America/Toronto',
                    columns: [{ key: 'date', label: 'Date' }],
                    rows: [{ date: '2026-07-01', total: 1 }],
                    revenue_rows: [],
                    totals: { total: 1 },
                    orders_scanned: 1,
                },
            }),
        ).toThrow('zero revenue day rows');
    });
});
