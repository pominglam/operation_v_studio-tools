<script setup lang="ts">
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import type { Chart as ChartInstance } from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    currentRetentionMonthKey,
    formatRepeatRate,
    formatRetentionMoney,
    formatRetentionMonthLabel,
} from '../../lib/customerRetention';
import type { CustomerRetentionMonthRow } from '../../types/customerRetention';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
);

const NEW_BAR = '#38bdf8';
const RETURNING_BAR = '#10b981';
const NEW_LINE = '#fb923c';
const RETURNING_LINE = '#a78bfa';
const TOTAL_LINE = '#64748b';
const HALO_PREFIX = '_halo:';

const props = defineProps<{
    months: CustomerRetentionMonthRow[];
    currency: string;
}>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
let chart: ChartInstance | null = null;

function fadeHex(hex: string, alpha: number): string {
    const raw = hex.replace('#', '');
    const n = Number.parseInt(raw, 16);
    if (!Number.isFinite(n)) return hex;
    const r = (n >> 16) & 255;
    const g = (n >> 8) & 255;
    const b = n & 255;

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function isHalo(label: string | undefined): boolean {
    return (label ?? '').startsWith(HALO_PREFIX);
}

function spendLine(options: {
    label: string;
    data: number[];
    color: string;
    incomplete: boolean[];
    borderWidth: number;
    pointRadius: number;
    dashed?: boolean;
    order: number;
}): object[] {
    const dashed = options.dashed === true;

    return [
        {
            type: 'line',
            label: `${HALO_PREFIX}${options.label}`,
            data: options.data,
            yAxisID: 'ySpend',
            borderColor: options.incomplete.map((isOpen) =>
                isOpen ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.85)',
            ),
            backgroundColor: '#ffffff',
            pointBackgroundColor: '#ffffff',
            pointRadius: options.pointRadius,
            borderWidth: options.borderWidth + 2,
            tension: 0.25,
            order: options.order + 1,
        },
        {
            type: 'line',
            label: options.label,
            data: options.data,
            yAxisID: 'ySpend',
            borderColor: options.incomplete.map((isOpen) =>
                fadeHex(options.color, isOpen ? 0.35 : 0.72),
            ),
            backgroundColor: options.color,
            pointBackgroundColor: options.incomplete.map((isOpen) =>
                fadeHex(options.color, isOpen ? 0.35 : 0.72),
            ),
            pointBorderColor: 'rgba(255,255,255,0.9)',
            pointBorderWidth: 1,
            pointRadius: options.pointRadius,
            borderWidth: options.borderWidth,
            borderDash: dashed ? [6, 4] : [],
            tension: 0.25,
            order: options.order,
        },
    ];
}

function render(): void {
    const canvas = canvasRef.value;
    if (canvas === null || props.months.length === 0) {
        return;
    }

    const currentMonth = currentRetentionMonthKey();
    const incomplete = props.months.map((row) => row.month === currentMonth);
    const labels = props.months.map((row) => formatRetentionMonthLabel(row.month));
    const acquired = props.months.map((row) => row.acquired);
    const returning = props.months.map((row) => row.returning_buyers);
    const spend = props.months.map((row) => Number(row.spend));
    const acquiredSpend = props.months.map((row) => Number(row.acquired_spend));
    const returningSpend = props.months.map((row) => Number(row.returning_spend));

    chart?.destroy();
    chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'New',
                    data: acquired,
                    stack: 'buyers',
                    backgroundColor: incomplete.map((isOpen) =>
                        fadeHex(NEW_BAR, isOpen ? 0.32 : 1),
                    ),
                    borderWidth: 0,
                    yAxisID: 'yBuyers',
                    order: 4,
                },
                {
                    type: 'bar',
                    label: 'Returning',
                    data: returning,
                    stack: 'buyers',
                    backgroundColor: incomplete.map((isOpen) =>
                        fadeHex(RETURNING_BAR, isOpen ? 0.32 : 1),
                    ),
                    borderWidth: 0,
                    yAxisID: 'yBuyers',
                    order: 4,
                },
                ...spendLine({
                    label: 'New spend',
                    data: acquiredSpend,
                    color: NEW_LINE,
                    incomplete,
                    borderWidth: 1.5,
                    pointRadius: 2,
                    dashed: true,
                    order: 2,
                }),
                ...spendLine({
                    label: 'Returning spend',
                    data: returningSpend,
                    color: RETURNING_LINE,
                    incomplete,
                    borderWidth: 1.5,
                    pointRadius: 2,
                    dashed: true,
                    order: 2,
                }),
                ...spendLine({
                    label: 'Total spend',
                    data: spend,
                    color: TOTAL_LINE,
                    incomplete,
                    borderWidth: 2,
                    pointRadius: 2.5,
                    order: 1,
                }),
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        font: { size: 12 },
                        filter: (item) => !isHalo(item.text),
                    },
                },
                tooltip: {
                    filter: (item) => !isHalo(item.dataset.label),
                    callbacks: {
                        label: (item) => {
                            const name = item.dataset.label ?? '';
                            const row = props.months[item.dataIndex];
                            if (row !== undefined && name === 'New spend') {
                                return `${name} ${formatRetentionMoney(row.acquired_spend, props.currency)}`;
                            }
                            if (row !== undefined && name === 'Returning spend') {
                                return `${name} ${formatRetentionMoney(row.returning_spend, props.currency)}`;
                            }
                            if (row !== undefined && name === 'Total spend') {
                                return `${name} ${formatRetentionMoney(row.spend, props.currency)}`;
                            }

                            return `${name} ${String(item.raw ?? '')}`;
                        },
                        afterBody: (items) => {
                            const index = items[0]?.dataIndex;
                            if (index === undefined) return [];
                            const row = props.months[index];
                            if (row === undefined) return [];
                            const note = row.month === currentMonth ? 'Incomplete month.' : '';
                            return [
                                `Return rate ${formatRepeatRate(row.buyer_return_rate)}`,
                                `AOV ${formatRetentionMoney(row.aov, props.currency)}`,
                                note,
                            ].filter((line) => line !== '');
                        },
                    },
                },
            },
            scales: {
                x: { stacked: true, grid: { display: false } },
                yBuyers: {
                    stacked: true,
                    position: 'left',
                    title: { display: true, text: 'Identified buyers', color: '#0f172a' },
                    grid: { color: '#f1f5f9' },
                    beginAtZero: true,
                    ticks: { precision: 0, color: '#334155' },
                },
                ySpend: {
                    position: 'right',
                    title: { display: true, text: `Spend (${props.currency})`, color: '#94a3b8' },
                    grid: { drawOnChartArea: false },
                    beginAtZero: true,
                    ticks: {
                        color: '#94a3b8',
                        callback: (value) => {
                            const amount = typeof value === 'number' ? value : Number(value);
                            if (!Number.isFinite(amount)) return '';
                            return `$${(amount / 1000).toFixed(amount >= 1000 ? 0 : 1)}k`;
                        },
                    },
                },
            },
        },
    });
}

onMounted(() => {
    render();
});

watch(
    () => [props.months, props.currency],
    () => {
        render();
    },
);

onBeforeUnmount(() => {
    chart?.destroy();
    chart = null;
});
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-3 py-2">
            <h2 class="text-sm font-semibold text-slate-900">Identified buyers and spend</h2>
            <p class="mt-0.5 text-xs text-slate-500">
                Columns are the main read: New + Returning buyers. Lighter overlay lines are New
                spend (orange), Returning spend (violet), and Total spend. The current Eastern Time
                (Montreal) month is faded until it closes.
            </p>
        </div>
        <div class="h-80 px-3 py-2 sm:h-96">
            <canvas ref="canvasRef" role="img" aria-label="Identified buyers and spend by month" />
        </div>
    </div>
</template>
