<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import {
    formatStaffOrdersRevenue,
    parseStaffOrdersReportResponse,
    STAFF_ORDERS_REPORT_TIMEOUT_MS,
    type StaffOrdersReport,
    type StaffOrdersReportColumn,
    type StaffOrdersReportRevenueRow,
    type StaffOrdersReportRow,
} from '../lib/staffOrdersReport';

type ReportColumn = StaffOrdersReportColumn;
type ReportRow = StaffOrdersReportRow;
type RevenueRow = StaffOrdersReportRevenueRow;
type ViewMode = 'count' | 'revenue';

const viewModeOptions: Array<{ value: ViewMode; label: string }> = [
    { value: 'count', label: 'Order counts' },
    { value: 'revenue', label: 'Revenue before tax' },
];

function currentMonthValue(): string {
    const now = new Date();
    const year = now.getFullYear();
    const monthValue = String(now.getMonth() + 1).padStart(2, '0');
    return `${year}-${monthValue}`;
}

function shiftMonth(month: string, delta: number): string {
    const [yearText, monthText] = month.split('-');
    const date = new Date(Number(yearText), Number(monthText) - 1 + delta, 1);
    const year = date.getFullYear();
    const nextMonth = String(date.getMonth() + 1).padStart(2, '0');
    return `${year}-${nextMonth}`;
}

function formatMonthLabel(month: string): string {
    const [yearText, monthText] = month.split('-');
    const date = new Date(Number(yearText), Number(monthText) - 1, 1);
    return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

function formatDayLabel(date: string): string {
    const parsed = new Date(`${date}T12:00:00`);
    return parsed.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

const loading = ref(false);
const error = ref<string | null>(null);
const month = ref(currentMonthValue());
const viewMode = ref<ViewMode>('revenue');
const report = ref<StaffOrdersReport | null>(null);

const columns = computed<ReportColumn[]>(() => report.value?.columns ?? []);
const rows = computed<ReportRow[]>(() => report.value?.rows ?? []);
const totals = computed<Record<string, number>>(() => report.value?.totals ?? {});
const revenueRows = computed<RevenueRow[]>(() => report.value?.revenue_rows ?? []);
const revenueTotals = computed<Record<string, string>>(() => report.value?.revenue_totals ?? {});
const revenueCurrency = computed<string>(() => report.value?.revenue_currency ?? 'CAD');
const ordersScanned = computed<number>(() => report.value?.orders_scanned ?? 0);
const ordersMissingAttribution = computed<number>(
    () => report.value?.orders_missing_attribution ?? 0,
);
const ordersMissingSubtotal = computed<number>(() => report.value?.orders_missing_subtotal ?? 0);
const earliestMonth = '2025-12';

const monthOptions = computed<Array<{ value: string; label: string }>>(() => {
    const options: Array<{ value: string; label: string }> = [];
    const latest = currentMonthValue();
    let cursor = earliestMonth;

    while (cursor <= latest) {
        options.unshift({ value: cursor, label: formatMonthLabel(cursor) });
        cursor = shiftMonth(cursor, 1);
    }

    return options;
});

const canGoNext = computed<boolean>(() => month.value < currentMonthValue());
const isRevenueView = computed<boolean>(() => viewMode.value === 'revenue');

function cellValue(row: ReportRow | RevenueRow, columnKey: string): string {
    if (columnKey === 'date') {
        return formatDayLabel(String(row.date));
    }

    if (isRevenueView.value) {
        return formatRevenue(String((row as RevenueRow)[columnKey] ?? '0.00'));
    }

    return String((row as ReportRow)[columnKey] ?? 0);
}

function footerValue(columnKey: string): string {
    if (columnKey === 'date') {
        return 'Total';
    }

    if (isRevenueView.value) {
        return formatRevenue(String(revenueTotals.value[columnKey] ?? '0.00'));
    }

    return String(totals.value[columnKey] ?? 0);
}

function formatRevenue(amount: string): string {
    return formatStaffOrdersRevenue(amount, revenueCurrency.value);
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const res = await api.get('/api/v1/reports/staff-orders', {
            params: { month: month.value },
            timeout: STAFF_ORDERS_REPORT_TIMEOUT_MS,
        });
        report.value = parseStaffOrdersReportResponse(res.data);
    } catch (e: unknown) {
        if (e instanceof Error && e.message.includes('Staff orders report')) {
            error.value = e.message;
        } else if (e instanceof Error && e.message.toLowerCase().includes('timeout')) {
            error.value = 'Staff orders report timed out. Try again in a moment.';
        } else {
            error.value = 'Failed to load staff orders report.';
        }
        report.value = null;
    } finally {
        loading.value = false;
    }
}

function goToPreviousMonth(): void {
    month.value = shiftMonth(month.value, -1);
}

function goToNextMonth(): void {
    month.value = shiftMonth(month.value, 1);
}

watch(month, () => void load());

onMounted(() => void load());
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Staff orders</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Daily order counts and revenue before tax by POS staff and sales channel for
                    one calendar month.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                    @click="goToPreviousMonth"
                >
                    Previous
                </button>
                <label class="sr-only" for="staff-orders-month">Report month</label>
                <select
                    id="staff-orders-month"
                    v-model="month"
                    class="min-w-[10rem] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900"
                >
                    <option
                        v-for="option in monthOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!canGoNext"
                    @click="goToNextMonth"
                >
                    Next
                </button>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-700">
            <div>
                Showing {{ rows.length }} days
                <span v-if="report?.timezone">({{ report.timezone }})</span>
            </div>
            <div v-if="!loading && !error">Orders counted: {{ ordersScanned }}</div>
            <div v-if="!loading && !error">
                Revenue before tax:
                {{ formatRevenue(revenueTotals.total ?? '0.00') }}
            </div>
        </div>

        <div
            v-if="!loading && !error && ordersMissingAttribution > 0"
            class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            {{ ordersMissingAttribution }} eligible order(s) in this month are missing staff/channel
            attribution in the mirror. Run
            <code class="rounded bg-white px-1">php artisan shopify:orders-backfill-staff-attribution {{ month }}</code>
            once, or wait for the next order sync to backfill updated rows.
        </div>

        <div
            v-if="!loading && !error && ordersMissingSubtotal > 0"
            class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            {{ ordersMissingSubtotal }} attributed order(s) are missing subtotal in the mirror, so
            revenue totals may be low. Re-run
            <code class="rounded bg-white px-1">php artisan shopify:orders-backfill-staff-attribution {{ month }}</code>
            after order sync picks up subtotals, or wait for the next incremental sync.
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3"
            >
                <label class="flex items-center gap-2 text-sm font-medium text-slate-900">
                    <span>Show</span>
                    <select
                        id="staff-orders-view-mode"
                        v-model="viewMode"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900"
                    >
                        <option
                            v-for="option in viewModeOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <span v-if="isRevenueView">({{ revenueCurrency }})</span>
                </label>
            </div>
            <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading report…</div>
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr
                            class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                class="px-4 py-3"
                                :class="column.key === 'date' ? '' : 'text-right tabular-nums'"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="row in isRevenueView ? revenueRows : rows"
                            :key="row.date"
                            class="hover:bg-slate-50"
                        >
                            <td
                                v-for="column in columns"
                                :key="`${row.date}-${column.key}`"
                                class="px-4 py-3"
                                :class="
                                    column.key === 'date'
                                        ? 'text-slate-700'
                                        : 'text-right tabular-nums text-slate-900'
                                "
                            >
                                {{ cellValue(row, column.key) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr class="font-semibold text-slate-900">
                            <td
                                v-for="column in columns"
                                :key="`total-${column.key}`"
                                class="px-4 py-3"
                                :class="
                                    column.key === 'date'
                                        ? 'text-slate-700'
                                        : 'text-right tabular-nums'
                                "
                            >
                                {{ footerValue(column.key) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
</template>
