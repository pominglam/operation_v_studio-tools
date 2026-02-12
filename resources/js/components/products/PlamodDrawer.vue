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
    sort_order?: number | null;
    shopify_enabled?: boolean | null;
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
    preferred_description_source?: string | null;
    contents: ProductInfoContent[];
    assets: ProductInfoAsset[];
};

type SourceKey = 'bandai' | 'hlj' | 'gundamplanet' | 'newtype' | 'plamod' | 'other';

type DescriptionSelectionMode = 'source' | 'manual';

type SelectedSourceState = {
    contentSource: SourceKey;
    descriptionMode: DescriptionSelectionMode;
};

const SOURCE_LABELS: Record<SourceKey, string> = {
    bandai: 'Bandai',
    hlj: 'HLJ',
    plamod: 'Plamod',
    gundamplanet: 'GundamPlanet',
    newtype: 'Newtype',
    other: 'Other',
};

const SOURCE_BADGE_CLASSES: Record<SourceKey, string> = {
    plamod: 'border-indigo-700 bg-indigo-600 text-white',
    hlj: 'border-emerald-700 bg-emerald-600 text-white',
    newtype: 'border-fuchsia-700 bg-fuchsia-600 text-white',
    gundamplanet: 'border-amber-700 bg-amber-500 text-slate-950',
    bandai: 'border-sky-700 bg-sky-600 text-white',
    other: 'border-slate-700 bg-slate-700 text-white',
};

function sourceBadgeClass(key: SourceKey): string {
    return SOURCE_BADGE_CLASSES[key] ?? SOURCE_BADGE_CLASSES.other;
}

function normalizeSourceKey(source: string): SourceKey {
    const s = source.trim().toLowerCase();
    if (s === 'bandai') return 'bandai';
    if (s === 'hlj') return 'hlj';
    if (s === 'plamod') return 'plamod';
    if (s === 'gundamplanet') return 'gundamplanet';
    if (s === 'newtype') return 'newtype';
    return 'other';
}

function preferredContentSource(available: Set<SourceKey>): SourceKey {
    if (available.has('hlj')) return 'hlj';
    if (available.has('bandai')) return 'bandai';
    if (available.has('newtype')) return 'newtype';
    if (available.has('other')) return 'other';
    return 'plamod';
}

function isImage(a: ProductInfoAsset): boolean {
    if (a.kind === 'image') return true;
    return (a.mime_type ?? '').startsWith('image/');
}

function isBlank(s: string | null | undefined): boolean {
    return !s || s.trim() === '';
}

function htmlToPlainText(html: string): string {
    const el = document.createElement('div');
    el.innerHTML = html;
    return (el.textContent ?? '').trim();
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
    descriptionMode: 'source',
});

watch(
    () => availableSources.value,
    (avail) => {
        const preferredRaw = data.value?.preferred_description_source ?? null;
        const preferred = typeof preferredRaw === 'string' && preferredRaw.trim() !== '' ? normalizeSourceKey(preferredRaw) : null;
        selectedSource.value = {
            contentSource: preferred && avail.has(preferred) ? preferred : preferredContentSource(avail),
            descriptionMode: 'source',
        };
    },
    { immediate: true },
);

const selectedContent = computed<ProductInfoContent | null>(() => {
    return contentForSource(data.value?.contents ?? [], selectedSource.value.contentSource);
});

const title = computed<string>(() => selectedContent.value?.title || props.productSku || 'Product info');

type DescriptionCard = {
    key: SourceKey;
    content: ProductInfoContent;
};

const descriptionCards = computed<DescriptionCard[]>(() => {
    const contents = data.value?.contents ?? [];
    const order: SourceKey[] = ['hlj', 'newtype', 'gundamplanet', 'plamod', 'bandai', 'other'];
    const out: DescriptionCard[] = [];
    for (const key of order) {
        const c = contentForSource(contents, key);
        if (!c) continue;
        out.push({ key, content: c });
    }
    return out;
});

const manualDescriptionDraft = ref<string>('');
const otherDefaultDescriptionHtml = computed<string | null>(() => {
    const other = contentForSource(data.value?.contents ?? [], 'other');
    return other ? descriptionHtmlFor(other) : null;
});

watch(
    () => [props.open, props.productId, otherDefaultDescriptionHtml.value] as const,
    ([open, productId, html]) => {
        if (!open) return;
        if (!productId) return;

        // Seed initial draft but never overwrite user's edits.
        if (manualDescriptionDraft.value.trim() !== '') return;
        manualDescriptionDraft.value = html ? htmlToPlainText(html) : '';
    },
    { immediate: true },
);

function useDescriptionSource(key: SourceKey): void {
    selectedSource.value = { ...selectedSource.value, contentSource: key, descriptionMode: 'source' };
    void persistPreferredDescriptionSource(key);
}

function useManualDescription(): void {
    selectedSource.value = { ...selectedSource.value, contentSource: 'other', descriptionMode: 'manual' };
    void persistPreferredDescriptionSource('other');
}

const savingPreferredDescription = ref(false);

async function persistPreferredDescriptionSource(key: SourceKey): Promise<void> {
    if (!props.productId) return;
    savingPreferredDescription.value = true;
    error.value = null;
    try {
        await api.patch(`/api/v1/products/${props.productId}/preferred-description-source`, {
            preferred_description_source: key,
        });
        if (data.value) {
            data.value = { ...data.value, preferred_description_source: key };
        }
    } catch {
        error.value = 'Failed to save preferred description.';
    } finally {
        savingPreferredDescription.value = false;
    }
}

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

function descriptionHtmlFor(content: ProductInfoContent): string | null {
    const raw = content.description_html ?? null;
    if (!raw) return null;
    const decoded = decodeHtmlEntitiesDeep(raw);
    const trimmed = decoded.trim();
    return trimmed !== '' ? trimmed : null;
}

function assetSortKey(a: ProductInfoAsset): [number, number] {
    const order = typeof a.sort_order === 'number' && Number.isFinite(a.sort_order) ? a.sort_order : 1_000_000_000;
    return [order, a.id];
}

function isExporting(a: ProductInfoAsset): boolean {
    return (a.shopify_enabled ?? true) === true;
}

const imageAssets = computed<ProductInfoAsset[]>(() => {
    return (data.value?.assets ?? [])
        .filter(isImage)
        .slice()
        .sort((a, b) => {
            const [ao, aid] = assetSortKey(a);
            const [bo, bid] = assetSortKey(b);
            if (ao !== bo) return ao - bo;
            return aid - bid;
        });
});

const hiddenImageSources = ref<Set<SourceKey>>(new Set());
const HIDDEN_SOURCES_STORAGE_PREFIX = 'plamod_drawer:hidden_image_sources:';

function storageKeyForHiddenSources(productId: string | null): string | null {
    const id = (productId ?? '').trim();
    if (!id) return null;
    return `${HIDDEN_SOURCES_STORAGE_PREFIX}${id}`;
}

function loadHiddenSourcesFromStorage(): void {
    const key = storageKeyForHiddenSources(props.productId);
    if (!key) return;
    try {
        const raw = window.localStorage.getItem(key);
        const list = raw ? (JSON.parse(raw) as unknown) : null;
        if (!Array.isArray(list)) return;
        const next = new Set<SourceKey>();
        for (const v of list) {
            if (typeof v !== 'string') continue;
            next.add(normalizeSourceKey(v));
        }
        hiddenImageSources.value = next;
    } catch {
        // ignore (privacy mode / invalid JSON)
    }
}

function persistHiddenSourcesToStorage(): void {
    const key = storageKeyForHiddenSources(props.productId);
    if (!key) return;
    try {
        const list = Array.from(hiddenImageSources.value.values());
        window.localStorage.setItem(key, JSON.stringify(list));
    } catch {
        // ignore
    }
}

type ImageSourceStat = {
    key: SourceKey;
    label: string;
    count: number;
    hidden: boolean;
};

const imageSourceStats = computed<ImageSourceStat[]>(() => {
    const counts = new Map<SourceKey, number>();
    for (const a of imageAssets.value) {
        const k = normalizeSourceKey(a.source);
        counts.set(k, (counts.get(k) ?? 0) + 1);
    }
    const order: SourceKey[] = ['hlj', 'gundamplanet', 'plamod', 'bandai', 'other'];
    const keys = Array.from(counts.keys()).sort((a, b) => {
        const ai = order.indexOf(a);
        const bi = order.indexOf(b);
        if (ai >= 0 && bi >= 0) return ai - bi;
        if (ai >= 0) return -1;
        if (bi >= 0) return 1;
        return a.localeCompare(b);
    });
    return keys.map((k) => ({
        key: k,
        label: SOURCE_LABELS[k] ?? k,
        count: counts.get(k) ?? 0,
        hidden: hiddenImageSources.value.has(k),
    }));
});

function toggleImageSourceVisibility(key: SourceKey): void {
    const next = new Set(hiddenImageSources.value);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    hiddenImageSources.value = next;
    persistHiddenSourcesToStorage();

    // When hiding a source, treat it like "disabled for export" as well.
    if (hiddenImageSources.value.has(key)) {
        void disableImagesForSource(key);
    }
}

function showAllImageSources(): void {
    hiddenImageSources.value = new Set();
    persistHiddenSourcesToStorage();
}

async function disableImagesForSource(key: SourceKey): Promise<void> {
    if (!props.productId) return;

    const idsToDisable = imageAssets.value
        .filter((a) => normalizeSourceKey(a.source) === key)
        .filter((a) => isExporting(a))
        .map((a) => a.id);

    if (idsToDisable.length === 0) {
        return;
    }

    // Optimistic update
    if (data.value) {
        const set = new Set(idsToDisable);
        data.value = {
            ...data.value,
            assets: (data.value.assets ?? []).map((x) => (set.has(x.id) ? { ...x, shopify_enabled: false } : x)),
        };
    }

    try {
        await Promise.allSettled(
            idsToDisable.map((id) => api.patch(`/api/v1/product-assets/${id}/shopify-enabled`, { shopify_enabled: false })),
        );

        // Reorder: keep enabled first, disabled last.
        const after = imageAssets.value.slice();
        const enabled = after.filter((a) => isExporting(a));
        const disabled = after.filter((a) => !isExporting(a));
        const ordered = [...enabled, ...disabled];
        applyImageOrderLocally(ordered);
        await persistImageOrder(ordered);
    } catch {
        // Best-effort; if persistence fails, reload to reflect server truth.
        await load();
    }
}

async function disableImagesForHiddenSources(): Promise<void> {
    if (!props.productId) return;
    if (disablingHiddenSources.value) return;

    const hidden = hiddenImageSources.value;
    if (hidden.size === 0) return;

    const idsToDisable = imageAssets.value
        .filter((a) => hidden.has(normalizeSourceKey(a.source)))
        .filter((a) => isExporting(a))
        .map((a) => a.id);

    if (idsToDisable.length === 0) {
        return;
    }

    disablingHiddenSources.value = true;

    // Optimistic update
    if (data.value) {
        const set = new Set(idsToDisable);
        data.value = {
            ...data.value,
            assets: (data.value.assets ?? []).map((x) => (set.has(x.id) ? { ...x, shopify_enabled: false } : x)),
        };
    }

    try {
        await Promise.allSettled(
            idsToDisable.map((id) => api.patch(`/api/v1/product-assets/${id}/shopify-enabled`, { shopify_enabled: false })),
        );

        // Reorder: keep enabled first, disabled last.
        const after = imageAssets.value.slice();
        const enabled = after.filter((a) => isExporting(a));
        const disabled = after.filter((a) => !isExporting(a));
        const ordered = [...enabled, ...disabled];
        applyImageOrderLocally(ordered);
        await persistImageOrder(ordered);
    } catch {
        // Best-effort; if persistence fails, reload to reflect server truth.
        await load();
    } finally {
        disablingHiddenSources.value = false;
    }
}

const visibleImageAssets = computed<ProductInfoAsset[]>(() => {
    const hidden = hiddenImageSources.value;
    if (hidden.size === 0) return imageAssets.value;
    return imageAssets.value.filter((a) => !hidden.has(normalizeSourceKey(a.source)));
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

const activeImageId = ref<number | null>(null);
const activeImageIndex = computed<number>(() => {
    const id = activeImageId.value;
    if (id === null) return 0;
    const idx = visibleImageAssets.value.findIndex((a) => a.id === id);
    return idx >= 0 ? idx : 0;
});
const activeImage = computed<ProductInfoAsset | null>(() => visibleImageAssets.value[activeImageIndex.value] ?? null);
const activeImageDebug = computed<string | null>(() => {
    const img = activeImage.value;
    if (!img) return null;

    const parts: string[] = [];
    if (img.origin_width && img.origin_height) parts.push(`${img.origin_width}×${img.origin_height}`);
    if (img.checksum_sha256) parts.push(img.checksum_sha256.slice(0, 12));
    return parts.length > 0 ? parts.join(' · ') : null;
});
const savingOrder = ref(false);
const dragAssetId = ref<number | null>(null);
const togglingShopify = ref<Record<number, true>>({});
const dedupingExact = ref(false);
const disablingHiddenSources = ref(false);

function applyImageOrderLocally(orderedImages: ProductInfoAsset[]): void {
    if (!data.value) return;

    const idToOrder = new Map<number, number>();
    for (let i = 0; i < orderedImages.length; i++) {
        const a = orderedImages[i];
        if (!a) continue;
        idToOrder.set(a.id, i + 1);
    }

    const nonImages: ProductInfoAsset[] = (data.value.assets ?? []).filter((a) => !isImage(a));
    const updatedImages = orderedImages.map((a) => ({
        ...a,
        sort_order: idToOrder.get(a.id) ?? a.sort_order ?? null,
    }));

    data.value = {
        ...data.value,
        assets: [...updatedImages, ...nonImages],
    };
}

function reorder<T>(arr: T[], from: number, to: number): T[] {
    const copy = [...arr];
    const [item] = copy.splice(from, 1);
    if (item === undefined) return copy;
    copy.splice(to, 0, item);
    return copy;
}

function mergeVisibleAndHiddenImages(visibleOrdered: ProductInfoAsset[]): ProductInfoAsset[] {
    const visibleIds = new Set(visibleOrdered.map((a) => a.id));
    const hidden = imageAssets.value.filter((a) => !visibleIds.has(a.id));
    return [...visibleOrdered, ...hidden];
}

async function persistImageOrder(orderedImages: ProductInfoAsset[]): Promise<void> {
    if (!props.productId) return;

    savingOrder.value = true;
    error.value = null;
    message.value = null;
    try {
        await api.put(`/api/v1/products/${props.productId}/assets/order`, {
            asset_ids: orderedImages.map((a) => a.id),
        });
        message.value = 'Image order saved.';
    } catch {
        error.value = 'Failed to save image order.';
    } finally {
        savingOrder.value = false;
    }
}

async function sortExportingImagesBySource(): Promise<void> {
    if (savingOrder.value) return;

    const current = imageAssets.value.slice();
    if (current.length <= 1) return;

    const enabled: ProductInfoAsset[] = [];
    const disabled: ProductInfoAsset[] = [];
    for (const a of current) {
        if (isExporting(a)) enabled.push(a);
        else disabled.push(a);
    }

    const order: SourceKey[] = ['plamod', 'hlj', 'newtype', 'gundamplanet'];
    const buckets = new Map<SourceKey, ProductInfoAsset[]>();
    for (const k of order) buckets.set(k, []);

    const otherEnabled: ProductInfoAsset[] = [];
    for (const a of enabled) {
        const k = normalizeSourceKey(a.source);
        const bucket = buckets.get(k) ?? null;
        if (bucket) bucket.push(a);
        else otherEnabled.push(a);
    }

    const sortedEnabled: ProductInfoAsset[] = [];
    for (const k of order) {
        sortedEnabled.push(...(buckets.get(k) ?? []));
    }
    sortedEnabled.push(...otherEnabled);

    const ordered = [...sortedEnabled, ...disabled];
    applyImageOrderLocally(ordered);

    // Keep current active image if possible.
    const beforeActiveId = activeImage.value?.id ?? null;
    if (beforeActiveId !== null) {
        const idx = ordered.findIndex((a) => a.id === beforeActiveId);
        if (idx >= 0) activeImageId.value = ordered[idx]?.id ?? beforeActiveId;
    }

    await persistImageOrder(ordered);
}

async function onDropThumbnail(toIndex: number): Promise<void> {
    const fromId = dragAssetId.value;
    dragAssetId.value = null;
    if (fromId === null) return;
    const from = visibleImageAssets.value.findIndex((a) => a.id === fromId);
    if (from < 0) return;
    if (from === toIndex) return;

    const beforeActiveId = activeImage.value?.id ?? null;
    const reorderedVisible = reorder(visibleImageAssets.value, from, toIndex);
    const reorderedImages = mergeVisibleAndHiddenImages(reorderedVisible);

    // Update local ordering immediately (and update sort_order so the computed sort doesn't snap back).
    applyImageOrderLocally(reorderedImages);

    // Keep current active image if possible.
    if (beforeActiveId !== null) {
        const idx = reorderedImages.findIndex((a) => a.id === beforeActiveId);
        if (idx >= 0) activeImageId.value = reorderedImages[idx]?.id ?? beforeActiveId;
    }

    await persistImageOrder(reorderedImages);
}

function prevImage(): void {
    const n = visibleImageAssets.value.length;
    if (n <= 1) return;
    const idx = activeImageIndex.value;
    const next = (idx - 1 + n) % n;
    activeImageId.value = visibleImageAssets.value[next]?.id ?? null;
}

function nextImage(): void {
    const n = visibleImageAssets.value.length;
    if (n <= 1) return;
    const idx = activeImageIndex.value;
    const next = (idx + 1) % n;
    activeImageId.value = visibleImageAssets.value[next]?.id ?? null;
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
        activeImageId.value = visibleImageAssets.value[0]?.id ?? null;
    },
);

watch(
    () => visibleImageAssets.value.map((a) => a.id).join(','),
    () => {
        const id = activeImageId.value;
        if (id === null) {
            activeImageId.value = visibleImageAssets.value[0]?.id ?? null;
            return;
        }
        const stillVisible = visibleImageAssets.value.some((a) => a.id === id);
        if (!stillVisible) {
            activeImageId.value = visibleImageAssets.value[0]?.id ?? null;
        }
    },
);

function isTogglingShopify(id: number): boolean {
    return togglingShopify.value[id] === true;
}

async function toggleShopifyEnabled(a: ProductInfoAsset): Promise<void> {
    const id = a.id;
    if (isTogglingShopify(id)) return;

    const current = a.shopify_enabled ?? true;
    const next = !current;

    togglingShopify.value = { ...togglingShopify.value, [id]: true };
    error.value = null;
    message.value = null;

    // Optimistic update
    if (data.value) {
        data.value = {
            ...data.value,
            assets: (data.value.assets ?? []).map((x) => (x.id === id ? { ...x, shopify_enabled: next } : x)),
        };
    }

    try {
        await api.patch(`/api/v1/product-assets/${id}/shopify-enabled`, { shopify_enabled: next });

        // If disabling an image, move it to the end (you likely won't use it).
        // Also keep disabled images grouped at the back for readability.
        const imgs = imageAssets.value.slice();
        const tgt = imgs.find((x) => x.id === id) ?? null;
        if (tgt) {
            const rest = imgs.filter((x) => x.id !== id);
            const enabled = rest.filter((x) => (x.shopify_enabled ?? true) === true);
            const disabled = rest.filter((x) => (x.shopify_enabled ?? true) === false);

            let ordered: ProductInfoAsset[];
            if (next === false) {
                ordered = [...enabled, ...disabled, { ...tgt, shopify_enabled: false }];
            } else {
                // Re-enabled: put it at the end of the enabled group (before disabled).
                ordered = [...enabled, { ...tgt, shopify_enabled: true }, ...disabled];
            }

            applyImageOrderLocally(ordered);
            await persistImageOrder(ordered);
        }
    } catch {
        if (data.value) {
            data.value = {
                ...data.value,
                assets: (data.value.assets ?? []).map((x) => (x.id === id ? { ...x, shopify_enabled: current } : x)),
            };
        }
        error.value = 'Failed to update Shopify export setting.';
    } finally {
        const { [id]: _omit, ...rest } = togglingShopify.value;
        togglingShopify.value = rest;
    }
}

async function disableExactDuplicateImages(): Promise<void> {
    if (!props.productId) return;
    if (dedupingExact.value) return;

    // Group by checksum (exact byte-identical images).
    const imgs = imageAssets.value.slice();
    const groups = new Map<string, ProductInfoAsset[]>();
    for (const a of imgs) {
        const sha = (a.checksum_sha256 ?? '').trim();
        if (!sha) continue;
        const list = groups.get(sha) ?? [];
        list.push(a);
        groups.set(sha, list);
    }

    // Determine which assets to disable: keep first (current order), disable rest.
    const toDisable: ProductInfoAsset[] = [];
    for (const [, list] of groups) {
        if (list.length <= 1) continue;
        for (const a of list.slice(1)) {
            if ((a.shopify_enabled ?? true) === true) {
                toDisable.push(a);
            }
        }
    }
    if (toDisable.length === 0) {
        message.value = 'No exact duplicates found.';
        return;
    }

    dedupingExact.value = true;
    error.value = null;
    message.value = null;

    // Optimistic: disable duplicates locally.
    if (data.value) {
        const disableIds = new Set(toDisable.map((a) => a.id));
        data.value = {
            ...data.value,
            assets: (data.value.assets ?? []).map((x) =>
                disableIds.has(x.id) ? { ...x, shopify_enabled: false } : x,
            ),
        };
    }

    try {
        await Promise.allSettled(
            toDisable.map((a) => api.patch(`/api/v1/product-assets/${a.id}/shopify-enabled`, { shopify_enabled: false })),
        );

        // Reorder: enabled first (keep relative order), then disabled.
        const after = imageAssets.value.slice();
        const enabled = after.filter((a) => (a.shopify_enabled ?? true) === true);
        const disabled = after.filter((a) => (a.shopify_enabled ?? true) === false);
        const ordered = [...enabled, ...disabled];

        applyImageOrderLocally(ordered);
        await persistImageOrder(ordered);

        message.value = `Disabled ${toDisable.length} exact duplicate(s).`;
    } catch {
        error.value = 'Failed to disable exact duplicates.';
        await load();
    } finally {
        dedupingExact.value = false;
    }
}

async function load(): Promise<void> {
    if (!props.productId) return;
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<{ data: ProductInfoPayload }>(`/api/v1/products/${props.productId}/product-info`);
        data.value = res.data.data;
        // Enforce: hidden sources should not be exported.
        void disableImagesForHiddenSources();
    } catch {
        error.value = 'Failed to load product info.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.productId],
    ([open]) => {
        if (!open) return;
        loadHiddenSourcesFromStorage();
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
                class="absolute right-0 top-0 flex h-full w-full max-w-5xl flex-col border-l border-slate-200 bg-white shadow-xl"
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

                    <div v-if="loading" class="text-sm text-slate-600">Loading…</div>

                    <div v-if="!loading && attributes.length > 0" class="rounded-md border border-slate-200 bg-white p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Attributes</div>
                        <dl class="mt-2 divide-y divide-slate-100">
                            <div v-for="[k, v] in attributes" :key="k" class="grid grid-cols-3 gap-3 py-2 text-sm">
                                <dt class="col-span-1 font-medium text-slate-700">{{ k }}</dt>
                                <dd class="col-span-2 text-slate-900">{{ v }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div v-if="!loading" class="rounded-md border border-slate-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Descriptions</div>
                            <div class="text-xs text-slate-500">
                                {{ descriptionCards.length }} source(s)
                            </div>
                        </div>

                        <div v-if="descriptionCards.length === 0" class="mt-2 text-sm text-slate-600">No descriptions found yet.</div>

                        <div class="mt-2 grid gap-3 lg:grid-cols-2">
                            <div
                                v-for="card in descriptionCards"
                                :key="card.key"
                                class="rounded-md border p-3"
                                :class="
                                    selectedSource.descriptionMode === 'source' && card.key === selectedSource.contentSource
                                        ? 'border-slate-900 bg-slate-50'
                                        : 'border-slate-200 bg-white hover:border-slate-300'
                                "
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800">
                                                {{ SOURCE_LABELS[card.key] ?? card.key }}
                                            </div>
                                            <div v-if="card.content.updated_at" class="text-xs text-slate-500">
                                                updated {{ card.content.updated_at.slice(0, 10) }}
                                            </div>
                                        </div>
                                        <div class="mt-1 truncate text-sm font-semibold text-slate-900">
                                            {{ card.content.title ?? '—' }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-600">
                                            <a
                                                v-if="card.content.source_url"
                                                class="font-semibold text-slate-900 underline"
                                                :href="card.content.source_url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Open source
                                            </a>
                                            <span v-else>—</span>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-md border px-3 py-1.5 text-xs font-semibold transition"
                                        :class="
                                            selectedSource.descriptionMode === 'source' && card.key === selectedSource.contentSource
                                                ? 'border-slate-900 bg-slate-900 text-white hover:bg-slate-800'
                                                : 'border-slate-200 bg-white text-slate-900 hover:bg-slate-50'
                                        "
                                        :disabled="savingPreferredDescription"
                                        @click="useDescriptionSource(card.key)"
                                    >
                                        {{
                                            selectedSource.descriptionMode === 'source' && card.key === selectedSource.contentSource
                                                ? 'Using'
                                                : 'Use this'
                                        }}
                                    </button>
                                </div>

                                <div class="mt-2 max-h-40 overflow-auto rounded-md border border-slate-100 bg-white p-2">
                                    <div
                                        v-if="descriptionHtmlFor(card.content)"
                                        class="prose prose-slate max-w-none text-sm"
                                        v-html="descriptionHtmlFor(card.content)"
                                    />
                                    <div v-else class="text-sm text-slate-600">No description text.</div>
                                </div>
                            </div>

                            <div
                                class="rounded-md border p-3"
                                :class="
                                    selectedSource.descriptionMode === 'manual'
                                        ? 'border-slate-900 bg-slate-50'
                                        : 'border-slate-200 bg-white hover:border-slate-300'
                                "
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800">
                                                Manual
                                            </div>
                                            <div class="text-xs text-slate-500">local</div>
                                        </div>
                                        <div class="mt-1 truncate text-sm font-semibold text-slate-900">Editable draft</div>
                                        <div class="mt-1 text-xs text-slate-600">—</div>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-md border px-3 py-1.5 text-xs font-semibold transition"
                                        :class="
                                            selectedSource.descriptionMode === 'manual'
                                                ? 'border-slate-900 bg-slate-900 text-white hover:bg-slate-800'
                                                : 'border-slate-200 bg-white text-slate-900 hover:bg-slate-50'
                                        "
                                        :disabled="savingPreferredDescription"
                                        @click="useManualDescription"
                                    >
                                        {{ selectedSource.descriptionMode === 'manual' ? 'Using' : 'Use this' }}
                                    </button>
                                </div>

                                <div class="mt-2 max-h-40 overflow-auto rounded-md border border-slate-100 bg-white p-2">
                                    <textarea
                                        data-testid="description-editor-manual"
                                        v-model="manualDescriptionDraft"
                                        class="h-32 w-full resize-none border-0 bg-transparent p-0 text-sm leading-5 text-slate-900 outline-none"
                                        :placeholder="otherDefaultDescriptionHtml ? '' : 'Type a description…'"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-md border border-slate-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Photos</div>
                            <div class="text-xs text-slate-500">
                                {{ visibleImageAssets.length }} shown · {{ imageAssets.length }} total
                            </div>
                        </div>
                        <div class="mt-1 text-xs text-slate-600">
                            Drag to reorder (Shopify export follows this order). Toggle export per photo (color vs grayed out).
                        </div>

                        <div v-if="imageAssets.length > 0" class="mt-2 flex flex-wrap items-center gap-2">
                            <div class="text-xs font-semibold text-slate-700">Sources</div>
                            <button
                                v-for="s in imageSourceStats"
                                :key="s.key"
                                type="button"
                                class="rounded-full border px-2 py-0.5 text-xs font-semibold transition"
                                :class="
                                    s.hidden
                                        ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                        : 'border-slate-900 bg-slate-900 text-white hover:bg-slate-800'
                                "
                                @click="toggleImageSourceVisibility(s.key)"
                            >
                                {{ s.label }} <span class="opacity-80">({{ s.count }})</span>
                                <span v-if="s.hidden" class="opacity-70"> · hidden</span>
                            </button>
                            <button
                                v-if="hiddenImageSources.size > 0"
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-900 hover:bg-slate-50"
                                @click="showAllImageSources"
                            >
                                Show all
                            </button>
                            <div class="grow" />
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                                :disabled="dedupingExact || savingOrder"
                                @click="disableExactDuplicateImages"
                                title="Exact duplicates are detected by checksum (identical bytes). Duplicates will be disabled for Shopify export."
                            >
                                {{ dedupingExact ? 'Disabling…' : 'Disable exact duplicates' }}
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                                :disabled="savingOrder"
                                @click="sortExportingImagesBySource"
                                title="Reorders exporting (On) photos by source: Plamod → HLJ → Newtype → GundamPlanet."
                            >
                                Sort exporting by source
                            </button>
                        </div>

                        <div v-if="imageAssets.length === 0" class="mt-2 text-sm text-slate-600">No images found yet.</div>

                        <div v-else class="mt-2 rounded-md border border-slate-200 bg-slate-50">
                            <div class="relative">
                                <img
                                    v-if="activeImage"
                                    :src="activeImage.view_url"
                                    :alt="activeImage.filename"
                                    class="h-72 w-full rounded-md object-contain"
                                    :class="(activeImage.shopify_enabled ?? true) ? '' : 'opacity-60 grayscale'"
                                />

                                <div class="absolute left-2 top-2 flex flex-wrap items-center gap-2">
                                    <div
                                        v-if="activeImage"
                                        class="rounded-full border px-2 py-0.5 text-xs font-semibold"
                                        :class="sourceBadgeClass(normalizeSourceKey(activeImage.source))"
                                    >
                                        {{ SOURCE_LABELS[normalizeSourceKey(activeImage.source)] ?? activeImage.source }}
                                    </div>
                                    <button
                                        v-if="activeImage"
                                        type="button"
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold transition disabled:opacity-50"
                                        :class="
                                            (activeImage.shopify_enabled ?? true)
                                                ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200'
                                                : 'bg-slate-200 text-slate-700 hover:bg-slate-300'
                                        "
                                        :disabled="isTogglingShopify(activeImage.id)"
                                        @click="toggleShopifyEnabled(activeImage)"
                                    >
                                        {{ (activeImage.shopify_enabled ?? true) ? 'Exporting' : 'Not exporting' }}
                                    </button>
                                </div>

                                <div
                                    v-if="activeImageDebug"
                                    class="absolute bottom-2 right-2 rounded bg-white/90 px-2 py-1 text-xs text-slate-700"
                                >
                                    {{ activeImageDebug }}
                                </div>

                                <button
                                    v-if="visibleImageAssets.length > 1"
                                    type="button"
                                    class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full border border-slate-200 bg-white/90 px-2 py-1 text-sm text-slate-900 shadow-sm hover:bg-white"
                                    @click="prevImage"
                                >
                                    ‹
                                </button>
                                <button
                                    v-if="visibleImageAssets.length > 1"
                                    type="button"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full border border-slate-200 bg-white/90 px-2 py-1 text-sm text-slate-900 shadow-sm hover:bg-white"
                                    @click="nextImage"
                                >
                                    ›
                                </button>
                            </div>

                            <div v-if="visibleImageAssets.length > 1" class="border-t border-slate-200 bg-white p-2">
                                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                                    <button
                                        v-for="(img, idx) in visibleImageAssets"
                                        :key="img.id"
                                        type="button"
                                        class="group relative aspect-square overflow-hidden rounded border p-0.5 disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="img.id === activeImage?.id ? 'border-slate-900' : 'border-slate-200 hover:border-slate-400'"
                                        :disabled="savingOrder"
                                        draggable="true"
                                        @dragstart="dragAssetId = img.id"
                                        @dragover.prevent
                                        @drop.prevent="onDropThumbnail(idx)"
                                        @click="activeImageId = img.id"
                                    >
                                        <img
                                            :src="img.view_url"
                                            :alt="img.filename"
                                            class="h-full w-full rounded object-cover"
                                            :class="(img.shopify_enabled ?? true) ? '' : 'opacity-40 grayscale'"
                                        />
                                        <div
                                            class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-1 bg-black/60 px-1 py-0.5"
                                        >
                                            <div class="truncate">
                                                <span
                                                    class="rounded-full border px-1.5 py-0.5 text-[11px] font-semibold"
                                                    :class="sourceBadgeClass(normalizeSourceKey(img.source))"
                                                >
                                                    {{ SOURCE_LABELS[normalizeSourceKey(img.source)] ?? img.source }}
                                                </span>
                                            </div>
                                            <button
                                                type="button"
                                                class="shrink-0 rounded bg-white/90 px-1 py-0.5 text-[11px] font-semibold text-slate-900 hover:bg-white disabled:opacity-50"
                                                :disabled="isTogglingShopify(img.id)"
                                                @click.stop="toggleShopifyEnabled(img)"
                                                :title="
                                                    (img.shopify_enabled ?? true)
                                                        ? 'Export to Shopify (click to disable)'
                                                        : 'Not exporting (click to enable)'
                                                "
                                            >
                                                {{ (img.shopify_enabled ?? true) ? 'On' : 'Off' }}
                                            </button>
                                        </div>
                                    </button>
                                </div>
                                <div v-if="savingOrder" class="mt-2 text-xs text-slate-500">Saving order…</div>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>


