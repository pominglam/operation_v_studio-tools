<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '../../lib/api';
import { decodeHtmlEntitiesDeep } from '../../lib/html';
import { descriptionSourceUrl } from '../../lib/pdpSources';

type ProductInfoAsset = {
    id: number;
    source: string;
    kind: string;
    filename: string;
    mime_type: string | null;
    size_bytes: number | null;
    origin_url?: string | null;
    origin_width?: number | null;
    origin_height?: number | null;
    checksum_sha256?: string | null;
    download_url: string;
    view_url: string;
};

type ProductInfoContent = {
    source: string;
    source_url: string | null;
    title: string | null;
    description_html: string | null;
    attributes: Record<string, unknown> | null;
    updated_at: string | null;
};

type ProductInfoPayload = {
    contents: ProductInfoContent[];
    assets: ProductInfoAsset[];
};

type SourceKey = 'bandai' | 'hlj' | 'plamod' | 'other';

type SelectedSourceState = {
    contentSource: SourceKey;
    imageSource: SourceKey;
};

const SOURCE_LABELS: Record<SourceKey, string> = {
    bandai: 'Bandai',
    hlj: 'HLJ',
    plamod: 'Plamod',
    other: 'Other',
};

function normalizeSourceKey(source: string): SourceKey {
    const s = source.trim().toLowerCase();
    if (s === 'bandai') return 'bandai';
    if (s === 'hlj') return 'hlj';
    if (s === 'plamod') return 'plamod';
    return 'other';
}

function preferredContentSource(available: Set<SourceKey>): SourceKey {
    if (available.has('bandai')) return 'bandai';
    if (available.has('hlj')) return 'hlj';
    if (available.has('other')) return 'other';
    return 'plamod';
}

function preferredImageSource(available: Set<SourceKey>): SourceKey {
    if (available.has('bandai')) return 'bandai';
    if (available.has('hlj')) return 'hlj';
    if (available.has('plamod')) return 'plamod';
    return 'other';
}

function isImage(a: ProductInfoAsset): boolean {
    if (a.kind === 'image') return true;
    return (a.mime_type ?? '').startsWith('image/');
}

function isBlank(s: string | null | undefined): boolean {
    return !s || s.trim() === '';
}

function contentForSource(contents: ProductInfoContent[], source: SourceKey): ProductInfoContent | null {
    const src = source === 'other' ? null : source;
    const candidates = src ? contents.filter((c) => normalizeSourceKey(c.source) === source) : contents.filter((c) => normalizeSourceKey(c.source) === 'other');
    if (candidates.length === 0) return null;

    // Prefer one with description, then most recently updated.
    const withDesc = candidates.filter((c) => !isBlank(c.description_html));
    const list = withDesc.length > 0 ? withDesc : candidates;
    return (
        list
            .slice()
            .sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''))[0] ?? null
    );
}

function assetsForSource(assets: ProductInfoAsset[], source: SourceKey): ProductInfoAsset[] {
    return assets.filter((a) => normalizeSourceKey(a.source) === source).filter(isImage);
}

type PlamodPayload = {
    // legacy, kept for backward compatibility if needed later
    source: 'plamod';
    content: null | {
        source: string;
        source_url: string | null;
        title: string | null;
        description_html: string | null;
        attributes: Record<string, string> | null;
        updated_at: string | null;
    };
    assets: ProductInfoAsset[];
};

const props = defineProps<{
    open: boolean;
    productId: string | null;
    productSku: string | null;
    productPrice: string | null;
    onClose: () => void;
}>();

const loading = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
const data = ref<ProductInfoPayload | null>(null);
const syncing = ref(false);

const availableSources = computed<Set<SourceKey>>(() => {
    const s = new Set<SourceKey>();
    const contents = data.value?.contents ?? [];
    for (const c of contents) s.add(normalizeSourceKey(c.source));
    const assets = data.value?.assets ?? [];
    for (const a of assets) {
        if (isImage(a)) s.add(normalizeSourceKey(a.source));
    }
    return s;
});

const selectedSource = ref<SelectedSourceState>({
    contentSource: 'plamod',
    imageSource: 'plamod',
});

watch(
    () => availableSources.value,
    (avail) => {
        selectedSource.value = {
            contentSource: preferredContentSource(avail),
            imageSource: preferredImageSource(avail),
        };
    },
    { immediate: true },
);

const selectedContent = computed<ProductInfoContent | null>(() => {
    return contentForSource(data.value?.contents ?? [], selectedSource.value.contentSource);
});

const title = computed<string>(() => selectedContent.value?.title || props.productSku || 'Product info');

const attributes = computed<[string, string][]>(() => {
    const attrs = selectedContent.value?.attributes ?? null;
    if (!attrs) return [];
    return Object.entries(attrs)
        .map(([k, v]) => [k, typeof v === 'string' ? v : JSON.stringify(v)] as [string, string])
        .filter(([, v]) => v.trim() !== '');
});

const descriptionHtml = computed<string | null>(() => {
    const raw = selectedContent.value?.description_html ?? null;
    if (!raw) return null;
    const decoded = decodeHtmlEntitiesDeep(raw);
    const trimmed = decoded.trim();
    return trimmed !== '' ? trimmed : null;
});

const imageAssets = computed<ProductInfoAsset[]>(() => {
    const src = selectedSource.value.imageSource;
    return assetsForSource(data.value?.assets ?? [], src);
});

const plamodPdpUrl = computed<string | null>(() => {
    const sku = (props.productSku ?? '').trim();
    if (!sku) return null;
    return `https://plamod.com/retailer/products/${encodeURIComponent(sku)}`;
});

const searchQuery = computed<string>(() => {
    const sku = (props.productSku ?? '').trim();
    if (sku) return sku;
    const t = (selectedContent.value?.title ?? '').trim();
    return t;
});

const pandaSearchUrl = computed<string | null>(() => {
    const q = searchQuery.value.trim();
    if (!q) return null;
    return `https://pandahobby.ca/search?q=${encodeURIComponent(q)}`;
});

const argamaSearchUrl = computed<string | null>(() => {
    const q = searchQuery.value.trim();
    if (!q) return null;
    return `https://argamahobby.com/search?q=${encodeURIComponent(q)}`;
});

const descriptionSource = computed<string | null>(() => {
    const c = selectedContent.value ?? null;
    return descriptionSourceUrl(
        c
            ? {
                  source: c.source,
                  source_url: c.source_url,
              }
            : null,
        { sku: props.productSku, query: searchQuery.value },
    );
});

const activeImageIndex = ref(0);
const activeImage = computed<ProductInfoAsset | null>(() => imageAssets.value[activeImageIndex.value] ?? null);
const activeImageDebug = computed<string | null>(() => {
    const img = activeImage.value;
    if (!img) return null;

    const parts: string[] = [];
    if (img.origin_width && img.origin_height) parts.push(`${img.origin_width}×${img.origin_height}`);
    if (img.checksum_sha256) parts.push(img.checksum_sha256.slice(0, 12));
    return parts.length > 0 ? parts.join(' · ') : null;
});
const savingOrder = ref(false);
const dragIndex = ref<number | null>(null);

function reorder<T>(arr: T[], from: number, to: number): T[] {
    const copy = [...arr];
    const [item] = copy.splice(from, 1);
    if (item === undefined) return copy;
    copy.splice(to, 0, item);
    return copy;
}

async function persistImageOrder(orderedImages: ProductInfoAsset[]): Promise<void> {
    if (!props.productId) return;
    if (selectedSource.value.imageSource !== 'plamod') return;

    savingOrder.value = true;
    error.value = null;
    message.value = null;
    try {
        await api.put(`/api/v1/products/${props.productId}/plamod/assets/order`, {
            asset_ids: orderedImages.map((a) => a.id),
        });
        message.value = 'Image order saved.';
    } catch {
        error.value = 'Failed to save image order.';
    } finally {
        savingOrder.value = false;
    }
}

async function onDropThumbnail(toIndex: number): Promise<void> {
    const from = dragIndex.value;
    dragIndex.value = null;
    if (from === null) return;
    if (from === toIndex) return;
    if (selectedSource.value.imageSource !== 'plamod') return;

    const beforeActiveId = activeImage.value?.id ?? null;
    const reorderedImages = reorder(imageAssets.value, from, toIndex);

    // Update the underlying `data.assets` ordering so the computed `imageAssets` reflects the new order immediately,
    // but only within the Plamod image subset.
    const currentAssets = data.value?.assets ?? [];
    const plamodNonImages: ProductInfoAsset[] = [];
    const otherSourceAssets: ProductInfoAsset[] = [];
    for (const a of currentAssets) {
        if (normalizeSourceKey(a.source) !== 'plamod') {
            otherSourceAssets.push(a);
            continue;
        }
        if (!isImage(a)) {
            plamodNonImages.push(a);
        }
    }
    data.value = data.value
        ? { ...data.value, assets: [...otherSourceAssets, ...reorderedImages, ...plamodNonImages] }
        : data.value;

    // Keep current active image if possible.
    if (beforeActiveId !== null) {
        const idx = reorderedImages.findIndex((a) => a.id === beforeActiveId);
        if (idx >= 0) activeImageIndex.value = idx;
    }

    await persistImageOrder(reorderedImages);
}

function prevImage(): void {
    const n = imageAssets.value.length;
    if (n <= 1) return;
    activeImageIndex.value = (activeImageIndex.value - 1 + n) % n;
}

function nextImage(): void {
    const n = imageAssets.value.length;
    if (n <= 1) return;
    activeImageIndex.value = (activeImageIndex.value + 1) % n;
}

function formatCad(value: string | null): string | null {
    if (!value) return null;
    const n = Number.parseFloat(value);
    if (!Number.isFinite(n)) return null;
    return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(n);
}

watch(
    () => data.value?.assets?.map((a) => a.id).join(',') ?? '',
    () => {
        activeImageIndex.value = 0;
    },
);

async function load(): Promise<void> {
    if (!props.productId) return;
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<{ data: ProductInfoPayload }>(`/api/v1/products/${props.productId}/product-info`);
        data.value = res.data.data;
    } catch {
        error.value = 'Failed to load product info.';
    } finally {
        loading.value = false;
    }
}

async function sync(): Promise<void> {
    if (!props.productId) return;
    syncing.value = true;
    error.value = null;
    message.value = null;
    try {
        await api.post(`/api/v1/products/${props.productId}/product-info/sync`);
        message.value = 'Get product info queued. Refreshing…';
        window.setTimeout(() => void load(), 2000);
    } catch {
        error.value = 'Failed to start product info sync.';
    } finally {
        syncing.value = false;
    }
}

watch(
    () => [props.open, props.productId],
    ([open]) => {
        if (!open) return;
        void load();
    },
    { immediate: true },
);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-black/30" @click="onClose" />

            <aside
                class="absolute right-0 top-0 flex h-full w-full max-w-xl flex-col border-l border-slate-200 bg-white shadow-xl"
            >
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
                        <p class="mt-0.5 text-xs text-slate-600">
                            PDP content & assets (Plamod/HLJ/Bandai) with source attribution
                        </p>
                    </div>

                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-700 transition hover:bg-slate-50"
                        @click="onClose"
                    >
                        Close
                    </button>
                </div>

                <div class="flex-1 space-y-3 overflow-auto p-4">
                    <div v-if="error" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                        {{ error }}
                    </div>
                    <div v-if="message" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                        {{ message }}
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50"
                            type="button"
                            :disabled="syncing || !productId"
                            @click="sync"
                        >
                            {{ syncing ? 'Working…' : 'Get product info' }}
                        </button>
                        <button
                            class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                            type="button"
                            :disabled="loading || !productId"
                            @click="load"
                        >
                            Refresh
                        </button>
                    </div>

                    <div v-if="loading" class="text-sm text-slate-600">Loading…</div>

                    <div v-if="!loading" class="rounded-md border border-slate-200 bg-white p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sources</div>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            <div>
                                <div class="text-xs font-semibold text-slate-700">Description source</div>
                                <select
                                    v-model="selectedSource.contentSource"
                                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900"
                                >
                                    <option v-for="s in Array.from(availableSources)" :key="s" :value="s">
                                        {{ SOURCE_LABELS[s] }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <div class="text-xs font-semibold text-slate-700">Image source</div>
                                <select
                                    v-model="selectedSource.imageSource"
                                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900"
                                >
                                    <option v-for="s in Array.from(availableSources)" :key="s" :value="s">
                                        {{ SOURCE_LABELS[s] }}
                                    </option>
                                </select>
                                <div v-if="selectedSource.imageSource !== 'plamod'" class="mt-1 text-xs text-slate-500">
                                    Drag-reorder is only available for Plamod images.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-md border border-slate-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Assets</div>
                            <div class="text-xs text-slate-500">{{ data?.assets?.length ?? 0 }} file(s)</div>
                        </div>
                        <div class="mt-1 text-xs text-slate-600">
                            Source:
                            <a
                                v-if="plamodPdpUrl"
                                class="font-semibold text-slate-900 underline"
                                :href="plamodPdpUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Plamod PDP
                            </a>
                            <span v-else>—</span>
                        </div>

                        <div v-if="!loading && (data?.assets?.length ?? 0) === 0" class="mt-2 text-sm text-slate-600">
                            No assets synced yet. Click “Get product info”.
                        </div>

                        <ul v-else class="mt-2 space-y-2">
                            <li
                                v-for="a in data?.assets ?? []"
                                :key="a.id"
                                class="flex items-center justify-between gap-3 rounded-md border border-slate-100 px-3 py-2"
                            >
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-slate-900">
                                        {{ a.filename }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        <span class="font-semibold text-slate-700">{{ a.source }}</span>
                                        · {{ a.kind }}<span v-if="a.size_bytes"> · {{ a.size_bytes }} bytes</span>
                                    </div>
                                </div>

                                <a
                                    class="shrink-0 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50"
                                    :href="a.download_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Download
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-md border border-slate-200 bg-white p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">PDP Preview (basic)</div>

                        <div class="mt-2 grid gap-3">
                            <div class="grid gap-1">
                                <div class="text-base font-semibold text-slate-900">
                                    {{ selectedContent?.title ?? productSku ?? '—' }}
                                </div>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
                                    <div><span class="font-semibold text-slate-700">SKU:</span> {{ productSku ?? '—' }}</div>
                                    <div>
                                        <span class="font-semibold text-slate-700">Selling price:</span>
                                        {{ formatCad(productPrice) ?? (productPrice ?? '—') }}
                                    </div>
                                    <div>
                                        <span class="font-semibold text-slate-700">Source:</span>
                                        {{ SOURCE_LABELS[normalizeSourceKey(selectedContent?.source ?? '')] ?? (selectedContent?.source ?? '—') }}
                                    </div>
                                    <div>
                                        <span class="font-semibold text-slate-700">Image source:</span>
                                        {{ SOURCE_LABELS[selectedSource.imageSource] ?? selectedSource.imageSource }}
                                    </div>
                                </div>
                            </div>

                            <div v-if="imageAssets.length > 0" class="rounded-md border border-slate-200 bg-slate-50">
                                <div class="relative">
                                    <img
                                        v-if="activeImage"
                                        :src="activeImage.view_url"
                                        :alt="activeImage.filename"
                                        class="h-72 w-full rounded-md object-contain"
                                    />
                                    <div v-if="activeImageDebug" class="absolute bottom-2 right-2 rounded bg-white/90 px-2 py-1 text-xs text-slate-700">
                                        {{ activeImageDebug }}
                                    </div>

                                    <button
                                        v-if="imageAssets.length > 1"
                                        type="button"
                                        class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full border border-slate-200 bg-white/90 px-2 py-1 text-sm text-slate-900 shadow-sm hover:bg-white"
                                        @click="prevImage"
                                    >
                                        ‹
                                    </button>
                                    <button
                                        v-if="imageAssets.length > 1"
                                        type="button"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full border border-slate-200 bg-white/90 px-2 py-1 text-sm text-slate-900 shadow-sm hover:bg-white"
                                        @click="nextImage"
                                    >
                                        ›
                                    </button>
                                </div>

                                <div
                                    v-if="imageAssets.length > 1"
                                    class="flex items-center gap-2 overflow-auto border-t border-slate-200 bg-white p-2"
                                >
                                    <button
                                        v-for="(img, idx) in imageAssets"
                                        :key="img.id"
                                        type="button"
                                        class="shrink-0 cursor-move rounded border p-0.5 disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="idx === activeImageIndex ? 'border-slate-900' : 'border-slate-200 hover:border-slate-400'"
                                        @click="activeImageIndex = idx"
                                        :disabled="savingOrder"
                                        draggable="true"
                                        @dragstart="dragIndex = idx"
                                        @dragover.prevent
                                        @drop.prevent="onDropThumbnail(idx)"
                                        :title="img.origin_url ? `${img.filename}\n${img.origin_url}\n${img.checksum_sha256?.slice(0, 12) ?? ''}`.trim() : img.filename"
                                    >
                                        <img :src="img.view_url" :alt="img.filename" class="h-12 w-12 rounded object-cover" />
                                    </button>
                                    <div v-if="savingOrder" class="ml-2 text-xs text-slate-500">Saving…</div>
                                </div>
                            </div>

                    <div v-if="!loading" class="rounded-md border border-slate-200 bg-white p-3">
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Description</div>
                        <div class="mt-1 text-xs text-slate-600">
                            Source:
                            <a
                                v-if="descriptionSource"
                                class="font-semibold text-slate-900 underline"
                                :href="descriptionSource"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ selectedContent?.source ?? 'source' }}
                            </a>
                            <span v-else>{{ selectedContent?.source ?? '—' }}</span>
                        </div>

                        <div v-if="descriptionHtml" class="prose prose-slate mt-2 max-w-none" v-html="descriptionHtml" />
                        <div v-else class="mt-2 text-sm text-slate-600">
                            No description found yet.
                            <span v-if="pandaSearchUrl || argamaSearchUrl">
                                Try:
                                <a
                                    v-if="pandaSearchUrl"
                                    class="font-semibold text-slate-900 underline"
                                    :href="pandaSearchUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Panda
                                </a>
                                <span v-if="pandaSearchUrl && argamaSearchUrl"> · </span>
                                <a
                                    v-if="argamaSearchUrl"
                                    class="font-semibold text-slate-900 underline"
                                    :href="argamaSearchUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Argama
                                </a>
                            </span>
                        </div>
                            </div>

                            <div v-if="!loading && attributes.length > 0" class="rounded-md border border-slate-200 bg-white p-3">
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Attributes</div>
                                <dl class="mt-2 divide-y divide-slate-100">
                                    <div v-for="[k, v] in attributes" :key="k" class="grid grid-cols-3 gap-3 py-2 text-sm">
                                        <dt class="col-span-1 font-medium text-slate-700">{{ k }}</dt>
                                        <dd class="col-span-2 text-slate-900">{{ v }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>


