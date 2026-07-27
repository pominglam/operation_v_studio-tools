import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import StaffOrdersReportPage from '../StaffOrdersReportPage.vue';

const apiGet = vi.fn();

vi.mock('../../lib/api', () => ({
    api: {
        get: (...args: unknown[]) => apiGet(...args),
    },
}));

describe('StaffOrdersReportPage', () => {
    beforeEach(() => {
        apiGet.mockReset();
    });

    it('loads the current month report on mount', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-23T12:00:00'));

        apiGet.mockResolvedValue({
            data: {
                data: {
                    month: '2026-07',
                    timezone: 'America/Toronto',
                    columns: [
                        { key: 'date', label: 'Date' },
                        { key: 'alex_hui', label: 'Alex Hui' },
                        { key: 'total', label: 'Total' },
                    ],
                    rows: [{ date: '2026-07-01', alex_hui: 1, total: 1 }],
                    totals: { alex_hui: 1, total: 1 },
                    orders_scanned: 1,
                },
            },
        });

        mount(StaffOrdersReportPage);
        await Promise.resolve();

        expect(apiGet).toHaveBeenCalledWith('/api/v1/reports/staff-orders', {
            params: { month: '2026-07' },
        });

        vi.useRealTimers();
    });

    it('requests the previous month when Previous is clicked', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-23T12:00:00'));

        apiGet.mockResolvedValue({
            data: {
                data: {
                    month: '2026-07',
                    timezone: 'America/Toronto',
                    columns: [{ key: 'date', label: 'Date' }],
                    rows: [],
                    totals: {},
                    orders_scanned: 0,
                },
            },
        });

        const wrapper = mount(StaffOrdersReportPage);
        await Promise.resolve();

        apiGet.mockClear();
        apiGet.mockResolvedValue({
            data: {
                data: {
                    month: '2026-06',
                    timezone: 'America/Toronto',
                    columns: [{ key: 'date', label: 'Date' }],
                    rows: [],
                    totals: {},
                    orders_scanned: 0,
                },
            },
        });

        await wrapper.get('button').trigger('click');
        await Promise.resolve();

        expect(apiGet).toHaveBeenCalledWith('/api/v1/reports/staff-orders', {
            params: { month: '2026-06' },
        });

        vi.useRealTimers();
    });
});
