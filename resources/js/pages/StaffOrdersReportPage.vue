<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';

type ReportColumn = {
    key: string;
    label: string;
};

type ReportRow = {
    date: string;
    total: number;
    [bucket: string]: string | number;
};

type StaffOrdersReport = {
    month: string;
    timezone: string;
    columns: ReportColumn[];
    rows: ReportRow[];
    totals: Record<string, number>;
    orders_scanned: number;
};

function currentMonthValue(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    return `${year}-${month}`;
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
const report = ref<StaffOrdersReport | null>(null);

const columns = computed<ReportColumn[]>(() => report.value?.columns ?? []);
const rows = computed<ReportRow[]>(() => report.value?.rows ?? []);
const totals = computed<Record<string, number>>(() => report.value?.totals ?? {});
const ordersScanned = computed<number>(() => report.value?.orders_scanned ?? 0);
const monthLabel = computed<string>(() => formatMonthLabel(month.value));

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const res = await api.get<{ data: StaffOrdersReport }>('/api/v1/reports/staff-orders', {
            params: { month: month.value },
        });
        report.value = res.data.data;
    } catch {
        error.value = 'Failed to load staff orders report.';
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
                    Daily order counts by POS staff and sales channel for one calendar month.
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
                <div class="min-w-[10rem] text-center text-sm font-medium text-slate-900">
                    {{ monthLabel }}
                </div>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
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
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading…</div>
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
                        <tr v-for="row in rows" :key="row.date" class="hover:bg-slate-50">
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
                                <span v-if="column.key === 'date'">{{ formatDayLabel(String(row.date)) }}</span>
                                <span v-else>{{ row[column.key] ?? 0 }}</span>
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
                                <span v-if="column.key === 'date'">Total</span>
                                <span v-else>{{ totals[column.key] ?? 0 }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
</template>
