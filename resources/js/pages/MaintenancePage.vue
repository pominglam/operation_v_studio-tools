<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import { clearPageState, loadPageState, savePageState } from '../lib/pageState';

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
const notesMessage = ref<string | null>(null);
const notesError = ref<string | null>(null);
const notesBody = ref<string>('');
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

function requestRestoreDb(): void {
    if (!selectedRestoreUuid.value) return;
    const b = dbBackups.value.find((x) => x.uuid === selectedRestoreUuid.value) ?? null;
    const label = b ? `${b.filename} — ${b.description || 'No description'}` : selectedRestoreUuid.value;
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

    if (current.kind === 'restore_db') {
        await restoreDb();
        return;
    }

    await forceRefreshAll();
}

async function refreshLatestCosts(): Promise<void> {
    refreshingLatestCosts.value = true;
    refreshLatestCostsMessage.value = null;
    refreshLatestCostsError.value = null;

    try {
        const res = await api.post<{ matched: number; updated: number }>('/api/v1/maintenance/refresh-latest-costs');
        refreshLatestCostsMessage.value = `Refreshed latest costs. Matched ${res.data.matched}, updated ${res.data.updated}.`;
    } catch {
        refreshLatestCostsError.value = 'Failed to refresh latest costs.';
    } finally {
        refreshingLatestCosts.value = false;
        confirm.value = null;
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
        productTypes.value = (json.data?.types ?? []).filter((t) => typeof t === 'string' && t.trim() !== '');
        productVendors.value = (json.data?.vendors ?? []).filter((v) => typeof v === 'string' && v.trim() !== '');
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

async function loadExternalRateLimit(): Promise<void> {
    externalHitsLoading.value = true;
    externalHitsError.value = null;
    try {
        const res = await api.get<{ data: { hits_per_minute: number } }>('/api/v1/maintenance/external-rate-limit');
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
            externalAccessError.value = typeof err === 'string' && err.trim() !== '' ? err : 'Failed to update external access.';
            return;
        }
        externalAccessEnabled.value = !!res.data.data.enabled;
        externalAccessTunnel.value = res.data.data.tunnel ?? null;
        if (enabled && !externalAccessTunnel.value?.tunnel_url) {
            externalAccessMessage.value = 'External access enabled. Tunnel URL may take a few seconds — click Refresh.';
        } else {
            externalAccessMessage.value = enabled ? 'External access enabled.' : 'External access disabled.';
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
        const res = await api.put<{ data: { hits_per_minute: number } }>('/api/v1/maintenance/external-rate-limit', {
            hits_per_minute: externalHitsPerMinute.value,
        });
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
            { validateStatus: () => true },
        );
        if (res.status !== 201) {
            dbBackupError.value = 'Failed to create database backup.';
            return;
        }
        dbBackupMessage.value = 'Backup created.';
        dbBackupDescription.value = '';
        await loadDbBackups();
    } catch {
        dbBackupError.value = 'Failed to create database backup.';
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
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            dbRestoreError.value = res.data?.message ?? 'Failed to restore database.';
            return;
        }
        dbRestoreMessage.value = 'Restore started/completed. Refresh the page if things look stale.';
        confirm.value = null;
    } catch {
        dbRestoreError.value = 'Failed to restore database.';
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
    void loadDbBackups();
    void loadExternalRateLimit();
    void loadExternalAccess();
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
                    <div class="text-sm font-medium text-slate-900">External crawl rate limit</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Global throttle applied to external crawls (Bandai, HLJ, competitor sites). Approx hits per minute.
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

            <div v-if="externalHitsError" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                {{ externalHitsError }}
            </div>
            <div
                v-if="externalHitsMessage"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ externalHitsMessage }}
            </div>

            <div class="mt-3 max-w-sm">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Hits per minute</label>
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
                        Expose the full app through a Cloudflare quick tunnel (<span class="font-mono text-xs">trycloudflare.com</span>)
                        protected by a simple password. Local access is unaffected.
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
                        :disabled="externalAccessLoading || externalAccessBusy || !canStartExternalAccessTunnel"
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
                        :disabled="externalAccessLoading || externalAccessBusy || !externalAccessEnabled"
                        @click="setExternalAccessEnabled(false)"
                    >
                        Disable
                    </button>
                </div>
            </div>

            <div v-if="!externalAccessPasswordConfigured" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                Missing <span class="font-mono text-xs">EXTERNAL_ACCESS_PASSWORD</span> in <span class="font-mono text-xs">.env</span>. Configure it to enable external access.
            </div>

            <div v-if="externalAccessError" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
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
                        <span class="font-semibold text-slate-900">{{ externalAccessEnabled ? 'Enabled' : 'Disabled' }}</span>
                        <span v-if="externalAccessTunnel?.running" class="text-emerald-700"> · Tunnel running</span>
                        <span v-else class="text-slate-600"> · Tunnel stopped</span>
                        <span v-if="externalAccessTunnel?.error" class="text-rose-700"> · {{ externalAccessTunnel.error }}</span>
                    </div>

                    <div v-if="externalAccessTunnel?.tunnel_url" class="text-slate-700">
                        URL:
                        <a class="font-mono text-xs text-slate-900 underline" :href="externalAccessTunnel.tunnel_url" target="_blank">
                            {{ externalAccessTunnel.tunnel_url }}
                        </a>
                    </div>
                </div>

                <div v-if="externalAccessTunnel?.tunnel_url" class="mt-1 text-xs text-slate-600">
                    Quick tunnel URLs rotate; if you use an older URL it may show <span class="font-mono">404</span>. Always use the URL shown here.
                </div>
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
                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">
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
                            {{ creatingDbBackup ? 'Creating…' : 'Backup DB' }}
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
                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">
                            Select backup
                        </label>
                        <select
                            v-model="selectedRestoreUuid"
                            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            :disabled="restoringDb || dbBackupsLoading"
                        >
                            <option value="" disabled>Select…</option>
                            <option v-for="b in dbBackups" :key="b.uuid" :value="b.uuid">
                                {{ b.created_at ?? '' }} — {{ b.description || 'No description' }} ({{ b.filename }})
                            </option>
                        </select>
                        <div class="mt-2 text-xs text-slate-600">
                            Loaded <span class="font-semibold text-slate-900">{{ dbBackups.length }}</span> backups.
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button
                            class="inline-flex items-center justify-center rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                            type="button"
                            :disabled="restoringDb || !selectedRestoreUuid"
                            @click="requestRestoreDb"
                        >
                            {{ restoringDb ? 'Restoring…' : 'Restore DB' }}
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
                    <div class="text-sm font-medium text-slate-900">Refresh latest product costs</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Recompute cached latest unit and landed costs for all products based on purchase orders.
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
                    <div class="text-sm font-medium text-slate-900">AliExpress cookies (optional)</div>
                    <div class="mt-1 text-sm text-slate-600">
                        AliExpress blocks automated browsing. Paste your browser cookies JSON here to
                        allow the scraper to use your session.
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
            :busy="flushing || resettingRun || backfillingTypes || recomputingTypes || refreshingLatestCosts"
            @cancel="cancelConfirm"
            @confirm="confirmAction"
        />
    </section>
</template>
