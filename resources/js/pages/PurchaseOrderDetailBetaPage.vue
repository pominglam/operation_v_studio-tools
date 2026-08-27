<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import '../../css/po-beta.css';
import PoBetaActionsMenu from '../components/purchaseOrders/PoBetaActionsMenu.vue';
import PoBetaChrome from '../components/purchaseOrders/PoBetaChrome.vue';
import PoBetaOverviewTab from '../components/purchaseOrders/PoBetaOverviewTab.vue';
import PoSellingPriceHistoryPanel, {
    type PoSellingPriceHistoryEntry,
} from '../components/purchaseOrders/PoSellingPriceHistoryPanel.vue';
import PoWorkflowSetPricesDialog, {
    type PoSetPriceOverride,
    type PoSetPricePreview,
} from '../components/purchaseOrders/PoWorkflowSetPricesDialog.vue';
import { api } from '../lib/api';
import { formatPoMetaDate } from '../lib/datetime';
import { formatMoney2 } from '../lib/money';
import {
    filterPoBetaActions,
    parsePoBetaTab,
    poAttentionItems,
    poBetaClassicPath,
    formatCadDisplay,
    formatCountDisplay,
    poEstimatedLanded,
    poReceivingTotals,
    poShipmentLabel,
    poShippingAndSurcharge,
    poShortRef,
    poStatusLabel,
    poTitle,
    PO_WORKFLOW_STEP_KEYS,
    PO_WORKFLOW_STEP_LABELS,
    poWorkflowProgress,
    poWorkflowTimelineFrame,
    type PoBetaAction,
    type PoBetaSource,
    type PoBetaTab,
} from '../lib/purchaseOrderBeta';

const route = useRoute();
const router = useRouter();
const id = computed(() => String(route.params.id ?? ''));
const activeTab = computed(() => parsePoBetaTab(route.query.tab));

const loading = ref(true);
const error = ref<string | null>(null);
const po = ref<PoBetaSource | null>(null);
const actionsOpen = ref(false);
const actionQuery = ref('');
const history = ref<PoSellingPriceHistoryEntry[]>([]);
const historyError = ref<string | null>(null);
const setPricesOpen = ref(false);
const setPricesBusy = ref(false);
const setPricesLoading = ref(false);
const setPricesPreview = ref<PoSetPricePreview | null>(null);
const setPricesError = ref<string | null>(null);

const title = computed(() => (po.value ? poTitle(po.value) : 'Purchase order'));
const progress = computed(() => poWorkflowProgress(po.value?.workflow_checklist ?? null));
const isAtSetSellingPrice = computed(() => progress.value.currentKey === 'set_selling_price');
const workflowTabLabel = computed(() => {
    const frame = poWorkflowTimelineFrame(progress.value);
    return `${parseInt(frame.current.num, 10)}/${progress.value.total}`;
});
const receiving = computed(() => (po.value ? poReceivingTotals(po.value) : null));
const attention = computed(() => (po.value ? poAttentionItems(po.value) : []));
const extras = computed(() => (po.value ? poEstimatedLanded(po.value) : null));
const shippingAndSurcharge = computed(() => (po.value ? poShippingAndSurcharge(po.value) : null));
const visibleActions = computed(() => filterPoBetaActions(actionQuery.value));
const actionGroups = computed(() => {
    const groups: Array<{ name: PoBetaAction['group']; actions: PoBetaAction[] }> = [];
    for (const group of [
        'Documents',
        'Receiving',
        'Product & pricing',
        'Shopify & catalog',
    ] as const) {
        const actions = visibleActions.value.filter((action) => action.group === group);
        if (actions.length > 0) groups.push({ name: group, actions });
    }
    return groups;
});
const productsGridHref = computed(
    () => router.resolve({ name: 'products', query: { purchase_order_uuid: id.value } }).href,
);

function tabHref(tab: PoBetaTab): string {
    return router.resolve({
        name: 'purchase-order-detail-beta',
        params: { id: id.value },
        query: tab === 'overview' ? {} : { tab },
    }).href;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<{ data: PoBetaSource }>(`/api/v1/purchase-orders/${id.value}`);
        po.value = res.data.data;
        await Promise.all([loadHistory(), loadPricePreviewIfNeeded()]);
    } catch {
        error.value = 'Failed to load purchase order.';
    } finally {
        loading.value = false;
    }
}

async function loadPricePreviewIfNeeded(): Promise<void> {
    if (!po.value) {
        setPricesPreview.value = null;
        return;
    }

    const workflow = poWorkflowProgress(po.value.workflow_checklist);
    if (workflow.currentKey !== 'set_selling_price') {
        setPricesPreview.value = null;
        return;
    }

    try {
        const res = await api.get<{ data: PoSetPricePreview }>(
            `/api/v1/purchase-orders/${id.value}/workflow-actions/set-prices/preview`,
            { validateStatus: () => true },
        );
        setPricesPreview.value = res.status === 200 ? res.data.data : null;
    } catch {
        setPricesPreview.value = null;
    }
}

async function loadHistory(): Promise<void> {
    try {
        const res = await api.get<{ data: { entries: PoSellingPriceHistoryEntry[] } }>(
            `/api/v1/purchase-orders/${id.value}/selling-price-history`,
        );
        history.value = res.data.data.entries ?? [];
        historyError.value = null;
    } catch {
        history.value = [];
        historyError.value = 'Failed to load selling price history.';
    }
}

async function openSetPrices(): Promise<void> {
    actionsOpen.value = false;
    setPricesOpen.value = true;
    setPricesPreview.value = null;
    setPricesError.value = null;
    setPricesLoading.value = true;
    try {
        const res = await api.get<{ data: PoSetPricePreview }>(
            `/api/v1/purchase-orders/${id.value}/workflow-actions/set-prices/preview`,
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            throw new Error('Failed to load price preview.');
        }
        setPricesPreview.value = res.data.data;
    } catch (e: unknown) {
        setPricesError.value = e instanceof Error ? e.message : 'Failed to load price preview.';
    } finally {
        setPricesLoading.value = false;
    }
}

function closeSetPrices(): void {
    if (setPricesBusy.value) return;
    setPricesOpen.value = false;
}

async function applySetPrices(payload: { overrides: PoSetPriceOverride[] }): Promise<void> {
    setPricesBusy.value = true;
    try {
        const res = await api.post(
            `/api/v1/purchase-orders/${id.value}/workflow-actions/set-prices`,
            { overrides: payload.overrides },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            throw new Error('Failed to apply prices.');
        }
        setPricesOpen.value = false;
        await load();
    } catch (e: unknown) {
        setPricesError.value = e instanceof Error ? e.message : 'Failed to apply prices.';
    } finally {
        setPricesBusy.value = false;
    }
}

function runAction(action: PoBetaAction): void {
    if (action.id === 'set-prices') {
        void openSetPrices();
        return;
    }
    if (action.id === 'price-history') {
        actionsOpen.value = false;
        void router.push({
            name: 'purchase-order-detail-beta',
            params: { id: id.value },
            query: { tab: 'activity' },
        });
        return;
    }
    window.location.assign(`${poBetaClassicPath(id.value)}?from=beta`);
}

onMounted(() => {
    void load();
});
</script>

<template>
    <div class="po-beta" data-testid="po-beta-workspace">
        <PoBetaChrome :crumb="po ? poShortRef(po) : id.slice(0, 8)">
            <div v-if="loading" class="po-beta__quiet">Loading purchase order…</div>
            <p v-else-if="error" class="po-beta__error">{{ error }}</p>

            <template v-else-if="po">
                <div class="po-beta__workspace">
                <div class="po-beta__title-row">
                    <div>
                        <div class="po-beta__heading">
                            <h1 class="po-beta__title">{{ title }}</h1>
                            <span class="po-beta__status">{{ poStatusLabel(po.status) }}</span>
                        </div>
                        <p class="po-beta__meta" data-testid="po-beta-meta">
                            {{ poShortRef(po) }} · {{ poShipmentLabel(po.shipment_method) }} ·
                            Ordered {{ formatPoMetaDate(po.ordered_date) }} · Received
                            {{ formatPoMetaDate(po.received_date) }}
                        </p>
                    </div>
                    <div class="po-beta__actions">
                        <RouterLink
                            class="po-beta__btn po-beta__btn--ghost"
                            data-testid="po-beta-classic"
                            :to="poBetaClassicPath(id)"
                        >
                            Edit details
                        </RouterLink>
                        <a
                            class="po-beta__btn"
                            :href="productsGridHref"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            View products
                        </a>
                        <button
                            type="button"
                            class="po-beta__btn po-beta__btn--menu"
                            data-testid="po-beta-actions"
                            :aria-expanded="actionsOpen"
                            @click="actionsOpen = !actionsOpen"
                        >
                            Actions ∨
                        </button>
                        <button
                            v-if="isAtSetSellingPrice"
                            type="button"
                            class="po-beta__btn po-beta__btn--primary"
                            data-testid="po-beta-continue"
                            @click="openSetPrices"
                        >
                            Continue
                        </button>
                        <PoBetaActionsMenu
                            v-if="actionsOpen"
                            :query="actionQuery"
                            :groups="actionGroups"
                            @update:query="actionQuery = $event"
                            @run="runAction"
                        />
                    </div>
                </div>

                <div class="po-beta__summary" data-testid="po-beta-kpis">
                    <div>
                        <strong>{{ formatCountDisplay(receiving?.ordered ?? 0) }}</strong>
                        <span>Units</span>
                    </div>
                    <div>
                        <strong>{{ formatCountDisplay(po.items.length) }}</strong>
                        <span>Products</span>
                    </div>
                    <div>
                        <strong>{{ formatCadDisplay(po.product_total) }}</strong>
                        <span>Product</span>
                    </div>
                    <div>
                        <strong>{{ formatCadDisplay(shippingAndSurcharge) }}</strong>
                        <span>Shipping + surcharge</span>
                    </div>
                    <div class="po-beta__summary-total">
                        <strong>{{ formatCadDisplay(extras) }}</strong>
                        <span>Estimated landed</span>
                    </div>
                </div>

                <nav class="po-beta__tabs" aria-label="Purchase order workspace">
                    <RouterLink
                        :class="{ 'is-active': activeTab === 'overview' }"
                        :to="{ name: 'purchase-order-detail-beta', params: { id } }"
                        data-testid="po-beta-tab-overview"
                        >Overview</RouterLink
                    >
                    <RouterLink
                        :class="{ 'is-active': activeTab === 'workflow' }"
                        :to="{
                            name: 'purchase-order-detail-beta',
                            params: { id },
                            query: { tab: 'workflow' },
                        }"
                        data-testid="po-beta-tab-workflow"
                    >
                        Workflow {{ workflowTabLabel }}
                    </RouterLink>
                    <RouterLink
                        :class="{ 'is-active': activeTab === 'lines' }"
                        :to="{
                            name: 'purchase-order-detail-beta',
                            params: { id },
                            query: { tab: 'lines' },
                        }"
                        data-testid="po-beta-tab-lines"
                    >
                        Order lines {{ po.items.length }}
                    </RouterLink>
                    <RouterLink
                        :class="{ 'is-active': activeTab === 'receiving' }"
                        :to="{
                            name: 'purchase-order-detail-beta',
                            params: { id },
                            query: { tab: 'receiving' },
                        }"
                        data-testid="po-beta-tab-receiving"
                    >
                        Import &amp; receiving
                    </RouterLink>
                    <RouterLink
                        :class="{ 'is-active': activeTab === 'activity' }"
                        :to="{
                            name: 'purchase-order-detail-beta',
                            params: { id },
                            query: { tab: 'activity' },
                        }"
                        data-testid="po-beta-tab-activity"
                        >Activity</RouterLink
                    >
                </nav>

                <div class="po-beta__workspace-body">
                <PoBetaOverviewTab
                    v-if="activeTab === 'overview'"
                    :po="po"
                    :progress="progress"
                    :receiving="receiving"
                    :attention="attention"
                    :extras="extras"
                    :shipping-and-surcharge="shippingAndSurcharge"
                    :history="history"
                    :price-preview="setPricesPreview"
                    :tab-href="tabHref"
                    @continue="openSetPrices"
                />

                <section
                    v-else-if="activeTab === 'workflow'"
                    class="po-beta__section"
                    data-testid="po-beta-workflow"
                >
                    <p class="po-beta__label">Workflow</p>
                    <ol>
                        <li
                            v-for="(key, index) in PO_WORKFLOW_STEP_KEYS"
                            :key="key"
                            class="po-beta__attention"
                        >
                            <span>
                                {{ String(index + 1).padStart(2, '0') }} ·
                                {{ PO_WORKFLOW_STEP_LABELS[key] }}
                            </span>
                            <span class="po-beta__quiet">
                                {{
                                    po.workflow_checklist?.[key]
                                        ? 'Done'
                                        : index === progress.currentIndex
                                          ? 'Current'
                                          : 'Pending'
                                }}
                            </span>
                        </li>
                    </ol>
                </section>

                <section
                    v-else-if="activeTab === 'lines'"
                    class="po-beta__section"
                    data-testid="po-beta-lines"
                >
                    <p class="po-beta__label">Order lines</p>
                    <table class="po-beta__table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Ordered</th>
                                <th>Received</th>
                                <th>Landed</th>
                                <th>Sell</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in po.items" :key="item.sku">
                                <td>{{ item.sku }}</td>
                                <td>{{ item.product_name ?? '—' }}</td>
                                <td>{{ item.qty_ordered ?? '—' }}</td>
                                <td>{{ item.qty_received ?? '—' }}</td>
                                <td>{{ formatMoney2(item.latest_landed_unit_cost) }}</td>
                                <td>{{ formatMoney2(item.selling_price) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section
                    v-else-if="activeTab === 'receiving'"
                    class="po-beta__section"
                    data-testid="po-beta-receiving"
                >
                    <p class="po-beta__label">Import &amp; receiving</p>
                    <p v-if="receiving">
                        Ordered {{ receiving.ordered }} · Shipped {{ receiving.shipped }} · Received
                        {{ receiving.received }} · On shelves {{ receiving.onShelves }}
                    </p>
                    <p class="po-beta__quiet">
                        Import, inventory-check apply, and receiving writes stay on the classic page
                        until this workspace covers them.
                    </p>
                    <RouterLink class="po-beta__btn" :to="poBetaClassicPath(id)"
                        >Open classic receiving tools</RouterLink
                    >
                </section>

                <section v-else class="po-beta__section" data-testid="po-beta-activity">
                    <p class="po-beta__label">Activity</p>
                    <p v-if="historyError" class="po-beta__error">{{ historyError }}</p>
                    <PoSellingPriceHistoryPanel
                        v-else
                        :entries="history"
                        :loading="false"
                        :error="null"
                    />
                </section>
                </div>
                </div>
            </template>
        </PoBetaChrome>

        <PoWorkflowSetPricesDialog
            :open="setPricesOpen"
            :busy="setPricesBusy"
            :preview="setPricesPreview"
            :error="setPricesError"
            @cancel="closeSetPrices"
            @confirm="applySetPrices"
        />
    </div>
</template>
