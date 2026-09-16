<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import CustomerRetentionMonthlyChart from '../components/customerRetention/CustomerRetentionMonthlyChart.vue';
import CustomerRetentionMonthlyTable from '../components/customerRetention/CustomerRetentionMonthlyTable.vue';
import CustomerRetentionPeopleTable from '../components/customerRetention/CustomerRetentionPeopleTable.vue';
import { api, extractApiError } from '../lib/api';
import {
    formatRepeatRate,
    formatRetentionMoney,
    isCustomerCadenceStatus,
    isCustomerChurnStatus,
    isCustomerRetentionSortKey,
    isCustomerRetentionTab,
    parseCustomerRetentionIndexResponse,
    parseCustomerRetentionShowResponse,
} from '../lib/customerRetention';
import { loadPageState, savePageState } from '../lib/pageState';
import type {
    CustomerRetentionOrder,
    CustomerRetentionPerson,
    CustomerRetentionRfmCatalogRow,
    CustomerRetentionSortKey,
    CustomerRetentionSummary,
    CustomerRetentionTab,
} from '../types/customerRetention';

const PAGE_STATE_KEY = 'customer-retention:list:v3';

const activeTab = ref<CustomerRetentionTab>('people');
const search = ref('');
const frequency = ref('');
const rfmGroup = ref('');
const churn = ref('');
const cadence = ref('');
const sortBy = ref<CustomerRetentionSortKey>('last_order_at');
const sortDir = ref<'asc' | 'desc'>('desc');
const page = ref(1);

const loading = ref(false);
const error = ref<string | null>(null);
const rows = ref<CustomerRetentionPerson[]>([]);
const summary = ref<CustomerRetentionSummary | null>(null);
const meta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
const expandedId = ref<string | null>(null);
const expandedOrders = ref<CustomerRetentionOrder[]>([]);
const ordersLoading = ref(false);
let bootstrapping = true;

const currency = computed(() => summary.value?.revenue_currency ?? 'CAD');
const catalog = computed<CustomerRetentionRfmCatalogRow[]>(() => summary.value?.rfm_catalog ?? []);
const months = computed(() => summary.value?.months ?? []);
const lastPage = computed(() => meta.value.last_page);

function persistState(): void {
    savePageState(PAGE_STATE_KEY, {
        tab: activeTab.value,
        search: search.value,
        frequency: frequency.value,
        rfmGroup: rfmGroup.value,
        churn: churn.value,
        cadence: cadence.value,
        sortBy: sortBy.value,
        sortDir: sortDir.value,
        page: page.value,
    });
}

function restoreState(): void {
    const saved = loadPageState<{
        tab?: string;
        search?: string;
        frequency?: string;
        rfmGroup?: string;
        churn?: string;
        cadence?: string;
        sortBy?: string;
        sortDir?: 'asc' | 'desc';
        page?: number;
    }>(PAGE_STATE_KEY);
    if (!saved) return;
    if (typeof saved.tab === 'string' && isCustomerRetentionTab(saved.tab)) {
        activeTab.value = saved.tab;
    }
    if (typeof saved.search === 'string') search.value = saved.search;
    if (typeof saved.frequency === 'string') frequency.value = saved.frequency;
    if (typeof saved.rfmGroup === 'string') rfmGroup.value = saved.rfmGroup;
    if (
        typeof saved.churn === 'string' &&
        (saved.churn === '' || isCustomerChurnStatus(saved.churn))
    ) {
        churn.value = saved.churn;
    }
    if (
        typeof saved.cadence === 'string' &&
        (saved.cadence === '' || isCustomerCadenceStatus(saved.cadence))
    ) {
        cadence.value = saved.cadence;
    }
    if (typeof saved.sortBy === 'string' && isCustomerRetentionSortKey(saved.sortBy)) {
        sortBy.value = saved.sortBy;
    }
    if (saved.sortDir === 'asc' || saved.sortDir === 'desc') sortDir.value = saved.sortDir;
    if (typeof saved.page === 'number' && saved.page >= 1) page.value = saved.page;
}

function toggleSort(column: CustomerRetentionSortKey): void {
    if (sortBy.value === column) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }
    sortBy.value = column;
    sortDir.value =
        column === 'display_name' || column === 'frequency_label' || column === 'rfm_group'
            ? 'asc'
            : 'desc';
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get('/api/v1/reports/customer-retention', {
            params: {
                search: search.value || undefined,
                frequency: frequency.value || undefined,
                rfm_group: rfmGroup.value || undefined,
                churn: churn.value || undefined,
                cadence: cadence.value || undefined,
                sort_by: sortBy.value,
                sort_dir: sortDir.value,
                page: page.value,
            },
        });
        const parsed = parseCustomerRetentionIndexResponse(res.data);
        rows.value = parsed.data;
        summary.value = parsed.summary;
        meta.value = parsed.meta;
        if (page.value > parsed.meta.last_page) page.value = parsed.meta.last_page;
    } catch (err: unknown) {
        error.value = extractApiError(err);
        rows.value = [];
    } finally {
        loading.value = false;
    }
}

async function togglePerson(person: CustomerRetentionPerson): Promise<void> {
    if (expandedId.value === person.id) {
        expandedId.value = null;
        expandedOrders.value = [];
        return;
    }
    expandedId.value = person.id;
    ordersLoading.value = true;
    expandedOrders.value = [];
    try {
        const res = await api.get(`/api/v1/reports/customer-retention/${person.id}`);
        expandedOrders.value = parseCustomerRetentionShowResponse(res.data).orders;
    } catch (err: unknown) {
        error.value = extractApiError(err);
    } finally {
        ordersLoading.value = false;
    }
}

onMounted(async () => {
    restoreState();
    await load();
    bootstrapping = false;
});

watch(activeTab, () => {
    if (bootstrapping) return;
    persistState();
});

watch([search, frequency, rfmGroup, churn, cadence, sortBy, sortDir], () => {
    if (bootstrapping) return;
    page.value = 1;
    persistState();
    void load();
});

watch(page, () => {
    if (bootstrapping) return;
    persistState();
    void load();
});
</script>

<template>
    <section class="space-y-4">
        <div v-if="summary" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                <div class="text-xs text-slate-500">Identified people</div>
                <div class="text-lg font-semibold text-slate-900">{{ summary.people }}</div>
                <div class="text-xs text-slate-500">
                    {{ summary.identified_orders }} of {{ summary.eligible_orders }} eligible orders
                </div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                <div class="text-xs text-slate-500">Repeat rate (2+)</div>
                <div class="text-lg font-semibold text-slate-900">
                    {{ formatRepeatRate(summary.repeat_rate) }}
                </div>
                <div class="text-xs text-slate-500">
                    {{ summary.repeat_count }} people with 2+ orders
                </div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                <div class="text-xs text-slate-500">New / Repeat / Loyal</div>
                <div class="text-lg font-semibold text-slate-900">
                    {{ summary.new_count }} / {{ summary.repeat_count }} / {{ summary.loyal_count }}
                </div>
                <div class="text-xs text-slate-500">Loyal is the 3+ slice of Repeat</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                <div class="text-xs text-slate-500">Identified spend</div>
                <div class="text-lg font-semibold text-slate-900">
                    {{ formatRetentionMoney(summary.identified_spend, currency) }}
                </div>
                <div class="text-xs text-slate-500">
                    Unidentified
                    {{ formatRetentionMoney(summary.unidentified_spend, currency) }} ({{
                        summary.unidentified_orders
                    }}
                    orders)
                </div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                <div class="text-xs text-slate-500">Identified AOV</div>
                <div class="text-lg font-semibold text-slate-900">
                    {{ formatRetentionMoney(summary.identified_aov, currency) }}
                </div>
                <div class="text-xs text-slate-500">Identified spend ÷ identified orders</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                <div class="text-xs text-slate-500">On cadence / Due / Lapsed</div>
                <div class="text-lg font-semibold text-slate-900">
                    {{ summary.cadence_on_count }} / {{ summary.cadence_due_count }} /
                    {{ summary.cadence_lapsed_count }}
                </div>
                <div class="text-xs text-slate-500">
                    Lapsed after {{ summary.store_cadence.lapsed_after_days }}d (2 ×
                    {{ summary.store_cadence.median_gap_days }}d store median of
                    {{ summary.store_cadence.gap_floor_days }}+ day gaps, floor 60). Own-pace
                    churned: {{ summary.churned_count }}
                </div>
            </div>
        </div>

        <p v-if="summary" class="text-xs text-slate-500">
            {{ summary.scoring_note }} Months use Eastern Time (Montreal). Click an RFM name for
            Shopify’s rule. ⓘ explains R/F/M, AOV, store rhythm, and own-pace.
        </p>

        <div class="border-b border-slate-200">
            <div class="flex flex-wrap items-end gap-2" role="tablist" aria-label="Retention views">
                <button
                    class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium"
                    role="tab"
                    type="button"
                    :aria-selected="activeTab === 'months'"
                    :class="
                        activeTab === 'months'
                            ? 'border-slate-200 border-b-white bg-white text-slate-900'
                            : 'border-transparent text-slate-600 hover:text-slate-900'
                    "
                    @click="activeTab = 'months'"
                >
                    Month by month
                </button>
                <button
                    class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium"
                    role="tab"
                    type="button"
                    :aria-selected="activeTab === 'people'"
                    :class="
                        activeTab === 'people'
                            ? 'border-slate-200 border-b-white bg-white text-slate-900'
                            : 'border-transparent text-slate-600 hover:text-slate-900'
                    "
                    @click="activeTab = 'people'"
                >
                    People
                </button>
            </div>
        </div>

        <div v-if="activeTab === 'months' && months.length > 0" class="space-y-4">
            <CustomerRetentionMonthlyChart :months="months" :currency="currency" />
            <CustomerRetentionMonthlyTable :months="months" :currency="currency" />
        </div>

        <div
            v-if="activeTab === 'people'"
            class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4"
        >
            <label class="flex min-w-[12rem] flex-col gap-1 text-sm">
                <span class="text-slate-600">Search name</span>
                <input
                    v-model="search"
                    class="rounded-md border border-slate-200 px-2 py-1"
                    placeholder="Display name"
                />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Our label</span>
                <select v-model="frequency" class="rounded-md border border-slate-200 px-2 py-1">
                    <option value="">All</option>
                    <option value="new">New (1 order)</option>
                    <option value="repeat">Repeat (2+)</option>
                    <option value="loyal">Loyal (3+)</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">RFM group</span>
                <select v-model="rfmGroup" class="rounded-md border border-slate-200 px-2 py-1">
                    <option value="">All</option>
                    <option v-for="group in catalog" :key="group.key" :value="group.key">
                        {{ group.name }}
                    </option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Store rhythm</span>
                <select v-model="cadence" class="rounded-md border border-slate-200 px-2 py-1">
                    <option value="">All</option>
                    <option value="on_cadence">On cadence</option>
                    <option value="due">Due</option>
                    <option value="lapsed">Lapsed</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Own pace</span>
                <select v-model="churn" class="rounded-md border border-slate-200 px-2 py-1">
                    <option value="">All</option>
                    <option value="churned">Churned (2× own gap)</option>
                    <option value="active">Active (2+)</option>
                </select>
            </label>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>

        <template v-if="activeTab === 'people'">
            <CustomerRetentionPeopleTable
                :rows="rows"
                :loading="loading"
                :currency="currency"
                :catalog="catalog"
                :store-cadence="summary?.store_cadence ?? null"
                :sort-by="sortBy"
                :sort-dir="sortDir"
                :expanded-id="expandedId"
                :expanded-orders="expandedOrders"
                :orders-loading="ordersLoading"
                @sort="toggleSort"
                @toggle-person="togglePerson"
            />

            <div class="flex items-center justify-between text-sm text-slate-600">
                <span>{{ meta.total }} people</span>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 px-2 py-1 disabled:opacity-40"
                        :disabled="page <= 1"
                        @click="page = page - 1"
                    >
                        Previous
                    </button>
                    <span>Page {{ meta.current_page }} of {{ lastPage }}</span>
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 px-2 py-1 disabled:opacity-40"
                        :disabled="page >= lastPage"
                        @click="page = page + 1"
                    >
                        Next
                    </button>
                </div>
            </div>
        </template>
    </section>
</template>
