<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api } from '../lib/api';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';

const flushing = ref(false);
const resettingRun = ref(false);
const recrawlingSites = ref(false);
const forceRefreshingAll = ref(false);
const notesLoading = ref(false);
const notesSaving = ref(false);
const flushMessage = ref<string | null>(null);
const flushError = ref<string | null>(null);
const resetMessage = ref<string | null>(null);
const resetError = ref<string | null>(null);
const recrawlMessage = ref<string | null>(null);
const recrawlError = ref<string | null>(null);
const forceRefreshMessage = ref<string | null>(null);
const forceRefreshError = ref<string | null>(null);
const notesMessage = ref<string | null>(null);
const notesError = ref<string | null>(null);
const notesBody = ref<string>('');
const availableSites = ref<Array<{ key: string; name: string }>>([]);
const siteKeys = ref<string[]>([]);

const siteOptions = computed<MultiSelectOption[]>(() => {
    return availableSites.value.map((s) => ({ value: s.key, label: s.name }));
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

    await forceRefreshAll();
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

async function recrawlSelectedSites(): Promise<void> {
    if (siteKeys.value.length === 0) return;
    recrawlingSites.value = true;
    recrawlMessage.value = null;
    recrawlError.value = null;

    try {
        const res = await api.post(
            '/api/v1/price-research/run',
            { force: true, site_keys: siteKeys.value },
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

onMounted(() => {
    void loadPriceResearchSites();
    void loadMaintenanceNotes();
});
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
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-900">Recrawl prices by site</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Force recrawl only the selected competitor site(s) across all products. This
                        does not mark other sites as fresh.
                    </div>
                    <div class="mt-3 max-w-sm">
                        <MultiSelectFilter
                            v-model="siteKeys"
                            label="Sites"
                            :options="siteOptions"
                            placeholder="Select site(s)…"
                        />
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="recrawlingSites || siteKeys.length === 0"
                    @click="recrawlSelectedSites"
                >
                    {{ recrawlingSites ? 'Starting…' : 'Start recrawl' }}
                </button>
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
            :busy="flushing || resettingRun"
            @cancel="cancelConfirm"
            @confirm="confirmAction"
        />
    </section>
</template>
