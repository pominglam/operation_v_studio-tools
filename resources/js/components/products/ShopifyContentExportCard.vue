<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../../lib/api';

type TunnelStatus = {
    running: boolean;
    tunnel_url: string | null;
    container_id: string | null;
    error: string | null;
    reachable?: boolean | null;
    reachable_http_status?: number | null;
    reachable_checked_at?: string | null;
    reachable_error?: string | null;
};

type PrepareResult = {
    export_id: string;
    download_url: string;
    exported_products: number;
    exported_rows: number;
    skipped_missing_handle: Array<{ sku: string; description: string }>;
    skipped_duplicate_handle: Array<{ sku: string; description: string; handle: string }>;
    images_enabled: boolean;
    tunnel_base_url: string | null;
    tunnel: TunnelStatus;
};

const tunnel = ref<TunnelStatus | null>(null);
const tunnelLoading = ref(false);
const tunnelActionBusy = ref(false);
const tunnelMessage = ref<string | null>(null);
const tunnelError = ref<string | null>(null);

const preparing = ref(false);
const prepareError = ref<string | null>(null);
const prepared = ref<PrepareResult | null>(null);

async function loadTunnel(): Promise<void> {
    tunnelLoading.value = true;
    tunnelError.value = null;
    tunnelMessage.value = null;
    try {
        const res = await api.get<TunnelStatus>('/api/v1/shopify/image-tunnel');
        tunnel.value = res.data;
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        tunnelError.value = typeof apiMessage === 'string' ? apiMessage : 'Failed to load tunnel status.';
    } finally {
        tunnelLoading.value = false;
    }
}

async function startTunnel(): Promise<void> {
    tunnelActionBusy.value = true;
    tunnelError.value = null;
    tunnelMessage.value = null;
    try {
        const res = await api.post<{ ok: boolean; tunnel_url: string | null; error: string | null }>(
            '/api/v1/shopify/image-tunnel/start',
        );
        if (!res.data.ok) {
            tunnelError.value = res.data.error ?? 'Failed to start tunnel.';
        } else {
            tunnelMessage.value = 'Tunnel started.';
        }
        await loadTunnel();
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        tunnelError.value = typeof apiMessage === 'string' ? apiMessage : 'Failed to start tunnel.';
    } finally {
        tunnelActionBusy.value = false;
    }
}

async function stopTunnel(): Promise<void> {
    tunnelActionBusy.value = true;
    tunnelError.value = null;
    tunnelMessage.value = null;
    try {
        const res = await api.post<{ ok: boolean; error: string | null }>('/api/v1/shopify/image-tunnel/stop');
        if (!res.data.ok) {
            tunnelError.value = res.data.error ?? 'Failed to stop tunnel.';
        } else {
            tunnelMessage.value = 'Tunnel stopped.';
        }
        await loadTunnel();
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        tunnelError.value = typeof apiMessage === 'string' ? apiMessage : 'Failed to stop tunnel.';
    } finally {
        tunnelActionBusy.value = false;
    }
}

async function prepareExport(): Promise<void> {
    preparing.value = true;
    prepareError.value = null;
    tunnelMessage.value = null;
    tunnelError.value = null;
    try {
        const res = await api.post<PrepareResult>('/api/v1/products/exports/shopify-content/prepare');
        prepared.value = res.data;
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        prepareError.value = typeof apiMessage === 'string' ? apiMessage : 'Prepare export failed.';
    } finally {
        preparing.value = false;
    }
}

onMounted(() => {
    void loadTunnel();
});
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <div class="text-sm font-semibold text-slate-900">Shopify content export (images + description)</div>
                <div class="mt-1 text-sm text-slate-600">
                    Step 1: start the images tunnel. Step 2: prepare the CSV, then download.
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                    type="button"
                    :disabled="tunnelLoading || tunnelActionBusy"
                    @click="loadTunnel"
                >
                    {{ tunnelLoading ? 'Loading…' : 'Refresh tunnel' }}
                </button>
                <button
                    class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
                    type="button"
                    :disabled="tunnelLoading || tunnelActionBusy"
                    @click="startTunnel"
                >
                    Start / Update tunnel
                </button>
                <button
                    class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                    type="button"
                    :disabled="tunnelLoading || tunnelActionBusy"
                    @click="stopTunnel"
                >
                    Stop tunnel
                </button>
            </div>
        </div>

        <div v-if="tunnel" class="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
            <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                <div class="text-slate-700">
                    Status:
                    <span class="font-semibold text-slate-900">{{ tunnel.running ? 'Running' : 'Stopped' }}</span>
                    <span v-if="tunnel.error" class="text-rose-700"> · {{ tunnel.error }}</span>
                    <span v-if="tunnel.running && tunnel.reachable !== undefined" class="ml-2">
                        <span v-if="tunnel.reachable === true" class="text-emerald-700">
                            · Verified reachable
                            <span v-if="tunnel.reachable_http_status" class="text-xs text-slate-600">
                                (HTTP {{ tunnel.reachable_http_status }})
                            </span>
                        </span>
                        <span v-else-if="tunnel.reachable === false" class="text-rose-700">
                            · Not reachable
                            <span v-if="tunnel.reachable_http_status" class="text-xs text-slate-600">
                                (HTTP {{ tunnel.reachable_http_status }})
                            </span>
                            <span v-if="tunnel.reachable_error" class="text-xs text-rose-700">
                                ({{ tunnel.reachable_error }})
                            </span>
                        </span>
                        <span v-else class="text-amber-700">
                            · Reachability check unknown
                            <span v-if="tunnel.reachable_error" class="text-xs text-amber-700">
                                ({{ tunnel.reachable_error }})
                            </span>
                        </span>
                    </span>
                </div>
                <div v-if="tunnel.tunnel_url" class="text-slate-700">
                    URL:
                    <a class="font-mono text-xs text-slate-900 underline" :href="tunnel.tunnel_url" target="_blank">
                        {{ tunnel.tunnel_url }}
                    </a>
                </div>
            </div>
        </div>

        <div
            v-if="tunnelError"
            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ tunnelError }}
        </div>
        <div
            v-if="tunnelMessage"
            class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
        >
            {{ tunnelMessage }}
        </div>

        <div class="mt-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div class="text-sm text-slate-700">
                Uses <span class="font-mono text-xs">products.handle</span>, and fills
                <span class="font-mono text-xs">Body (HTML)</span> and <span class="font-mono text-xs">Image Src</span>.
            </div>
            <button
                class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
                type="button"
                :disabled="preparing"
                @click="prepareExport"
                data-testid="shopify-content-prepare"
            >
                {{ preparing ? 'Preparing…' : 'Prepare Shopify content CSV' }}
            </button>
        </div>

        <div
            v-if="prepareError"
            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ prepareError }}
        </div>

        <div v-if="prepared" class="mt-3 rounded-md border border-slate-200 bg-white px-3 py-3 text-sm">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div class="text-slate-800">
                    Exported <span class="font-semibold">{{ prepared.exported_products }}</span> product(s),
                    <span class="font-semibold">{{ prepared.exported_rows }}</span> row(s).
                    <span v-if="!prepared.images_enabled" class="text-amber-800">
                        · Images disabled (tunnel not running)
                    </span>
                </div>
                <a
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                    :href="prepared.download_url"
                    data-testid="shopify-content-download"
                >
                    Download CSV
                </a>
            </div>

            <div v-if="prepared.skipped_duplicate_handle.length" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3">
                <div class="font-semibold text-amber-900">
                    Skipped (duplicate handle): {{ prepared.skipped_duplicate_handle.length }}
                </div>
                <ul class="mt-2 space-y-1">
                    <li v-for="p in prepared.skipped_duplicate_handle" :key="p.sku" class="text-xs text-amber-900">
                        <span class="font-mono">{{ p.sku }}</span> ·
                        <span class="font-mono">{{ p.handle }}</span> · {{ p.description }}
                    </li>
                </ul>
            </div>

            <div v-if="prepared.skipped_missing_handle.length" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3">
                <div class="font-semibold text-amber-900">
                    Skipped (missing handle): {{ prepared.skipped_missing_handle.length }}
                </div>
                <ul class="mt-2 space-y-1">
                    <li v-for="p in prepared.skipped_missing_handle" :key="p.sku" class="text-xs text-amber-900">
                        <span class="font-mono">{{ p.sku }}</span> · {{ p.description }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>


