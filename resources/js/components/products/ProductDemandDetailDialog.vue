<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '../../lib/api';
import { formatLocalDate } from '../../lib/datetime';

type DemandDetail = {
    sku: string;
    sold_4w: number;
    window_days: number;
    detail_window_days: number;
    shopify_sold_4w: number;
    shopify_sold_12w: number;
    assumed_sold_4w: number;
    assumed_sold_12w: number;
    formula: string;
    weekly_rollups: Array<{
        week_start: string;
        shopify_sold: number;
        assumed_sold: number;
        total: number;
    }>;
    recent_shopify_lines: Array<{
        order_gid: string;
        order_name: string | null;
        order_admin_url: string | null;
        quantity: number;
        sold_on: string | null;
    }>;
    recent_shopify_lines_meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    recent_assumed_movements: Array<{
        kind: string;
        quantity: number;
        occurred_at: string | null;
        reference_type: string | null;
    }>;
};

type OrderLinkLike = {
    order_gid: string;
    order_name: string | null;
};

const props = defineProps<{
    open: boolean;
    productId: string | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const LINES_PER_PAGE = 10;

const loading = ref(false);
const linesLoading = ref(false);
const error = ref<string | null>(null);
const detail = ref<DemandDetail | null>(null);
const linesPage = ref(1);

const title = computed(() =>
    detail.value?.sku ? `Demand — ${detail.value.sku}` : 'Demand detail',
);

const linesMeta = computed(() => detail.value?.recent_shopify_lines_meta ?? null);
const linesLastPage = computed(() => linesMeta.value?.last_page ?? 1);
const linesTotal = computed(() => linesMeta.value?.total ?? 0);

function formatWeekLabel(weekStart: string): string {
    const start = formatLocalDate(weekStart);
    const endDate = new Date(`${weekStart}T12:00:00`);
    endDate.setDate(endDate.getDate() + 6);
    const end = formatLocalDate(endDate.toISOString().slice(0, 10));

    return `${start} – ${end}`;
}

function orderLinkLabel(line: OrderLinkLike): string {
    if (line.order_name) {
        return line.order_name;
    }

    const match = line.order_gid.match(/\/(\d+)$/);
    return match ? `#${match[1]}` : line.order_gid;
}

async function load(options: { linesOnly?: boolean } = {}): Promise<void> {
    if (!props.productId) return;

    if (options.linesOnly) {
        linesLoading.value = true;
    } else {
        loading.value = true;
        error.value = null;
        if (!options.linesOnly) {
            detail.value = null;
        }
    }

    try {
        const res = await api.get<{ data: DemandDetail }>(
            `/api/v1/products/${props.productId}/demand`,
            {
                params: {
                    lines_page: linesPage.value,
                    lines_per_page: LINES_PER_PAGE,
                },
            },
        );
        detail.value = res.data.data;
    } catch {
        error.value = 'Failed to load demand detail.';
    } finally {
        loading.value = false;
        linesLoading.value = false;
    }
}

function goToLinesPage(page: number): void {
    if (page < 1 || page > linesLastPage.value || page === linesPage.value) return;
    linesPage.value = page;
    void load({ linesOnly: true });
}

watch(
    () => [props.open, props.productId] as const,
    ([open, id], [, prevId]) => {
        if (!open || !id) return;
        if (id !== prevId) {
            linesPage.value = 1;
        }
        void load();
    },
    { immediate: true },
);
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        @click.self="emit('close')"
    >
        <div
            class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg border border-slate-200 bg-white p-5 shadow-xl"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ title }}</h2>
                    <p v-if="detail" class="mt-1 text-sm text-slate-600">
                        {{ detail.formula }} →
                        <span class="font-semibold tabular-nums">{{ detail.sold_4w }}</span>
                        units (last {{ detail.window_days }} days)
                    </p>
                </div>
                <button
                    type="button"
                    class="text-slate-600 hover:text-slate-900"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </div>

            <div v-if="loading" class="mt-6 text-sm text-slate-600">Loading…</div>
            <div
                v-if="error"
                class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ error }}
            </div>

            <template v-if="detail && !loading">
                <div class="mt-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                    <div class="rounded-md border border-slate-200 p-3">
                        <div class="text-slate-600">Shopify 4 wk</div>
                        <div class="text-lg font-semibold tabular-nums">
                            {{ detail.shopify_sold_4w }}
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 p-3">
                        <div class="text-slate-600">Shopify 12 wk</div>
                        <div class="text-lg font-semibold tabular-nums">
                            {{ detail.shopify_sold_12w }}
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 p-3">
                        <div class="text-slate-600">Assumed 4 wk</div>
                        <div class="text-lg font-semibold tabular-nums">
                            {{ detail.assumed_sold_4w }}
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 p-3">
                        <div class="text-slate-600">Assumed 12 wk</div>
                        <div class="text-lg font-semibold tabular-nums">
                            {{ detail.assumed_sold_12w }}
                        </div>
                    </div>
                </div>

                <h3 class="mt-6 text-sm font-medium text-slate-900">
                    Weekly rollups
                    <span class="font-normal text-slate-500"
                        >({{ detail.detail_window_days }}-day timeline)</span
                    >
                </h3>
                <p class="mt-1 text-xs text-slate-500">
                    Every week in the window is shown. Weeks with no sales display 0.
                </p>
                <div class="mt-2 max-h-72 overflow-y-auto rounded-md border border-slate-100">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 bg-white">
                            <tr class="text-left text-slate-600">
                                <th class="px-2 py-1">Week</th>
                                <th class="px-2 py-1 text-right">Shopify</th>
                                <th class="px-2 py-1 text-right">Assumed</th>
                                <th class="px-2 py-1 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in detail.weekly_rollups"
                                :key="row.week_start"
                                :class="row.total === 0 ? 'text-slate-400' : 'text-slate-900'"
                            >
                                <td class="px-2 py-1 whitespace-nowrap">
                                    {{ formatWeekLabel(row.week_start) }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ row.shopify_sold }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ row.assumed_sold }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ row.total }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-medium text-slate-900">Recent Shopify lines</h3>
                    <div v-if="linesTotal > 0" class="text-xs text-slate-500">
                        {{ linesTotal }} line{{ linesTotal === 1 ? '' : 's' }}
                    </div>
                </div>
                <div :class="linesLoading ? 'opacity-50' : ''">
                    <ul class="mt-2 space-y-1 text-xs text-slate-700">
                        <li
                            v-for="(line, i) in detail.recent_shopify_lines"
                            :key="`${line.order_gid}-${i}`"
                        >
                            {{ line.sold_on }} · qty {{ line.quantity }} ·
                            <a
                                v-if="line.order_admin_url"
                                :href="line.order_admin_url"
                                class="text-sky-700 underline hover:text-sky-900"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ orderLinkLabel(line) }}
                            </a>
                            <span v-else>{{ orderLinkLabel(line) }}</span>
                        </li>
                        <li v-if="detail.recent_shopify_lines.length === 0" class="text-slate-500">
                            None
                        </li>
                    </ul>
                </div>
                <div
                    v-if="linesLastPage > 1"
                    class="mt-3 flex items-center justify-between gap-2 text-xs text-slate-600"
                >
                    <button
                        type="button"
                        class="rounded border border-slate-200 px-2 py-1 hover:bg-slate-50 disabled:opacity-40"
                        :disabled="linesLoading || linesPage <= 1"
                        @click="goToLinesPage(linesPage - 1)"
                    >
                        Previous
                    </button>
                    <span>Page {{ linesPage }} of {{ linesLastPage }}</span>
                    <button
                        type="button"
                        class="rounded border border-slate-200 px-2 py-1 hover:bg-slate-50 disabled:opacity-40"
                        :disabled="linesLoading || linesPage >= linesLastPage"
                        @click="goToLinesPage(linesPage + 1)"
                    >
                        Next
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
