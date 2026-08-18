import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import StaffOrdersReportPage from '../StaffOrdersReportPage.vue';
import { STAFF_ORDERS_REPORT_TIMEOUT_MS } from '../../lib/staffOrdersReport';

const apiGet = vi.fn();

vi.mock('../../lib/api', () => ({
    api: {
        get: (...args: unknown[]) => apiGet(...args),
    },
}));

const sampleReport = {
    from_month: '2026-07',
    to_month: '2026-07',
    month: '2026-07',
    timezone: 'America/Toronto',
    columns: [
        { key: 'date', label: 'Date' },
        { key: 'alex_hui', label: 'Alex Hui' },
        { key: 'total', label: 'Total' },
    ],
    rows: [{ date: '2026-07-01', alex_hui: 1, total: 1 }],
    totals: { alex_hui: 1, total: 1 },
    revenue_rows: [{ date: '2026-07-01', alex_hui: '42.00', total: '42.00' }],
    revenue_totals: { alex_hui: '42.00', total: '42.00' },
    revenue_currency: 'CAD',
    orders_scanned: 1,
};

describe('StaffOrdersReportPage', () => {
    beforeEach(() => {
        apiGet.mockReset();
    });

    it('loads the current month report on mount with extended timeout', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-23T12:00:00'));

        apiGet.mockResolvedValue({ data: { data: sampleReport } });

        mount(StaffOrdersReportPage);
        await flushPromises();

        expect(apiGet).toHaveBeenCalledWith('/api/v1/reports/staff-orders', {
            params: { month: '2026-07' },
            timeout: STAFF_ORDERS_REPORT_TIMEOUT_MS,
        });

        vi.useRealTimers();
    });

    it('renders day rows and order count from the api payload', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-23T12:00:00'));

        apiGet.mockResolvedValue({ data: { data: sampleReport } });

        const wrapper = mount(StaffOrdersReportPage);
        await flushPromises();

        expect(wrapper.text()).toContain('Showing 1 days');
        expect(wrapper.text()).toContain('Orders counted: 1');
        expect(wrapper.text()).toContain('Revenue before tax');
        expect(wrapper.get('#staff-orders-view-mode').element).toBeTruthy();
        expect(wrapper.text()).toContain('Alex Hui');

        vi.useRealTimers();
    });

    it('switches the table to revenue when the view mode select changes', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-23T12:00:00'));

        apiGet.mockResolvedValue({ data: { data: sampleReport } });

        const wrapper = mount(StaffOrdersReportPage);
        await flushPromises();

        await wrapper.get('#staff-orders-view-mode').setValue('revenue');
        await flushPromises();

        expect(wrapper.text()).toMatch(/42\.00|CA\$42\.00|\$42\.00/);

        vi.useRealTimers();
    });

    it('shows an error when the api returns zero day rows', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-23T12:00:00'));

        apiGet.mockResolvedValue({
            data: {
                data: {
                    ...sampleReport,
                    rows: [],
                    revenue_rows: [],
                    orders_scanned: 0,
                },
            },
        });

        const wrapper = mount(StaffOrdersReportPage);
        await flushPromises();

        expect(wrapper.text()).toContain('zero day rows');
        expect(wrapper.text()).not.toContain('Orders counted: 0');

        vi.useRealTimers();
    });

    it('loads a chosen month when the month select changes', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-23T12:00:00'));

        apiGet.mockResolvedValue({ data: { data: sampleReport } });

        const wrapper = mount(StaffOrdersReportPage);
        await flushPromises();

        apiGet.mockClear();
        apiGet.mockResolvedValue({
            data: {
                data: {
                    ...sampleReport,
                    from_month: '2026-06',
                    to_month: '2026-06',
                    month: '2026-06',
                },
            },
        });

        await wrapper.get('#staff-orders-month').setValue('2026-06');
        await flushPromises();

        expect(apiGet).toHaveBeenCalledWith('/api/v1/reports/staff-orders', {
            params: { month: '2026-06' },
            timeout: STAFF_ORDERS_REPORT_TIMEOUT_MS,
        });

        vi.useRealTimers();
    });
});
