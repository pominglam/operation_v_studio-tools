<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api } from '../lib/api';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import { clearPageState, loadPageState, savePageState } from '../lib/pageState';
import { formatLocalDateTime } from '../lib/datetime';
import {
    SPECIAL_ORDER_CUSTOMER_MESSAGE_PLACEHOLDERS,
    previewSpecialOrderCustomerMessage,
} from '../lib/specialOrderCustomerMessage';
import {
    DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD,
    DEFAULT_OPV_MARGIN_CAP_CAD,
    DEFAULT_SHIPPING_COST_AMOUNT,
    DEFAULT_SHIPPING_COST_CURRENCY,
    DEFAULT_SHIPPING_COST_PER_KG_CNY,
} from '../lib/specialOrderPricingCaps';

type DbBackupRow = {
    uuid: string;
    driver: string;
    filename: string;
    description: string;
    created_by: string;
    size_bytes: number | null;
    created_at: string | null;
};

const flushing = ref(false);
const resettingRun = ref(false);
const recrawlingSites = ref(false);
const forceRefreshingAll = ref(false);
const backfillingTypes = ref(false);
const recomputingTypes = ref(false);
const refreshingLatestCosts = ref(false);
const clearingStaleLatestArrival = ref(false);
const clearStaleLatestArrivalMessage = ref<string | null>(null);
const clearStaleLatestArrivalError = ref<string | null>(null);
const notesLoading = ref(false);
const notesSaving = ref(false);
const flushMessage = ref<string | null>(null);
const flushError = ref<string | null>(null);
const resetMessage = ref<string | null>(null);
const resetError = ref<string | null>(null);
const recrawlMessage = ref<string | null>(null);
const recrawlError = ref<string | null>(null);
const aliCookiesJson = ref<string>('');
const uploadingAliCookies = ref(false);
const aliCookiesMessage = ref<string | null>(null);
const aliCookiesError = ref<string | null>(null);
const forceRefreshMessage = ref<string | null>(null);
const forceRefreshError = ref<string | null>(null);
const typeBackfillMessage = ref<string | null>(null);
const typeBackfillError = ref<string | null>(null);
const typeRecomputeMessage = ref<string | null>(null);
const typeRecomputeError = ref<string | null>(null);
const refreshLatestCostsMessage = ref<string | null>(null);
const refreshLatestCostsError = ref<string | null>(null);
const refreshingPlamodInstock = ref(false);
const refreshPlamodInstockMessage = ref<string | null>(null);
const refreshPlamodInstockError = ref<string | null>(null);
const regeneratingMkFilterManifest = ref(false);
const regenerateMkFilterManifestMessage = ref<string | null>(null);
const regenerateMkFilterManifestError = ref<string | null>(null);
const notesMessage = ref<string | null>(null);
const notesError = ref<string | null>(null);
const notesBody = ref<string>('');
const specialOrderMessageTemplateLoading = ref(false);
const specialOrderMessageTemplateSaving = ref(false);
const specialOrderMessageTemplateBody = ref('');
const specialOrderMessageTemplateDefaultBody = ref('');
const specialOrderMessageTemplateMessage = ref<string | null>(null);
const specialOrderMessageTemplateError = ref<string | null>(null);
const specialOrderMessageTemplatePreview = computed(() =>
    previewSpecialOrderCustomerMessage(specialOrderMessageTemplateBody.value),
);
const specialOrderPricingCapsLoading = ref(false);
const specialOrderPricingCapsSaving = ref(false);
const specialOrderMerchandiserCommissionCapCad = ref(DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD);
const specialOrderOpvMarginCapCad = ref(DEFAULT_OPV_MARGIN_CAP_CAD);
const specialOrderDefaultMerchandiserCommissionCapCad = ref(
    DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD,
);
const specialOrderDefaultOpvMarginCapCad = ref(DEFAULT_OPV_MARGIN_CAP_CAD);
const specialOrderDefaultShippingCostAmountStored = ref(DEFAULT_SHIPPING_COST_AMOUNT);
const specialOrderDefaultShippingCostCurrencyStored = ref(DEFAULT_SHIPPING_COST_CURRENCY);
const specialOrderShippingCostAmount = ref(DEFAULT_SHIPPING_COST_AMOUNT);
const specialOrderShippingCostCurrency = ref(DEFAULT_SHIPPING_COST_CURRENCY);
const specialOrderShippingCostPerKgCny = ref(DEFAULT_SHIPPING_COST_PER_KG_CNY);
const specialOrderDefaultShippingCostPerKgCnyStored = ref(DEFAULT_SHIPPING_COST_PER_KG_CNY);
const specialOrderPricingCapsMessage = ref<string | null>(null);
const specialOrderPricingCapsError = ref<string | null>(null);
const specialOrderPricingCapsIsDefault = ref(true);
const opvCatalogPricingLoading = ref(false);
const opvCatalogPricingSaving = ref(false);
const opvCatalogPriceMultiplier = ref('1.50');
const opvCatalogDefaultDepositPercent = ref('20.00');
const opvCatalogDefaultPriceMultiplier = ref('1.50');
const opvCatalogDefaultDefaultDepositPercent = ref('20.00');
const opvCatalogPricingIsDefault = ref(true);
const opvCatalogPricingMessage = ref<string | null>(null);
const opvCatalogPricingError = ref<string | null>(null);
const opvCatalogPricingAtDefaults = computed(
    () =>
        opvCatalogPriceMultiplier.value === opvCatalogDefaultPriceMultiplier.value &&
        opvCatalogDefaultDepositPercent.value === opvCatalogDefaultDefaultDepositPercent.value,
);
const specialOrderPricingCapsAtDefaults = computed(
    () =>
        specialOrderMerchandiserCommissionCapCad.value ===
            specialOrderDefaultMerchandiserCommissionCapCad.value &&
        specialOrderOpvMarginCapCad.value === specialOrderDefaultOpvMarginCapCad.value &&
        specialOrderShippingCostAmount.value ===
            specialOrderDefaultShippingCostAmountStored.value &&
        specialOrderShippingCostCurrency.value ===
            specialOrderDefaultShippingCostCurrencyStored.value &&
        specialOrderShippingCostPerKgCny.value ===
            specialOrderDefaultShippingCostPerKgCnyStored.value,
);
const externalHitsLoading = ref(false);
const externalHitsSaving = ref(false);
const externalHitsPerMinute = ref<number>(10);
const externalHitsMessage = ref<string | null>(null);
const externalHitsError = ref<string | null>(null);
const externalAccessLoading = ref(false);
const externalAccessBusy = ref(false);
const externalAccessEnabled = ref(false);
const externalAccessPasswordConfigured = ref(false);
const externalAccessTunnel = ref<{
    running: boolean;
    tunnel_url: string | null;
    error: string | null;
    reachable?: boolean | null;
    reachable_http_status?: number | null;
    reachable_checked_at?: string | null;
    reachable_error?: string | null;
} | null>(null);
const externalAccessMessage = ref<string | null>(null);
const externalAccessError = ref<string | null>(null);

const canStartExternalAccessTunnel = computed(() => {
    if (externalAccessLoading.value || externalAccessBusy.value) return false;
    if (!externalAccessPasswordConfigured.value) return false;
    // Allow starting/updating tunnel even if already running (quick tunnel URLs can rotate).
    return true;
});
const availableSites = ref<Array<{ key: string; name: string }>>([]);
const siteKeys = ref<string[]>([]);
const recrawlStatus = ref<'any' | 'fresh' | 'expired'>('any');
const recrawlQuoteStatus = ref<'any' | 'error'>('any');
const productTypes = ref<string[]>([]);
const productVendors = ref<string[]>([]);
const selectedTypes = ref<string[]>([]);
const selectedVendors = ref<string[]>([]);

const STATE_KEY = 'page_state:maintenance';
const hydrating = ref(true);

const dbBackupsLoading = ref(false);
const dbBackups = ref<DbBackupRow[]>([]);
const dbBackupDescription = ref<string>('');
const creatingDbBackup = ref(false);
const dbBackupMessage = ref<string | null>(null);
const dbBackupError = ref<string | null>(null);

const selectedRestoreUuid = ref<string>('');
const restoringDb = ref(false);
const dbRestoreMessage = ref<string | null>(null);
const dbRestoreError = ref<string | null>(null);

const shopifySettingsLoading = ref(false);
const shopifySettingsSaving = ref(false);
const shopifyIntervalMinutes = ref(30);
type ShopifyTaskStatus = {
    key: string;
    label: string;
    status: string;
    queued: boolean;
    last_started_at: string | null;
    last_finished_at: string | null;
    duration_ms: number | null;
    records_fetched: number | null;
    records_updated: number | null;
    error_summary: string | null;
    counts_json: Record<string, unknown> | null;
};
type ShopifyHealthSnapshot = {
    order_reconcile_interval_minutes?: number;
    order_reconcile_interval_hours?: number;
    orders_last_success_at?: string | null;
    orders_high_water_updated_at?: string | null;
    orders_last_error?: string | null;
    next_order_reconcile_due_at?: string | null;
    last_webhook_received_at?: string | null;
    tasks?: ShopifyTaskStatus[];
};
const shopifyHealth = ref<ShopifyHealthSnapshot>({});
const shopifyMessage = ref<string | null>(null);
const shopifyError = ref<string | null>(null);
const shopifyActionBusy = ref(false);
const SHOPIFY_STATUS_POLL_INTERVAL_MS = 5_000;
let shopifyStatusPollTimer: ReturnType<typeof window.setInterval> | null = null;
let shopifyStatusRequestInFlight = false;

const shopifyTasks = computed(() => shopifyHealth.value.tasks ?? []);

const shopifyHasActiveWork = computed(() =>
    shopifyTasks.value.some((task) => task.status === 'queued' || task.status === 'running'),
);

function shopifyStatusLabel(status: string): string {
    switch (status) {
        case 'never':
            return 'Never run';
        case 'queued':
            return 'Queued';
        case 'running':
            return 'Running';
        case 'completed':
            return 'Completed';
        case 'failed':
            return 'Failed';
        default:
            return status;
    }
}

function shopifyStatusClass(status: string): string {
    switch (status) {
        case 'queued':
            return 'bg-amber-100 text-amber-900';
        case 'running':
            return 'bg-sky-100 text-sky-900';
        case 'completed':
            return 'bg-emerald-100 text-emerald-900';
        case 'failed':
            return 'bg-rose-100 text-rose-900';
        default:
            return 'bg-slate-100 text-slate-700';
    }
}

function shopifyTaskDetail(task: ShopifyTaskStatus): string | null {
    const parts: string[] = [];
    if (task.records_fetched != null && task.records_fetched > 0) {
        parts.push(`fetched ${task.records_fetched}`);
    }
    if (task.records_updated != null && task.records_updated > 0) {
        parts.push(`updated ${task.records_updated}`);
    }
    const counts = task.counts_json;
    if (counts && typeof counts === 'object') {
        if (typeof counts.shopify_day_rows === 'number') {
            parts.push(`${counts.shopify_day_rows} day rows`);
        }
        if (typeof counts.matched === 'number') {
            parts.push(`${counts.matched} SKUs matched`);
        }
    }
    return parts.length > 0 ? parts.join(', ') : null;
}

const siteOptions = computed<MultiSelectOption[]>(() => {
    return availableSites.value.map((s) => ({ value: s.key, label: s.name }));
});

const typeOptions = computed<MultiSelectOption[]>(() => {
    return productTypes.value.map((t) => ({ value: t, label: t }));
});

const vendorOptions = computed<MultiSelectOption[]>(() => {
    return productVendors.value.map((v) => ({ value: v, label: v }));
});

type ConfirmState =
    | {
          kind: 'flush_products';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'reset_run';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'force_refresh_all';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'backfill_product_types';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'recompute_product_types';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'refresh_latest_costs';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'restore_db';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'shopify_historical';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'shopify_rebuild_rollups';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'shopify_inventory_pull';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'clear_stale_latest_arrival';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'plamod_instock_refresh';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      };

const confirm = ref<ConfirmState | null>(null);

function requestFlush(): void {
    confirm.value = {
        kind: 'flush_products',
        title: 'Flush products table',
        message: 'This will delete ALL products from the database. Continue?',
        confirmText: 'Flush',
        variant: 'danger',
    };
}

function requestResetRun(): void {
    confirm.value = {
        kind: 'reset_run',
        title: 'Reset stuck price research run',
        message: 'Mark the current queued/running price research run as FAILED?',
        confirmText: 'Reset run',
        variant: 'danger',
    };
}

function requestForceRefreshAll(): void {
    confirm.value = {
        kind: 'force_refresh_all',
        title: 'Force refresh all price research',
        message:
            'This will recrawl ALL competitor sites for ALL products (even if currently fresh). Continue?',
        confirmText: 'Force refresh all',
        variant: 'danger',
    };
}

function requestBackfillProductTypes(): void {
    confirm.value = {
        kind: 'backfill_product_types',
        title: 'Backfill product types',
        message:
            'This will fill missing product types based on the product description (it will not overwrite existing types). Continue?',
        confirmText: 'Backfill',
        variant: 'primary',
    };
}

function requestRecomputeProductTypes(): void {
    confirm.value = {
        kind: 'recompute_product_types',
        title: 'Recompute product types',
        message:
            'This will recompute product types for ALL products based on the current mapping rules and may overwrite existing types. Continue?',
        confirmText: 'Recompute',
        variant: 'danger',
    };
}

function requestRefreshLatestCosts(): void {
    confirm.value = {
        kind: 'refresh_latest_costs',
        title: 'Refresh latest product costs',
        message:
            'This will recompute cached latest_unit_cost and latest_landed_unit_cost for ALL products from purchase order history. Continue?',
        confirmText: 'Refresh',
        variant: 'primary',
    };
}

function requestClearStaleLatestArrival(): void {
    confirm.value = {
        kind: 'clear_stale_latest_arrival',
        title: 'Clear stale latest arrival flags',
        message:
            'Remove the latest arrival flag locally and remove only the "latest arrival" tag on Shopify for products on POs older than 4 weeks, except products that also appear on a PO within the last 4 weeks (those keep the flag). Published on Shopify and other tags are not changed. Continue?',
        confirmText: 'Clear flags',
        variant: 'primary',
    };
}

function requestRefreshPlamodInstock(): void {
    confirm.value = {
        kind: 'plamod_instock_refresh',
        title: 'Refresh PLAMOD in-stock catalog',
        message:
            'Queue a Playwright export of Bandai Hobby Plastic Model Kits (In-Stock tab) into the restock snapshot. Continue?',
        confirmText: 'Refresh',
        variant: 'primary',
    };
}

function requestRestoreDb(): void {
    if (!selectedRestoreUuid.value) return;
    const b = dbBackups.value.find((x) => x.uuid === selectedRestoreUuid.value) ?? null;
    const label = b
        ? `${b.filename} — ${b.description || 'No description'}`
        : selectedRestoreUuid.value;
    confirm.value = {
        kind: 'restore_db',
        title: 'Restore database from backup',
        message: `This will overwrite the database using:\n${label}\n\nContinue?`,
        confirmText: 'Restore',
        variant: 'danger',
    };
}

async function confirmAction(): Promise<void> {
    const current = confirm.value;
    if (!current) return;

    if (current.kind === 'flush_products') {
        await flush();
        return;
    }

    if (current.kind === 'reset_run') {
        await resetPriceResearchRun();
        return;
    }

    if (current.kind === 'backfill_product_types') {
        await backfillProductTypes();
        return;
    }

    if (current.kind === 'recompute_product_types') {
        await recomputeProductTypes();
        return;
    }

    if (current.kind === 'refresh_latest_costs') {
        await refreshLatestCosts();
        return;
    }

    if (current.kind === 'clear_stale_latest_arrival') {
        await clearStaleLatestArrival();
        return;
    }

    if (current.kind === 'plamod_instock_refresh') {
        await refreshPlamodInstockCatalog();
        return;
    }

    if (current.kind === 'restore_db') {
        await restoreDb();
        return;
    }

    if (current.kind === 'shopify_historical') {
        await runShopifyHistoricalBackfill();
        return;
    }

    if (current.kind === 'shopify_rebuild_rollups') {
        await runShopifyRebuildRollups();
        return;
    }

    if (current.kind === 'shopify_inventory_pull') {
        await runShopifyInventoryPull();
        return;
    }

    await forceRefreshAll();
}

async function clearStaleLatestArrival(): Promise<void> {
    clearingStaleLatestArrival.value = true;
    clearStaleLatestArrivalMessage.value = null;
    clearStaleLatestArrivalError.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: {
                purchase_orders_matched: number;
                products_cleared: number;
                cutoff_date: string;
                shopify_tags_removed: number;
                shopify_skipped_no_gid: number;
                shopify_tag_removals_failed: number;
            };
        }>('/api/v1/maintenance/clear-stale-latest-arrival');
        const d = res.data.data;
        let msg = `Cleared latest arrival on ${d.products_cleared} product(s) from ${d.purchase_orders_matched} PO(s) older than ${d.cutoff_date}.`;
        if (d.shopify_tags_removed > 0) {
            msg += ` Removed the latest arrival tag on ${d.shopify_tags_removed} Shopify product(s).`;
        }
        if (d.shopify_skipped_no_gid > 0) {
            msg += ` ${d.shopify_skipped_no_gid} had no Shopify mirror (tag not pushed).`;
        }
        if (d.shopify_tag_removals_failed > 0) {
            msg += ` ${d.shopify_tag_removals_failed} Shopify tag removal(s) failed.`;
        }
        clearStaleLatestArrivalMessage.value = msg;
    } catch {
        clearStaleLatestArrivalError.value = 'Failed to clear stale latest arrival flags.';
    } finally {
        clearingStaleLatestArrival.value = false;
        confirm.value = null;
    }
}

async function refreshLatestCosts(): Promise<void> {
    refreshingLatestCosts.value = true;
    refreshLatestCostsMessage.value = null;
    refreshLatestCostsError.value = null;

    try {
        const res = await api.post<{ matched: number; updated: number }>(
            '/api/v1/maintenance/refresh-latest-costs',
        );
        refreshLatestCostsMessage.value = `Refreshed latest costs. Matched ${res.data.matched}, updated ${res.data.updated}.`;
    } catch {
        refreshLatestCostsError.value = 'Failed to refresh latest costs.';
    } finally {
        refreshingLatestCosts.value = false;
        confirm.value = null;
    }
}

async function refreshPlamodInstockCatalog(): Promise<void> {
    refreshingPlamodInstock.value = true;
    refreshPlamodInstockMessage.value = null;
    refreshPlamodInstockError.value = null;

    try {
        const res = await api.post<{ data: { ok: boolean; sync_log_id: number | null } }>(
            '/api/v1/plamod/restock/sync',
        );
        refreshPlamodInstockMessage.value = `Queued PLAMOD in-stock refresh (log #${res.data.data.sync_log_id ?? '—'}).`;
    } catch {
        refreshPlamodInstockError.value = 'Failed to queue PLAMOD in-stock refresh.';
    } finally {
        refreshingPlamodInstock.value = false;
        confirm.value = null;
    }
}

async function regenerateModelKitFilterManifest(): Promise<void> {
    regeneratingMkFilterManifest.value = true;
    regenerateMkFilterManifestMessage.value = null;
    regenerateMkFilterManifestError.value = null;

    try {
        const res = await api.post<{
            handle_count: number;
            duration_ms: number;
            theme_root: string;
            written_paths: string[];
        }>('/api/v1/maintenance/model-kit-collection-filter-manifest');
        regenerateMkFilterManifestMessage.value = `Regenerated ${res.data.handle_count} collection handle(s) in ${res.data.duration_ms} ms. Theme: ${res.data.theme_root}. Push theme files to AI Dev when ready.`;
    } catch (error: unknown) {
        const message =
            typeof error === 'object' &&
            error !== null &&
            'response' in error &&
            typeof (error as { response?: { data?: { message?: string } } }).response?.data
                ?.message === 'string'
                ? (error as { response: { data: { message: string } } }).response.data.message
                : null;
        regenerateMkFilterManifestError.value =
            message ??
            'Failed to regenerate model-kit filter manifest. Check OVS_SHOPIFY_THEME_PATH is set.';
    } finally {
        regeneratingMkFilterManifest.value = false;
    }
}

function cancelConfirm(): void {
    confirm.value = null;
}

async function flush(): Promise<void> {
    flushing.value = true;
    flushMessage.value = null;
    flushError.value = null;

    try {
        await api.delete('/api/v1/products');
        flushMessage.value = 'All products flushed.';
    } catch (e: unknown) {
        flushError.value = 'Failed to flush products.';
    } finally {
        flushing.value = false;
        confirm.value = null;
    }
}

async function resetPriceResearchRun(): Promise<void> {
    resettingRun.value = true;
    resetMessage.value = null;
    resetError.value = null;

    try {
        const res = await api.post<{ message: string }>(
            '/api/v1/price-research/runs/reset',
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            resetError.value = res.data?.message ?? 'Failed to reset run.';
            return;
        }

        resetMessage.value = 'Price research run reset.';
    } catch (e: unknown) {
        resetError.value = 'Failed to reset run.';
    } finally {
        resettingRun.value = false;
        confirm.value = null;
    }
}

async function loadPriceResearchSites(): Promise<void> {
    try {
        const r = await fetch('/api/v1/price-research/filter-options');
        if (!r.ok) return;
        const json = (await r.json()) as {
            data?: { sites?: Array<{ key: string; name: string }> };
        };
        availableSites.value = json.data?.sites ?? [];
    } catch {
        // ignore
    }
}

async function loadProductFilterOptions(): Promise<void> {
    try {
        const r = await fetch('/api/v1/products/filter-options');
        if (!r.ok) return;
        const json = (await r.json()) as { data?: { types?: string[]; vendors?: string[] } };
        productTypes.value = (json.data?.types ?? []).filter(
            (t) => typeof t === 'string' && t.trim() !== '',
        );
        productVendors.value = (json.data?.vendors ?? []).filter(
            (v) => typeof v === 'string' && v.trim() !== '',
        );
    } catch {
        // ignore
    }
}

async function recrawlSelectedSites(): Promise<void> {
    if (siteKeys.value.length === 0) return;
    recrawlingSites.value = true;
    recrawlMessage.value = null;
    recrawlError.value = null;

    try {
        const res = await api.post(
            '/api/v1/price-research/run',
            {
                force: true,
                site_keys: siteKeys.value,
                status: recrawlStatus.value,
                quote_status: recrawlQuoteStatus.value,
                types: selectedTypes.value,
                vendors: selectedVendors.value,
            },
            { validateStatus: () => true },
        );

        if (res.status !== 202 && res.status !== 200) {
            recrawlError.value = 'Failed to start site recrawl.';
            return;
        }

        recrawlMessage.value = `Queued site recrawl for: ${siteKeys.value.join(', ')}.`;
    } catch {
        recrawlError.value = 'Failed to start site recrawl.';
    } finally {
        recrawlingSites.value = false;
    }
}

async function uploadAliExpressCookies(): Promise<void> {
    aliCookiesMessage.value = null;
    aliCookiesError.value = null;

    const raw = aliCookiesJson.value.trim();
    if (!raw) {
        aliCookiesError.value = 'Paste cookies JSON first.';
        return;
    }

    let cookies: unknown;
    try {
        cookies = JSON.parse(raw);
    } catch {
        aliCookiesError.value = 'Invalid JSON.';
        return;
    }

    if (!Array.isArray(cookies)) {
        aliCookiesError.value = 'Cookies JSON must be an array.';
        return;
    }

    uploadingAliCookies.value = true;
    try {
        const res = await api.post(
            '/api/v1/price-research/aliexpress/cookies',
            { cookies },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            aliCookiesError.value = res.data?.message ?? 'Failed to upload cookies.';
            return;
        }

        aliCookiesMessage.value = `AliExpress cookies uploaded (${res.data?.count ?? cookies.length}).`;
    } catch {
        aliCookiesError.value = 'Failed to upload cookies.';
    } finally {
        uploadingAliCookies.value = false;
    }
}

async function forceRefreshAll(): Promise<void> {
    forceRefreshingAll.value = true;
    forceRefreshMessage.value = null;
    forceRefreshError.value = null;

    try {
        const res = await api.post(
            '/api/v1/price-research/run',
            { force: true },
            { validateStatus: () => true },
        );

        if (res.status !== 202 && res.status !== 200) {
            forceRefreshError.value = 'Failed to start force refresh.';
            return;
        }

        forceRefreshMessage.value = 'Queued force refresh all price research.';
    } catch {
        forceRefreshError.value = 'Failed to start force refresh.';
    } finally {
        forceRefreshingAll.value = false;
        confirm.value = null;
    }
}

async function backfillProductTypes(): Promise<void> {
    backfillingTypes.value = true;
    typeBackfillMessage.value = null;
    typeBackfillError.value = null;

    try {
        const res = await api.post<{ updated: number }>(
            '/api/v1/products/backfill-types',
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            typeBackfillError.value = 'Failed to backfill product types.';
            return;
        }

        typeBackfillMessage.value = `Updated ${res.data.updated} product(s).`;
    } catch {
        typeBackfillError.value = 'Failed to backfill product types.';
    } finally {
        backfillingTypes.value = false;
        confirm.value = null;
    }
}

async function recomputeProductTypes(): Promise<void> {
    recomputingTypes.value = true;
    typeRecomputeMessage.value = null;
    typeRecomputeError.value = null;

    try {
        const res = await api.post<{ updated: number }>(
            '/api/v1/products/recompute-types',
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            typeRecomputeError.value = 'Failed to recompute product types.';
            return;
        }

        typeRecomputeMessage.value = `Updated ${res.data.updated} product(s).`;
    } catch {
        typeRecomputeError.value = 'Failed to recompute product types.';
    } finally {
        recomputingTypes.value = false;
        confirm.value = null;
    }
}

async function loadMaintenanceNotes(): Promise<void> {
    notesLoading.value = true;
    notesError.value = null;
    try {
        const res = await api.get<{ data?: { body?: string | null } }>(
            '/api/v1/maintenance/notes',
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            notesError.value = 'Failed to load notes.';
            return;
        }
        notesBody.value = res.data?.data?.body ?? '';
    } catch {
        notesError.value = 'Failed to load notes.';
    } finally {
        notesLoading.value = false;
    }
}

async function loadShopifySettings(silent = false): Promise<void> {
    if (shopifyStatusRequestInFlight) return;

    shopifyStatusRequestInFlight = true;
    if (!silent) {
        shopifySettingsLoading.value = true;
        shopifyError.value = null;
    }

    try {
        const res = await api.get<{ data: ShopifyHealthSnapshot }>('/api/v1/shopify/settings');
        shopifyHealth.value = res.data.data;
        const minutes = Number(res.data.data.order_reconcile_interval_minutes);
        if (!silent) {
            shopifyIntervalMinutes.value = Number.isFinite(minutes) && minutes >= 15 ? minutes : 30;
        }
        shopifyError.value = null;
    } catch {
        if (!silent) {
            shopifyError.value = 'Failed to load Shopify settings.';
        }
    } finally {
        shopifyStatusRequestInFlight = false;
        if (!silent) {
            shopifySettingsLoading.value = false;
        }
    }
}

async function refreshShopifyStatus(): Promise<void> {
    await loadShopifySettings();
}

function startShopifyStatusPolling(): void {
    if (shopifyStatusPollTimer !== null) return;

    shopifyStatusPollTimer = window.setInterval(() => {
        if (document.visibilityState === 'hidden') return;
        void loadShopifySettings(true);
    }, SHOPIFY_STATUS_POLL_INTERVAL_MS);
}

function stopShopifyStatusPolling(): void {
    if (shopifyStatusPollTimer === null) return;

    window.clearInterval(shopifyStatusPollTimer);
    shopifyStatusPollTimer = null;
}

async function saveShopifyInterval(): Promise<void> {
    shopifySettingsSaving.value = true;
    shopifyError.value = null;
    shopifyMessage.value = null;
    try {
        const res = await api.put<{ data: ShopifyHealthSnapshot }>('/api/v1/shopify/settings', {
            order_reconcile_interval_minutes: shopifyIntervalMinutes.value,
        });
        shopifyHealth.value = res.data.data;
        shopifyMessage.value = 'Shopify settings saved.';
    } catch {
        shopifyError.value = 'Failed to save Shopify settings.';
    } finally {
        shopifySettingsSaving.value = false;
    }
}

function requestShopifyHistorical(): void {
    confirm.value = {
        kind: 'shopify_historical',
        title: 'Repull all historical orders',
        message:
            'Queue a full Shopify order backfill? This may take a long time and consume API quota.',
        confirmText: 'Queue backfill',
        variant: 'danger',
    };
}

function requestShopifyRebuildRollups(): void {
    confirm.value = {
        kind: 'shopify_rebuild_rollups',
        title: 'Rebuild demand rollups',
        message: 'Recompute all demand rollups from stored order lines and inventory movements?',
        confirmText: 'Rebuild',
        variant: 'primary',
    };
}

function requestShopifyInventoryPull(): void {
    confirm.value = {
        kind: 'shopify_inventory_pull',
        title: 'Pull Shopify inventory',
        message:
            'Sync inventory from Shopify and overwrite products.available_qty for matched SKUs?',
        confirmText: 'Pull inventory',
        variant: 'danger',
    };
}

async function runShopifyHistoricalBackfill(): Promise<void> {
    shopifyActionBusy.value = true;
    shopifyMessage.value = null;
    shopifyError.value = null;
    try {
        await api.post('/api/v1/shopify/orders/historical-backfill');
        shopifyMessage.value = 'Historical order backfill queued.';
        await loadShopifySettings();
    } catch {
        shopifyError.value = 'Failed to queue historical backfill.';
    } finally {
        shopifyActionBusy.value = false;
        confirm.value = null;
    }
}

async function runShopifyRebuildRollups(): Promise<void> {
    shopifyActionBusy.value = true;
    shopifyMessage.value = null;
    shopifyError.value = null;
    try {
        await api.post('/api/v1/shopify/demand/rebuild-rollups');
        shopifyMessage.value = 'Demand rollup rebuild queued.';
        await loadShopifySettings();
    } catch {
        shopifyError.value = 'Failed to queue rollup rebuild.';
    } finally {
        shopifyActionBusy.value = false;
        confirm.value = null;
    }
}

async function runShopifyInventoryPull(): Promise<void> {
    shopifyActionBusy.value = true;
    shopifyMessage.value = null;
    shopifyError.value = null;
    try {
        await api.post('/api/v1/shopify/inventory/pull-to-products');
        shopifyMessage.value = 'Shopify inventory pull queued.';
        await loadShopifySettings();
    } catch {
        shopifyError.value = 'Failed to queue inventory pull.';
    } finally {
        shopifyActionBusy.value = false;
        confirm.value = null;
    }
}

async function loadExternalRateLimit(): Promise<void> {
    externalHitsLoading.value = true;
    externalHitsError.value = null;
    try {
        const res = await api.get<{ data: { hits_per_minute: number } }>(
            '/api/v1/maintenance/external-rate-limit',
        );
        const v = Number(res.data.data.hits_per_minute);
        externalHitsPerMinute.value = Number.isFinite(v) && v > 0 ? v : 10;
    } catch {
        externalHitsError.value = 'Failed to load external crawl rate limit.';
    } finally {
        externalHitsLoading.value = false;
    }
}

async function loadExternalAccess(): Promise<void> {
    externalAccessLoading.value = true;
    externalAccessError.value = null;
    try {
        const res = await api.get<{
            data: {
                enabled: boolean;
                password_configured: boolean;
                tunnel: any;
            };
        }>('/api/v1/maintenance/external-access');

        externalAccessEnabled.value = !!res.data.data.enabled;
        externalAccessPasswordConfigured.value = !!res.data.data.password_configured;
        externalAccessTunnel.value = res.data.data.tunnel ?? null;
    } catch {
        externalAccessError.value = 'Failed to load external access status.';
    } finally {
        externalAccessLoading.value = false;
    }
}

async function setExternalAccessEnabled(enabled: boolean): Promise<void> {
    externalAccessBusy.value = true;
    externalAccessError.value = null;
    externalAccessMessage.value = null;
    try {
        const res = await api.put<{ data: { enabled: boolean; tunnel: any } }>(
            '/api/v1/maintenance/external-access',
            { enabled },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const err = (res.data as any)?.error;
            externalAccessError.value =
                typeof err === 'string' && err.trim() !== ''
                    ? err
                    : 'Failed to update external access.';
            return;
        }
        externalAccessEnabled.value = !!res.data.data.enabled;
        externalAccessTunnel.value = res.data.data.tunnel ?? null;
        if (enabled && !externalAccessTunnel.value?.tunnel_url) {
            externalAccessMessage.value =
                'External access enabled. Tunnel URL may take a few seconds — click Refresh.';
        } else {
            externalAccessMessage.value = enabled
                ? 'External access enabled.'
                : 'External access disabled.';
        }
    } catch {
        // This action can take a bit (cloudflared startup / URL propagation).
        // If the request times out, refresh status and show a best-effort message.
        try {
            await loadExternalAccess();
            if (externalAccessEnabled.value === enabled) {
                externalAccessError.value = null;
                externalAccessMessage.value = enabled
                    ? 'External access updated. Tunnel URL may take a few seconds — click Refresh.'
                    : 'External access disabled.';
            } else {
                externalAccessError.value = 'Failed to update external access.';
            }
        } catch {
            externalAccessError.value = 'Failed to update external access.';
        }
    } finally {
        externalAccessBusy.value = false;
    }
}

async function saveExternalRateLimit(): Promise<void> {
    externalHitsSaving.value = true;
    externalHitsError.value = null;
    externalHitsMessage.value = null;
    try {
        const res = await api.put<{ data: { hits_per_minute: number } }>(
            '/api/v1/maintenance/external-rate-limit',
            {
                hits_per_minute: externalHitsPerMinute.value,
            },
        );
        const v = Number(res.data.data.hits_per_minute);
        externalHitsPerMinute.value = Number.isFinite(v) && v > 0 ? v : externalHitsPerMinute.value;
        externalHitsMessage.value = 'Saved.';
    } catch {
        externalHitsError.value = 'Failed to save external crawl rate limit.';
    } finally {
        externalHitsSaving.value = false;
    }
}

async function saveMaintenanceNotes(): Promise<void> {
    notesSaving.value = true;
    notesMessage.value = null;
    notesError.value = null;

    try {
        const res = await api.put<{ data?: { body?: string | null } }>(
            '/api/v1/maintenance/notes',
            { body: notesBody.value.trim() === '' ? null : notesBody.value },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            notesError.value = 'Failed to save notes.';
            return;
        }

        notesBody.value = res.data?.data?.body ?? '';
        notesMessage.value = 'Notes saved.';
    } catch {
        notesError.value = 'Failed to save notes.';
    } finally {
        notesSaving.value = false;
    }
}

async function loadSpecialOrderMessageTemplate(): Promise<void> {
    specialOrderMessageTemplateLoading.value = true;
    specialOrderMessageTemplateError.value = null;
    try {
        const res = await api.get<{
            data: { body: string; default_body: string };
        }>('/api/v1/maintenance/special-order-customer-message-template', {
            validateStatus: () => true,
        });
        if (res.status !== 200) {
            specialOrderMessageTemplateError.value =
                'Failed to load special order message template.';
            return;
        }
        specialOrderMessageTemplateBody.value = res.data.data.body ?? '';
        specialOrderMessageTemplateDefaultBody.value = res.data.data.default_body ?? '';
    } catch {
        specialOrderMessageTemplateError.value = 'Failed to load special order message template.';
    } finally {
        specialOrderMessageTemplateLoading.value = false;
    }
}

async function saveSpecialOrderMessageTemplate(): Promise<void> {
    specialOrderMessageTemplateSaving.value = true;
    specialOrderMessageTemplateMessage.value = null;
    specialOrderMessageTemplateError.value = null;

    try {
        const res = await api.put<{ data: { body: string } }>(
            '/api/v1/maintenance/special-order-customer-message-template',
            { body: specialOrderMessageTemplateBody.value },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const anyData = res.data as { message?: string; errors?: { body?: string[] } };
            specialOrderMessageTemplateError.value =
                anyData?.errors?.body?.[0] ??
                anyData?.message ??
                'Failed to save special order message template.';
            return;
        }

        specialOrderMessageTemplateBody.value = res.data.data.body;
        specialOrderMessageTemplateMessage.value = 'Template saved.';
    } catch {
        specialOrderMessageTemplateError.value = 'Failed to save special order message template.';
    } finally {
        specialOrderMessageTemplateSaving.value = false;
    }
}

async function resetSpecialOrderMessageTemplate(): Promise<void> {
    specialOrderMessageTemplateSaving.value = true;
    specialOrderMessageTemplateMessage.value = null;
    specialOrderMessageTemplateError.value = null;

    try {
        const res = await api.put<{ data: { body: string } }>(
            '/api/v1/maintenance/special-order-customer-message-template',
            { reset: true },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            specialOrderMessageTemplateError.value = 'Failed to reset template.';
            return;
        }

        specialOrderMessageTemplateBody.value = res.data.data.body;
        specialOrderMessageTemplateMessage.value = 'Template reset to default.';
    } catch {
        specialOrderMessageTemplateError.value = 'Failed to reset template.';
    } finally {
        specialOrderMessageTemplateSaving.value = false;
    }
}

async function applyOpvCatalogPricing(data: {
    price_multiplier: string;
    default_deposit_percent: string;
    default_price_multiplier?: string;
    default_default_deposit_percent?: string;
    is_default?: boolean;
}): Promise<void> {
    opvCatalogPriceMultiplier.value = data.price_multiplier;
    opvCatalogDefaultDepositPercent.value = data.default_deposit_percent;
    if (data.default_price_multiplier) {
        opvCatalogDefaultPriceMultiplier.value = data.default_price_multiplier;
    }
    if (data.default_default_deposit_percent) {
        opvCatalogDefaultDefaultDepositPercent.value = data.default_default_deposit_percent;
    }
    opvCatalogPricingIsDefault.value = data.is_default ?? true;
}

async function loadOpvCatalogPricing(): Promise<void> {
    opvCatalogPricingLoading.value = true;
    opvCatalogPricingError.value = null;
    try {
        const res = await api.get<{
            data: {
                price_multiplier: string;
                default_deposit_percent: string;
                default_price_multiplier: string;
                default_default_deposit_percent: string;
                is_default: boolean;
            };
        }>('/api/v1/maintenance/opv-catalog-pricing', { validateStatus: () => true });
        if (res.status !== 200) {
            opvCatalogPricingError.value = 'Failed to load OPV catalog margin.';
            return;
        }
        await applyOpvCatalogPricing(res.data.data);
    } catch {
        opvCatalogPricingError.value = 'Failed to load OPV catalog margin.';
    } finally {
        opvCatalogPricingLoading.value = false;
    }
}

async function saveOpvCatalogPricing(): Promise<void> {
    opvCatalogPricingSaving.value = true;
    opvCatalogPricingMessage.value = null;
    opvCatalogPricingError.value = null;
    try {
        const res = await api.put<{
            data: {
                price_multiplier: string;
                default_deposit_percent: string;
                is_default: boolean;
            };
        }>(
            '/api/v1/maintenance/opv-catalog-pricing',
            {
                price_multiplier: opvCatalogPriceMultiplier.value,
                default_deposit_percent: opvCatalogDefaultDepositPercent.value,
            },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const anyData = res.data as { message?: string; errors?: Record<string, string[]> };
            opvCatalogPricingError.value =
                anyData?.errors?.price_multiplier?.[0] ??
                anyData?.message ??
                'Failed to save OPV catalog margin.';
            return;
        }
        await applyOpvCatalogPricing(res.data.data);
        opvCatalogPricingMessage.value = 'OPV catalog margin saved.';
    } catch {
        opvCatalogPricingError.value = 'Failed to save OPV catalog margin.';
    } finally {
        opvCatalogPricingSaving.value = false;
    }
}

async function resetOpvCatalogPricing(): Promise<void> {
    opvCatalogPricingSaving.value = true;
    opvCatalogPricingMessage.value = null;
    opvCatalogPricingError.value = null;
    try {
        const res = await api.put<{
            data: {
                price_multiplier: string;
                default_deposit_percent: string;
                is_default: boolean;
            };
        }>(
            '/api/v1/maintenance/opv-catalog-pricing',
            { reset: true },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            opvCatalogPricingError.value = 'Failed to reset OPV catalog margin.';
            return;
        }
        await applyOpvCatalogPricing(res.data.data);
        opvCatalogPricingMessage.value = 'OPV catalog margin reset to defaults.';
    } catch {
        opvCatalogPricingError.value = 'Failed to reset OPV catalog margin.';
    } finally {
        opvCatalogPricingSaving.value = false;
    }
}

async function loadSpecialOrderPricingCaps(): Promise<void> {
    specialOrderPricingCapsLoading.value = true;
    specialOrderPricingCapsError.value = null;
    try {
        const res = await api.get<{
            data: {
                merchandiser_commission_cap_cad: string;
                opv_margin_cap_cad: string;
                default_shipping_cost_amount: string;
                default_shipping_cost_currency: string;
                default_shipping_cost_per_kg_cny: string;
                default_merchandiser_commission_cap_cad: string;
                default_opv_margin_cap_cad: string;
                default_default_shipping_cost_amount: string;
                default_default_shipping_cost_currency: string;
                default_default_shipping_cost_per_kg_cny: string;
                is_default: boolean;
            };
        }>('/api/v1/maintenance/special-order-pricing-caps', {
            validateStatus: () => true,
        });
        if (res.status !== 200) {
            specialOrderPricingCapsError.value = 'Failed to load special order pricing caps.';
            return;
        }
        specialOrderMerchandiserCommissionCapCad.value =
            res.data.data.merchandiser_commission_cap_cad ??
            DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD;
        specialOrderOpvMarginCapCad.value =
            res.data.data.opv_margin_cap_cad ?? DEFAULT_OPV_MARGIN_CAP_CAD;
        specialOrderDefaultMerchandiserCommissionCapCad.value =
            res.data.data.default_merchandiser_commission_cap_cad ??
            DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD;
        specialOrderDefaultOpvMarginCapCad.value =
            res.data.data.default_opv_margin_cap_cad ?? DEFAULT_OPV_MARGIN_CAP_CAD;
        specialOrderShippingCostAmount.value =
            res.data.data.default_shipping_cost_amount ?? DEFAULT_SHIPPING_COST_AMOUNT;
        specialOrderShippingCostCurrency.value =
            res.data.data.default_shipping_cost_currency ?? DEFAULT_SHIPPING_COST_CURRENCY;
        specialOrderDefaultShippingCostAmountStored.value =
            res.data.data.default_default_shipping_cost_amount ?? DEFAULT_SHIPPING_COST_AMOUNT;
        specialOrderDefaultShippingCostCurrencyStored.value =
            res.data.data.default_default_shipping_cost_currency ?? DEFAULT_SHIPPING_COST_CURRENCY;
        specialOrderShippingCostPerKgCny.value =
            res.data.data.default_shipping_cost_per_kg_cny ?? DEFAULT_SHIPPING_COST_PER_KG_CNY;
        specialOrderDefaultShippingCostPerKgCnyStored.value =
            res.data.data.default_default_shipping_cost_per_kg_cny ??
            DEFAULT_SHIPPING_COST_PER_KG_CNY;
        specialOrderPricingCapsIsDefault.value = res.data.data.is_default ?? true;
    } catch {
        specialOrderPricingCapsError.value = 'Failed to load special order pricing caps.';
    } finally {
        specialOrderPricingCapsLoading.value = false;
    }
}

async function saveSpecialOrderPricingCaps(): Promise<void> {
    specialOrderPricingCapsSaving.value = true;
    specialOrderPricingCapsMessage.value = null;
    specialOrderPricingCapsError.value = null;

    try {
        const res = await api.put<{
            data: {
                merchandiser_commission_cap_cad: string;
                opv_margin_cap_cad: string;
                default_shipping_cost_amount: string;
                default_shipping_cost_currency: string;
                default_shipping_cost_per_kg_cny: string;
                is_default: boolean;
            };
        }>(
            '/api/v1/maintenance/special-order-pricing-caps',
            {
                merchandiser_commission_cap_cad: specialOrderMerchandiserCommissionCapCad.value,
                opv_margin_cap_cad: specialOrderOpvMarginCapCad.value,
                default_shipping_cost_amount: specialOrderShippingCostAmount.value,
                default_shipping_cost_currency: specialOrderShippingCostCurrency.value,
                default_shipping_cost_per_kg_cny: specialOrderShippingCostPerKgCny.value,
            },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const anyData = res.data as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            specialOrderPricingCapsError.value =
                anyData?.errors?.merchandiser_commission_cap_cad?.[0] ??
                anyData?.errors?.opv_margin_cap_cad?.[0] ??
                anyData?.message ??
                'Failed to save special order pricing caps.';
            return;
        }

        specialOrderMerchandiserCommissionCapCad.value =
            res.data.data.merchandiser_commission_cap_cad;
        specialOrderOpvMarginCapCad.value = res.data.data.opv_margin_cap_cad;
        specialOrderShippingCostAmount.value = res.data.data.default_shipping_cost_amount;
        specialOrderShippingCostCurrency.value = res.data.data.default_shipping_cost_currency;
        specialOrderShippingCostPerKgCny.value = res.data.data.default_shipping_cost_per_kg_cny;
        specialOrderPricingCapsIsDefault.value = res.data.data.is_default;
        specialOrderPricingCapsMessage.value = 'Pricing caps saved.';
    } catch {
        specialOrderPricingCapsError.value = 'Failed to save special order pricing caps.';
    } finally {
        specialOrderPricingCapsSaving.value = false;
    }
}

async function resetSpecialOrderPricingCaps(): Promise<void> {
    specialOrderPricingCapsSaving.value = true;
    specialOrderPricingCapsMessage.value = null;
    specialOrderPricingCapsError.value = null;

    try {
        const res = await api.put<{
            data: {
                merchandiser_commission_cap_cad: string;
                opv_margin_cap_cad: string;
                is_default: boolean;
            };
        }>(
            '/api/v1/maintenance/special-order-pricing-caps',
            { reset: true },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            specialOrderPricingCapsError.value = 'Failed to reset pricing caps.';
            return;
        }

        specialOrderMerchandiserCommissionCapCad.value =
            res.data.data.merchandiser_commission_cap_cad;
        specialOrderOpvMarginCapCad.value = res.data.data.opv_margin_cap_cad;
        specialOrderShippingCostAmount.value = res.data.data.default_shipping_cost_amount;
        specialOrderShippingCostCurrency.value = res.data.data.default_shipping_cost_currency;
        specialOrderShippingCostPerKgCny.value = res.data.data.default_shipping_cost_per_kg_cny;
        specialOrderPricingCapsIsDefault.value = res.data.data.is_default;
        specialOrderPricingCapsMessage.value = 'Pricing caps reset to defaults.';
    } catch {
        specialOrderPricingCapsError.value = 'Failed to reset pricing caps.';
    } finally {
        specialOrderPricingCapsSaving.value = false;
    }
}

async function loadDbBackups(): Promise<void> {
    dbBackupsLoading.value = true;
    dbBackupError.value = null;
    try {
        const res = await api.get<{ data: DbBackupRow[] }>('/api/v1/maintenance/db-backups', {
            params: { limit: 200 },
            validateStatus: () => true,
        });
        if (res.status !== 200) {
            dbBackupError.value = 'Failed to load database backups.';
            return;
        }
        dbBackups.value = res.data.data ?? [];
        if (!selectedRestoreUuid.value && dbBackups.value.length > 0) {
            selectedRestoreUuid.value = dbBackups.value[0].uuid;
        }
    } catch {
        dbBackupError.value = 'Failed to load database backups.';
    } finally {
        dbBackupsLoading.value = false;
    }
}

async function createDbBackup(): Promise<void> {
    creatingDbBackup.value = true;
    dbBackupMessage.value = null;
    dbBackupError.value = null;
    try {
        const res = await api.post(
            '/api/v1/maintenance/db-backups',
            { description: dbBackupDescription.value },
            { validateStatus: () => true, timeout: 0 },
        );
        if (res.status !== 201) {
            const anyData = res.data as any;
            const msgRaw: unknown = anyData?.message ?? anyData?.error ?? anyData?.errors;
            let details = '';
            if (typeof msgRaw === 'string') details = msgRaw.trim();
            else if (msgRaw !== null && msgRaw !== undefined) {
                try {
                    details = JSON.stringify(msgRaw);
                } catch {
                    details = String(msgRaw);
                }
            }
            dbBackupError.value = `Failed to create DB + images backup (HTTP ${res.status}).${details ? ` ${details}` : ''}`;
            return;
        }
        dbBackupMessage.value = 'Backup created (DB + images).';
        dbBackupDescription.value = '';
        await loadDbBackups();
    } catch (e: unknown) {
        const anyErr = e as any;
        const msg = typeof anyErr?.message === 'string' ? anyErr.message.trim() : '';
        dbBackupError.value =
            msg !== ''
                ? `Failed to create DB + images backup. ${msg}`
                : 'Failed to create DB + images backup.';
    } finally {
        creatingDbBackup.value = false;
    }
}

async function restoreDb(): Promise<void> {
    restoringDb.value = true;
    dbRestoreMessage.value = null;
    dbRestoreError.value = null;
    try {
        const res = await api.post(
            '/api/v1/maintenance/db-backups/restore',
            { backup_uuid: selectedRestoreUuid.value },
            { validateStatus: () => true, timeout: 0 },
        );
        if (res.status !== 200) {
            const anyData = res.data as any;
            const msgRaw: unknown = anyData?.message ?? anyData?.error ?? anyData?.errors;
            let details = '';
            if (typeof msgRaw === 'string') details = msgRaw.trim();
            else if (msgRaw !== null && msgRaw !== undefined) {
                try {
                    details = JSON.stringify(msgRaw);
                } catch {
                    details = String(msgRaw);
                }
            }
            dbRestoreError.value = `Failed to restore DB + images (HTTP ${res.status}).${details ? ` ${details}` : ''}`;
            return;
        }
        dbRestoreMessage.value =
            'Restore started/completed (DB + images). Refresh the page if things look stale.';
        confirm.value = null;
    } catch {
        dbRestoreError.value = 'Failed to restore DB + images.';
    } finally {
        restoringDb.value = false;
    }
}

function resetRecrawlState(): void {
    clearPageState(STATE_KEY);
    siteKeys.value = [];
    recrawlStatus.value = 'any';
    recrawlQuoteStatus.value = 'any';
    selectedTypes.value = [];
    selectedVendors.value = [];
}

onMounted(() => {
    const saved = loadPageState<{
        siteKeys?: string[];
        recrawlStatus?: 'any' | 'fresh' | 'expired';
        recrawlQuoteStatus?: 'any' | 'error';
        selectedTypes?: string[];
        selectedVendors?: string[];
        aliCookiesJson?: string;
    }>(STATE_KEY);

    if (saved) {
        if (Array.isArray(saved.siteKeys)) siteKeys.value = saved.siteKeys;
        if (saved.recrawlStatus) recrawlStatus.value = saved.recrawlStatus;
        if (saved.recrawlQuoteStatus) recrawlQuoteStatus.value = saved.recrawlQuoteStatus;
        if (Array.isArray(saved.selectedTypes)) selectedTypes.value = saved.selectedTypes;
        if (Array.isArray(saved.selectedVendors)) selectedVendors.value = saved.selectedVendors;
        if (typeof saved.aliCookiesJson === 'string') aliCookiesJson.value = saved.aliCookiesJson;
    }

    hydrating.value = false;

    void loadPriceResearchSites();
    void loadProductFilterOptions();
    void loadMaintenanceNotes();
    void loadSpecialOrderMessageTemplate();
    void loadSpecialOrderPricingCaps();
    void loadOpvCatalogPricing();
    void loadDbBackups();
    void loadExternalRateLimit();
    void loadExternalAccess();
    void loadShopifySettings();
    startShopifyStatusPolling();
});

onBeforeUnmount(() => {
    stopShopifyStatusPolling();
});

watch(
    [siteKeys, recrawlStatus, recrawlQuoteStatus, selectedTypes, selectedVendors, aliCookiesJson],
    () => {
        if (hydrating.value) return;
        savePageState(STATE_KEY, {
            siteKeys: siteKeys.value,
            recrawlStatus: recrawlStatus.value,
            recrawlQuoteStatus: recrawlQuoteStatus.value,
            selectedTypes: selectedTypes.value,
            selectedVendors: selectedVendors.value,
            aliCookiesJson: aliCookiesJson.value,
        });
    },
    { deep: true },
);
</script>

<template>
    <section class="space-y-4">
        <div>
            <h1 class="text-xl font-semibold">Maintenance</h1>
            <p class="mt-1 text-sm text-slate-600">
                Admin utilities for maintaining imported data.
            </p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">Shopify sync & demand</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Order webhooks + scheduled reconcile, demand rollups, and inventory pull.
                        <RouterLink to="/shopify/webhooks" class="ml-1 underline"
                            >Webhook logs</RouterLink
                        >
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        class="inline-flex items-center justify-center rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                        type="button"
                        :disabled="shopifySettingsLoading"
                        @click="refreshShopifyStatus"
                    >
                        {{ shopifySettingsLoading ? 'Refreshing…' : 'Refresh status' }}
                    </button>
                    <button
                        class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
                        type="button"
                        :disabled="shopifySettingsSaving || shopifySettingsLoading"
                        @click="saveShopifyInterval"
                    >
                        {{ shopifySettingsSaving ? 'Saving…' : 'Save interval' }}
                    </button>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-end gap-4">
                <label class="text-sm">
                    <span class="text-slate-600">Order reconcile interval (minutes)</span>
                    <input
                        v-model.number="shopifyIntervalMinutes"
                        class="mt-1 block w-28 rounded-md border border-slate-200 px-2 py-1"
                        type="number"
                        min="15"
                        max="10080"
                    />
                    <span class="mt-1 block text-xs text-slate-500">
                        Incremental pull of new/updated orders. 30 minutes is light for this store.
                    </span>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-md border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-50"
                    :disabled="shopifyActionBusy"
                    @click="requestShopifyHistorical"
                >
                    Repull historical orders
                </button>
                <button
                    type="button"
                    class="rounded-md border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-50"
                    :disabled="shopifyActionBusy"
                    @click="requestShopifyRebuildRollups"
                >
                    Rebuild demand rollups
                </button>
                <button
                    type="button"
                    class="rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm text-rose-900 hover:bg-rose-100 disabled:opacity-50"
                    :disabled="shopifyActionBusy"
                    @click="requestShopifyInventoryPull"
                >
                    Pull Shopify inventory → available qty
                </button>
            </div>

            <div
                v-if="shopifyError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ shopifyError }}
            </div>
            <div
                v-if="shopifyMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ shopifyMessage }}
            </div>

            <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                        Sync status
                    </div>
                    <div class="text-xs text-sky-700">
                        {{ shopifyHasActiveWork ? 'Work in progress' : 'Status current' }} —
                        auto-updates every 5 seconds
                    </div>
                </div>

                <dl class="mt-2 grid gap-2 text-xs text-slate-600 sm:grid-cols-2">
                    <div>
                        <dt class="font-medium text-slate-700">Last order sync</dt>
                        <dd class="font-mono">
                            {{ formatLocalDateTime(shopifyHealth.orders_last_success_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-700">Next reconcile due</dt>
                        <dd class="font-mono">
                            {{ formatLocalDateTime(shopifyHealth.next_order_reconcile_due_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-700">Last webhook</dt>
                        <dd class="font-mono">
                            {{ formatLocalDateTime(shopifyHealth.last_webhook_received_at) }}
                        </dd>
                    </div>
                    <div v-if="shopifyHealth.orders_last_error">
                        <dt class="font-medium text-rose-700">Last order error</dt>
                        <dd class="text-rose-800">{{ shopifyHealth.orders_last_error }}</dd>
                    </div>
                </dl>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-600">
                                <th class="py-1 pr-3 font-medium">Task</th>
                                <th class="py-1 pr-3 font-medium">Status</th>
                                <th class="py-1 pr-3 font-medium">Last finished</th>
                                <th class="py-1 font-medium">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="task in shopifyTasks"
                                :key="task.key"
                                class="border-b border-slate-100"
                            >
                                <td class="py-2 pr-3 text-slate-900">{{ task.label }}</td>
                                <td class="py-2 pr-3">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 font-medium"
                                        :class="shopifyStatusClass(task.status)"
                                    >
                                        {{ shopifyStatusLabel(task.status) }}
                                    </span>
                                </td>
                                <td class="py-2 pr-3 font-mono text-slate-600">
                                    {{
                                        formatLocalDateTime(
                                            task.last_finished_at ?? task.last_started_at,
                                        )
                                    }}
                                </td>
                                <td class="py-2 text-slate-600">
                                    <span v-if="shopifyTaskDetail(task)">{{
                                        shopifyTaskDetail(task)
                                    }}</span>
                                    <span v-else-if="task.error_summary" class="text-rose-700">{{
                                        task.error_summary
                                    }}</span>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">
                        Model kit collection filter manifest
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        Regenerates handle → profile maps and theme snippets from
                        <span class="font-mono text-xs">ModelKitShelfCatalog</span> (84 shelves).
                        Usually completes in under one second. Requires
                        <span class="font-mono text-xs">OVS_SHOPIFY_THEME_PATH</span> pointing at
                        the sibling theme checkout. Does not push to Shopify — run theme push
                        separately after reviewing git diff.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    data-testid="maintenance-regenerate-mk-filter-manifest"
                    :disabled="regeneratingMkFilterManifest"
                    @click="regenerateModelKitFilterManifest"
                >
                    {{
                        regeneratingMkFilterManifest
                            ? 'Regenerating…'
                            : 'Regenerate filter manifest'
                    }}
                </button>
            </div>

            <div
                v-if="regenerateMkFilterManifestError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ regenerateMkFilterManifestError }}
            </div>

            <div
                v-if="regenerateMkFilterManifestMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ regenerateMkFilterManifestMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">External crawl rate limit</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Global throttle applied to external crawls (Bandai, HLJ, competitor sites).
                        Approx hits per minute.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="externalHitsSaving || externalHitsLoading"
                    @click="saveExternalRateLimit"
                >
                    {{ externalHitsSaving ? 'Saving…' : 'Save' }}
                </button>
            </div>

            <div
                v-if="externalHitsError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ externalHitsError }}
            </div>
            <div
                v-if="externalHitsMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ externalHitsMessage }}
            </div>

            <div class="mt-3 max-w-sm">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Hits per minute</label
                >
                <input
                    v-model.number="externalHitsPerMinute"
                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                    type="number"
                    min="1"
                    max="120"
                />
                <div class="mt-1 text-xs text-slate-500">Recommended: 10–20.</div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">External access</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Expose the full app through a Cloudflare quick tunnel (<span
                            class="font-mono text-xs"
                            >trycloudflare.com</span
                        >) protected by a simple password. Local access is unaffected.
                    </div>
                    <div class="mt-2 text-xs text-amber-800">
                        Warning: this is not a full auth system. Use only for temporary access.
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="externalAccessLoading || externalAccessBusy"
                        @click="loadExternalAccess"
                    >
                        {{ externalAccessLoading ? 'Refreshing…' : 'Refresh' }}
                    </button>
                    <button
                        class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="
                            externalAccessLoading ||
                            externalAccessBusy ||
                            !canStartExternalAccessTunnel
                        "
                        @click="setExternalAccessEnabled(true)"
                    >
                        {{
                            externalAccessBusy
                                ? 'Working…'
                                : externalAccessEnabled
                                  ? 'Start / Update tunnel'
                                  : 'Enable external access'
                        }}
                    </button>
                    <button
                        class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="
                            externalAccessLoading || externalAccessBusy || !externalAccessEnabled
                        "
                        @click="setExternalAccessEnabled(false)"
                    >
                        Disable
                    </button>
                </div>
            </div>

            <div
                v-if="!externalAccessPasswordConfigured"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                Missing <span class="font-mono text-xs">EXTERNAL_ACCESS_PASSWORD</span> in
                <span class="font-mono text-xs">.env</span>. Configure it to enable external access.
            </div>

            <div
                v-if="externalAccessError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ externalAccessError }}
            </div>
            <div
                v-if="externalAccessMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ externalAccessMessage }}
            </div>

            <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                    <div class="text-slate-700">
                        Status:
                        <span class="font-semibold text-slate-900">{{
                            externalAccessEnabled ? 'Enabled' : 'Disabled'
                        }}</span>
                        <span v-if="externalAccessTunnel?.running" class="text-emerald-700">
                            · Tunnel running</span
                        >
                        <span v-else class="text-slate-600"> · Tunnel stopped</span>
                        <span v-if="externalAccessTunnel?.error" class="text-rose-700">
                            · {{ externalAccessTunnel.error }}</span
                        >
                    </div>

                    <div v-if="externalAccessTunnel?.tunnel_url" class="text-slate-700">
                        URL:
                        <a
                            class="font-mono text-xs text-slate-900 underline"
                            :href="externalAccessTunnel.tunnel_url"
                            target="_blank"
                        >
                            {{ externalAccessTunnel.tunnel_url }}
                        </a>
                    </div>
                </div>

                <div v-if="externalAccessTunnel?.tunnel_url" class="mt-1 text-xs text-slate-600">
                    Quick tunnel URLs rotate; if you use an older URL it may show
                    <span class="font-mono">404</span>. Always use the URL shown here.
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">
                        Custom Asia order — customer message template
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        DM template for special orders. Placeholders:
                        <span
                            v-for="placeholder in SPECIAL_ORDER_CUSTOMER_MESSAGE_PLACEHOLDERS"
                            :key="placeholder"
                            class="ml-1 font-mono text-xs text-slate-700"
                            >{{ placeholder }}</span
                        >
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        class="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="
                            specialOrderMessageTemplateSaving ||
                            specialOrderMessageTemplateLoading ||
                            specialOrderMessageTemplateBody ===
                                specialOrderMessageTemplateDefaultBody
                        "
                        @click="resetSpecialOrderMessageTemplate"
                    >
                        Reset to default
                    </button>
                    <button
                        class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="
                            specialOrderMessageTemplateSaving || specialOrderMessageTemplateLoading
                        "
                        @click="saveSpecialOrderMessageTemplate"
                    >
                        {{ specialOrderMessageTemplateSaving ? 'Saving…' : 'Save template' }}
                    </button>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div>
                    <textarea
                        v-model="specialOrderMessageTemplateBody"
                        class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 font-mono text-sm text-slate-900"
                        :disabled="specialOrderMessageTemplateLoading"
                        rows="14"
                    />
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                        Preview (sample order)
                    </div>
                    <pre
                        class="mt-2 max-h-[320px] overflow-auto rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm whitespace-pre-wrap text-slate-800"
                        >{{ specialOrderMessageTemplatePreview }}</pre>
                </div>
            </div>

            <div
                v-if="specialOrderMessageTemplateError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ specialOrderMessageTemplateError }}
            </div>

            <div
                v-if="specialOrderMessageTemplateMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ specialOrderMessageTemplateMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">OPV catalog margin</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Multiplier on Plamod PO cost for pick-list and store preorder suggested
                        prices. Same catalog rule as PO set-prices: closest X.99 (ties go up).
                        Default deposit when opening a store preorder.
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        class="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="
                            opvCatalogPricingSaving ||
                            opvCatalogPricingLoading ||
                            opvCatalogPricingAtDefaults
                        "
                        @click="resetOpvCatalogPricing"
                    >
                        Reset to defaults
                    </button>
                    <button
                        class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="opvCatalogPricingSaving || opvCatalogPricingLoading"
                        data-testid="maintenance-opv-catalog-pricing-save"
                        @click="saveOpvCatalogPricing"
                    >
                        {{ opvCatalogPricingSaving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
            <div class="mt-4 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block text-sm text-slate-700">
                    <span class="font-medium text-slate-900">Price multiplier (× cost)</span>
                    <input
                        v-model="opvCatalogPriceMultiplier"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                        :disabled="opvCatalogPricingLoading"
                        inputmode="decimal"
                        type="text"
                        data-testid="maintenance-opv-price-multiplier"
                    />
                    <span class="mt-1 block text-xs text-slate-500">
                        Default: {{ opvCatalogDefaultPriceMultiplier }}
                    </span>
                </label>
                <label class="block text-sm text-slate-700">
                    <span class="font-medium text-slate-900">Default deposit %</span>
                    <input
                        v-model="opvCatalogDefaultDepositPercent"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                        :disabled="opvCatalogPricingLoading"
                        inputmode="decimal"
                        type="text"
                        data-testid="maintenance-opv-default-deposit"
                    />
                    <span class="mt-1 block text-xs text-slate-500">
                        Default: {{ opvCatalogDefaultDefaultDepositPercent }}
                    </span>
                </label>
            </div>
            <div
                v-if="opvCatalogPricingError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ opvCatalogPricingError }}
            </div>
            <div
                v-if="opvCatalogPricingMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ opvCatalogPricingMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">
                        Custom Asia order — pricing caps
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        Maximum CAD amounts for formula-derived commission and OPV margin, plus
                        default merchandiser shipping on new special orders.
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        class="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="
                            specialOrderPricingCapsSaving ||
                            specialOrderPricingCapsLoading ||
                            specialOrderPricingCapsAtDefaults
                        "
                        @click="resetSpecialOrderPricingCaps"
                    >
                        Reset to defaults
                    </button>
                    <button
                        class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="specialOrderPricingCapsSaving || specialOrderPricingCapsLoading"
                        @click="saveSpecialOrderPricingCaps"
                    >
                        {{ specialOrderPricingCapsSaving ? 'Saving…' : 'Save caps' }}
                    </button>
                </div>
            </div>

            <div class="mt-4 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block text-sm text-slate-700">
                    <span class="font-medium text-slate-900"
                        >Merchandiser commission cap (CAD)</span
                    >
                    <input
                        v-model="specialOrderMerchandiserCommissionCapCad"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                        :disabled="specialOrderPricingCapsLoading"
                        inputmode="decimal"
                        type="text"
                    />
                    <span class="mt-1 block text-xs text-slate-500">
                        Default: {{ specialOrderDefaultMerchandiserCommissionCapCad }}
                    </span>
                </label>
                <label class="block text-sm text-slate-700">
                    <span class="font-medium text-slate-900">OPV margin cap (CAD)</span>
                    <input
                        v-model="specialOrderOpvMarginCapCad"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                        :disabled="specialOrderPricingCapsLoading"
                        inputmode="decimal"
                        type="text"
                    />
                    <span class="mt-1 block text-xs text-slate-500">
                        Default: {{ specialOrderDefaultOpvMarginCapCad }}
                    </span>
                </label>
                <label class="block text-sm text-slate-700 sm:col-span-2">
                    <span class="font-medium text-slate-900">Default shipping (new orders)</span>
                    <div class="mt-1 flex max-w-xs gap-2">
                        <input
                            v-model="specialOrderShippingCostAmount"
                            class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                            :disabled="specialOrderPricingCapsLoading"
                            inputmode="decimal"
                            type="text"
                        />
                        <select
                            v-model="specialOrderShippingCostCurrency"
                            class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                            :disabled="specialOrderPricingCapsLoading"
                        >
                            <option value="CNY">RMB</option>
                            <option value="CAD">CAD</option>
                            <option value="HKD">HKD</option>
                            <option value="JPY">JPY</option>
                        </select>
                    </div>
                    <span class="mt-1 block text-xs text-slate-500">
                        Default: {{ specialOrderDefaultShippingCostAmountStored }}
                        {{
                            specialOrderDefaultShippingCostCurrencyStored === 'CNY'
                                ? 'RMB'
                                : specialOrderDefaultShippingCostCurrencyStored
                        }}
                    </span>
                </label>
                <label class="block text-sm text-slate-700 sm:col-span-2">
                    <span class="font-medium text-slate-900">Shipping rate (RMB/kg)</span>
                    <input
                        v-model="specialOrderShippingCostPerKgCny"
                        class="mt-1 w-full max-w-xs rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                        :disabled="specialOrderPricingCapsLoading"
                        inputmode="decimal"
                        type="text"
                    />
                    <span class="mt-1 block text-xs text-slate-500">
                        Default: {{ specialOrderDefaultShippingCostPerKgCnyStored }} RMB/kg — used
                        when shipping is entered by weight on special orders.
                    </span>
                </label>
            </div>

            <div v-if="!specialOrderPricingCapsIsDefault" class="mt-3 text-xs text-slate-600">
                Custom caps are in effect (stored in maintenance notes).
            </div>

            <div
                v-if="specialOrderPricingCapsError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ specialOrderPricingCapsError }}
            </div>

            <div
                v-if="specialOrderPricingCapsMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ specialOrderPricingCapsMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">Maintenance notes</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Freeform notes for the team (persisted in the database).
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="notesSaving || notesLoading"
                    @click="saveMaintenanceNotes"
                >
                    {{ notesSaving ? 'Saving…' : 'Save notes' }}
                </button>
            </div>

            <div class="mt-3">
                <textarea
                    v-model="notesBody"
                    class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                    :disabled="notesLoading"
                    rows="6"
                    placeholder="Add notes here…"
                />
            </div>

            <div
                v-if="notesError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ notesError }}
            </div>

            <div
                v-if="notesMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ notesMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">Database backups</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Create a backup with a description, or restore from an existing backup.
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="text-sm font-semibold text-slate-900">Create backup</div>
                    <div class="mt-2">
                        <label
                            class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >
                            Description
                        </label>
                        <textarea
                            v-model="dbBackupDescription"
                            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                            rows="3"
                            placeholder="Why are you taking this backup?"
                            :disabled="creatingDbBackup"
                        />
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button
                            class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                            type="button"
                            :disabled="creatingDbBackup"
                            @click="createDbBackup"
                        >
                            {{ creatingDbBackup ? 'Creating…' : 'Backup DB + images' }}
                        </button>
                    </div>
                    <div
                        v-if="dbBackupError"
                        class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                    >
                        {{ dbBackupError }}
                    </div>
                    <div
                        v-if="dbBackupMessage"
                        class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                    >
                        {{ dbBackupMessage }}
                    </div>
                </div>

                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="text-sm font-semibold text-slate-900">Restore backup</div>
                    <div class="mt-2">
                        <label
                            class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >
                            Select backup
                        </label>
                        <select
                            v-model="selectedRestoreUuid"
                            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            :disabled="restoringDb || dbBackupsLoading"
                        >
                            <option value="" disabled>Select…</option>
                            <option v-for="b in dbBackups" :key="b.uuid" :value="b.uuid">
                                {{ b.created_at ?? '' }} —
                                {{ b.description || 'No description' }} ({{ b.filename }})
                            </option>
                        </select>
                        <div class="mt-2 text-xs text-slate-600">
                            Loaded
                            <span class="font-semibold text-slate-900">{{ dbBackups.length }}</span>
                            backups.
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button
                            class="inline-flex items-center justify-center rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                            type="button"
                            :disabled="restoringDb || !selectedRestoreUuid"
                            @click="requestRestoreDb"
                        >
                            {{ restoringDb ? 'Restoring…' : 'Restore DB + images' }}
                        </button>
                    </div>
                    <div
                        v-if="dbRestoreError"
                        class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                    >
                        {{ dbRestoreError }}
                    </div>
                    <div
                        v-if="dbRestoreMessage"
                        class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                    >
                        {{ dbRestoreMessage }}
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">Flush products table</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Deletes all products currently stored.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="flushing"
                    @click="requestFlush"
                >
                    {{ flushing ? 'Flushing…' : 'Flush' }}
                </button>
            </div>

            <div
                v-if="flushError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ flushError }}
            </div>

            <div
                v-if="flushMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ flushMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">
                        Refresh latest product costs
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        Recompute cached latest unit and landed costs for all products based on
                        purchase orders.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="refreshingLatestCosts"
                    @click="requestRefreshLatestCosts"
                >
                    {{ refreshingLatestCosts ? 'Refreshing…' : 'Refresh costs' }}
                </button>
            </div>

            <div
                v-if="refreshLatestCostsError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ refreshLatestCostsError }}
            </div>

            <div
                v-if="refreshLatestCostsMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ refreshLatestCostsMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">
                        Refresh PLAMOD in-stock catalog
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        Export Bandai Hobby Plastic Model Kits (In-Stock) for the
                        <RouterLink
                            to="/restocking/plamod"
                            class="font-medium text-blue-700 hover:underline"
                        >
                            restock proposal
                        </RouterLink>
                        page.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="refreshingPlamodInstock"
                    data-testid="maintenance-plamod-instock-refresh"
                    @click="requestRefreshPlamodInstock"
                >
                    {{ refreshingPlamodInstock ? 'Queueing…' : 'Refresh PLAMOD in-stock' }}
                </button>
            </div>

            <div
                v-if="refreshPlamodInstockError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ refreshPlamodInstockError }}
            </div>

            <div
                v-if="refreshPlamodInstockMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ refreshPlamodInstockMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">
                        Clear stale latest arrival flags
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        Step 1 before marking a new PO: remove latest arrival from products on POs
                        older than 4 weeks (by received date, or created date if not set). Does not
                        change published on Shopify.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    data-testid="maintenance-clear-stale-latest-arrival"
                    :disabled="clearingStaleLatestArrival"
                    @click="requestClearStaleLatestArrival"
                >
                    {{ clearingStaleLatestArrival ? 'Clearing…' : 'Clear stale latest arrival' }}
                </button>
            </div>

            <div
                v-if="clearStaleLatestArrivalError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ clearStaleLatestArrivalError }}
            </div>

            <div
                v-if="clearStaleLatestArrivalMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ clearStaleLatestArrivalMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">Backfill product types</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Fill missing product types based on the product description (does not
                        overwrite existing types).
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="backfillingTypes"
                    @click="requestBackfillProductTypes"
                >
                    {{ backfillingTypes ? 'Backfilling…' : 'Backfill types' }}
                </button>
            </div>

            <div class="mt-3 flex justify-end">
                <button
                    class="inline-flex items-center justify-center rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="recomputingTypes"
                    @click="requestRecomputeProductTypes"
                >
                    {{ recomputingTypes ? 'Recomputing…' : 'Recompute all types' }}
                </button>
            </div>

            <div
                v-if="typeBackfillError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ typeBackfillError }}
            </div>

            <div
                v-if="typeBackfillMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ typeBackfillMessage }}
            </div>

            <div
                v-if="typeRecomputeError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ typeRecomputeError }}
            </div>

            <div
                v-if="typeRecomputeMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ typeRecomputeMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">Recrawl prices by site</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Force recrawl only the selected competitor site(s) across matching products.
                    </div>
                    <div class="mt-3 flex flex-col gap-3 md:flex-row md:flex-wrap">
                        <MultiSelectFilter
                            v-model="siteKeys"
                            label="Sites"
                            :options="siteOptions"
                            placeholder="Select site(s)…"
                        />

                        <div class="min-w-[180px] flex-[1_1_220px]">
                            <label
                                class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                                >Status</label
                            >
                            <select
                                v-model="recrawlStatus"
                                class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            >
                                <option value="any">All</option>
                                <option value="fresh">Fresh</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>

                        <div class="min-w-[180px] flex-[1_1_220px]">
                            <label
                                class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                                >Result</label
                            >
                            <select
                                v-model="recrawlQuoteStatus"
                                class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            >
                                <option value="any">All</option>
                                <option value="error">Error</option>
                            </select>
                        </div>

                        <MultiSelectFilter
                            v-model="selectedTypes"
                            label="Type"
                            :options="typeOptions"
                            placeholder="All types"
                        />

                        <MultiSelectFilter
                            v-model="selectedVendors"
                            label="Vendor"
                            :options="vendorOptions"
                            placeholder="All vendors"
                        />
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="recrawlingSites"
                        @click="resetRecrawlState"
                    >
                        Reset
                    </button>
                    <button
                        class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        type="button"
                        :disabled="recrawlingSites || siteKeys.length === 0"
                        @click="recrawlSelectedSites"
                    >
                        {{ recrawlingSites ? 'Starting…' : 'Start recrawl' }}
                    </button>
                </div>
            </div>

            <div
                v-if="recrawlError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ recrawlError }}
            </div>

            <div
                v-if="recrawlMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ recrawlMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">
                        AliExpress cookies (optional)
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        AliExpress blocks automated browsing. Paste your browser cookies JSON here
                        to allow the scraper to use your session.
                    </div>
                    <textarea
                        v-model="aliCookiesJson"
                        class="mt-3 h-32 w-full rounded-md border border-slate-200 bg-white p-3 text-xs text-slate-900"
                        placeholder='[{"name":"...","value":"...","domain":".aliexpress.com","path":"/", ...}]'
                    />
                </div>
                <button
                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="uploadingAliCookies || aliCookiesJson.trim() === ''"
                    @click="uploadAliExpressCookies"
                >
                    {{ uploadingAliCookies ? 'Uploading…' : 'Upload cookies' }}
                </button>
            </div>

            <div
                v-if="aliCookiesError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ aliCookiesError }}
            </div>

            <div
                v-if="aliCookiesMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ aliCookiesMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">
                        Force refresh all price research
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        Recrawl all competitor sites for all products (ignores freshness).
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="forceRefreshingAll"
                    @click="requestForceRefreshAll"
                >
                    {{ forceRefreshingAll ? 'Starting…' : 'Force refresh all' }}
                </button>
            </div>

            <div
                v-if="forceRefreshError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ forceRefreshError }}
            </div>

            <div
                v-if="forceRefreshMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ forceRefreshMessage }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">
                        Reset stuck price research run
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        If the UI says the latest run is queued/running forever, you can mark it as
                        failed to unblock the dashboard.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="resettingRun"
                    @click="requestResetRun"
                >
                    {{ resettingRun ? 'Resetting…' : 'Reset run' }}
                </button>
            </div>

            <div
                v-if="resetError"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ resetError }}
            </div>

            <div
                v-if="resetMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ resetMessage }}
            </div>
        </div>

        <ConfirmDialog
            :open="confirm !== null"
            :title="confirm?.title ?? ''"
            :message="confirm?.message ?? ''"
            :confirm-text="confirm?.confirmText ?? 'Confirm'"
            :variant="confirm?.variant ?? 'primary'"
            :busy="
                flushing ||
                resettingRun ||
                backfillingTypes ||
                recomputingTypes ||
                refreshingLatestCosts ||
                clearingStaleLatestArrival
            "
            @cancel="cancelConfirm"
            @confirm="confirmAction"
        />
    </section>
</template>
