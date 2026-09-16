import { computed, ref, watch, type Ref } from 'vue';
import { api } from '../../lib/api';
import {
    SOURCE_LABELS,
    assetThumbUrl,
    isExporting,
    isImage,
    isManualUploadAsset,
    normalizeSourceKey,
    type ProductInfoAsset,
    type SourceKey,
} from './productInfoTypes';

type ImageSourceStat = {
    key: SourceKey;
    label: string;
    count: number;
    hidden: boolean;
};

const HIDDEN_SOURCES_STORAGE_PREFIX = 'plamod_drawer:hidden_image_sources:';

function assetSortKey(a: ProductInfoAsset): [number, number] {
    const order =
        typeof a.sort_order === 'number' && Number.isFinite(a.sort_order)
            ? a.sort_order
            : 1_000_000_000;
    return [order, a.id];
}

function reorder<T>(arr: T[], from: number, to: number): T[] {
    const copy = [...arr];
    const [item] = copy.splice(from, 1);
    if (item === undefined) return copy;
    copy.splice(to, 0, item);
    return copy;
}

export function useProductInfoPhotos(
    productId: () => string | null,
    assets: Ref<ProductInfoAsset[]>,
): {
    imageAssets: Ref<ProductInfoAsset[]>;
    visibleImageAssets: Ref<ProductInfoAsset[]>;
    imageSourceStats: Ref<ImageSourceStat[]>;
    hiddenImageSources: Ref<Set<SourceKey>>;
    activeImageId: Ref<number | null>;
    activeImage: Ref<ProductInfoAsset | null>;
    activeImageDebug: Ref<string | null>;
    pendingCount: Ref<number>;
    pending: Ref<boolean>;
    error: Ref<string | null>;
    message: Ref<string | null>;
    manualUploadBusy: Ref<boolean>;
    manualUploadDragOver: Ref<boolean>;
    manualUploadInput: Ref<HTMLInputElement | null>;
    thumbnailDragInProgress: Ref<boolean>;
    dedupingExact: Ref<boolean>;
    loadHiddenSourcesFromStorage: () => void;
    toggleImageSourceVisibility: (key: SourceKey) => void;
    showAllImageSources: () => void;
    openManualUploadPicker: () => void;
    onManualUploadFilesSelected: (e: Event) => Promise<void>;
    onManualUploadDragOver: (e: DragEvent) => void;
    onManualUploadDragEnter: (e: DragEvent) => void;
    onManualUploadDragLeave: (e: DragEvent) => void;
    onManualUploadDrop: (e: DragEvent) => Promise<void>;
    onDropThumbnail: (toIndex: number) => void;
    onThumbDragStart: (id: number) => void;
    onThumbDragEnd: () => void;
    toggleShopifyEnabled: (a: ProductInfoAsset) => Promise<void>;
    isTogglingShopify: (id: number) => boolean;
    shopifyToggleLabel: (a: ProductInfoAsset) => string;
    deleteManualImage: (a: ProductInfoAsset) => Promise<void>;
    isDeletingManualAsset: (id: number) => boolean;
    disableExactDuplicateImages: () => Promise<void>;
    sortExportingImagesBySource: () => void;
    prevImage: () => void;
    nextImage: () => void;
    selectImage: (id: number) => void;
    resetPhotoUi: () => void;
    assetThumbUrl: typeof assetThumbUrl;
    isManualUploadAsset: typeof isManualUploadAsset;
} {
    const hiddenImageSources = ref<Set<SourceKey>>(new Set());
    const activeImageId = ref<number | null>(null);
    const pendingCount = ref(0);
    const pending = computed(() => pendingCount.value > 0);
    const error = ref<string | null>(null);
    const message = ref<string | null>(null);
    const manualUploadBusy = ref(false);
    const manualUploadDragOver = ref(false);
    const manualUploadInput = ref<HTMLInputElement | null>(null);
    const thumbnailDragInProgress = ref(false);
    const dragAssetId = ref<number | null>(null);
    const togglingShopify = ref<Record<number, true>>({});
    const deletingManualAssetId = ref<number | null>(null);
    const dedupingExact = ref(false);

    let orderPersistTail: Promise<void> = Promise.resolve();
    let pendingOrder: { productId: string; assetIds: number[] } | null = null;

    const imageAssets = computed<ProductInfoAsset[]>(() => {
        return assets.value
            .filter(isImage)
            .slice()
            .sort((a, b) => {
                const [ao, aid] = assetSortKey(a);
                const [bo, bid] = assetSortKey(b);
                if (ao !== bo) return ao - bo;
                return aid - bid;
            });
    });

    const visibleImageAssets = computed<ProductInfoAsset[]>(() => {
        const hidden = hiddenImageSources.value;
        if (hidden.size === 0) return imageAssets.value;
        return imageAssets.value.filter((a) => !hidden.has(normalizeSourceKey(a.source)));
    });

    const imageSourceStats = computed<ImageSourceStat[]>(() => {
        const counts = new Map<SourceKey, number>();
        for (const a of imageAssets.value) {
            const k = normalizeSourceKey(a.source);
            counts.set(k, (counts.get(k) ?? 0) + 1);
        }
        const order: SourceKey[] = [
            'hlj',
            'newtype',
            'gundamhangar',
            'gundamplanet',
            'plamod',
            'manual_upload',
            'bandai',
            'other',
        ];
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

    const activeImage = computed<ProductInfoAsset | null>(() => {
        const id = activeImageId.value;
        if (id !== null) {
            const found = visibleImageAssets.value.find((a) => a.id === id);
            if (found) return found;
        }
        return visibleImageAssets.value[0] ?? null;
    });

    const activeImageDebug = computed<string | null>(() => {
        const img = activeImage.value;
        if (!img) return null;
        const parts: string[] = [];
        if (img.origin_width && img.origin_height)
            parts.push(`${img.origin_width}×${img.origin_height}`);
        if (img.checksum_sha256) parts.push(img.checksum_sha256.slice(0, 12));
        return parts.length > 0 ? parts.join(' · ') : null;
    });

    watch(
        () => visibleImageAssets.value.map((a) => a.id),
        (ids) => {
            const current = activeImageId.value;
            if (current !== null && ids.includes(current)) return;
            activeImageId.value = ids[0] ?? null;
        },
    );

    function trackSave(work: () => Promise<void>): void {
        pendingCount.value += 1;
        void work()
            .catch((e: unknown) => {
                error.value = e instanceof Error ? e.message : 'Failed to save photo changes.';
            })
            .finally(() => {
                pendingCount.value = Math.max(0, pendingCount.value - 1);
            });
    }

    function applyImageOrderLocally(orderedImages: ProductInfoAsset[]): void {
        const idToOrder = new Map<number, number>();
        for (let i = 0; i < orderedImages.length; i++) {
            const a = orderedImages[i];
            if (!a) continue;
            idToOrder.set(a.id, i + 1);
        }
        const nonImages = assets.value.filter((a) => !isImage(a));
        const updatedImages = orderedImages.map((a) => ({
            ...a,
            sort_order: idToOrder.get(a.id) ?? a.sort_order ?? null,
        }));
        assets.value = [...updatedImages, ...nonImages];
    }

    function persistImageOrderInBackground(orderedImages: ProductInfoAsset[]): void {
        const id = productId();
        if (!id) return;
        pendingOrder = { productId: id, assetIds: orderedImages.map((a) => a.id) };
        orderPersistTail = orderPersistTail.then(flushPendingImageOrder, flushPendingImageOrder);
        trackSave(() => orderPersistTail);
    }

    async function flushPendingImageOrder(): Promise<void> {
        const job = pendingOrder;
        pendingOrder = null;
        if (!job) return;
        await api.put(`/api/v1/products/${job.productId}/assets/order`, {
            asset_ids: job.assetIds,
        });
    }

    function storageKeyForHiddenSources(): string | null {
        const id = (productId() ?? '').trim();
        if (!id) return null;
        return `${HIDDEN_SOURCES_STORAGE_PREFIX}${id}`;
    }

    function loadHiddenSourcesFromStorage(): void {
        const key = storageKeyForHiddenSources();
        if (!key) return;
        try {
            const raw = window.localStorage.getItem(key);
            const list = raw ? (JSON.parse(raw) as unknown) : null;
            if (!Array.isArray(list)) return;
            const next = new Set<SourceKey>();
            for (const v of list) {
                if (typeof v === 'string') next.add(normalizeSourceKey(v));
            }
            hiddenImageSources.value = next;
        } catch {
            // ignore
        }
    }

    function persistHiddenSourcesToStorage(): void {
        const key = storageKeyForHiddenSources();
        if (!key) return;
        try {
            window.localStorage.setItem(key, JSON.stringify(Array.from(hiddenImageSources.value)));
        } catch {
            // ignore
        }
    }

    function toggleImageSourceVisibility(key: SourceKey): void {
        const next = new Set(hiddenImageSources.value);
        if (next.has(key)) next.delete(key);
        else next.add(key);
        hiddenImageSources.value = next;
        persistHiddenSourcesToStorage();
        if (hiddenImageSources.value.has(key)) {
            disableImagesForSource(key);
        }
    }

    function showAllImageSources(): void {
        hiddenImageSources.value = new Set();
        persistHiddenSourcesToStorage();
    }

    function disableByIds(ids: number[]): void {
        if (ids.length === 0) return;
        const set = new Set(ids);
        const flagged = imageAssets.value.map((x) =>
            set.has(x.id) ? { ...x, shopify_enabled: false } : x,
        );
        const enabled = flagged.filter((a) => isExporting(a));
        const disabled = flagged.filter((a) => !isExporting(a));
        const ordered = [...enabled, ...disabled];
        applyImageOrderLocally(ordered);
        persistImageOrderInBackground(ordered);
        const id = productId();
        if (!id) return;
        trackSave(async () => {
            await api.patch(`/api/v1/products/${id}/assets/shopify-enabled`, {
                shopify_enabled: false,
                ids,
            });
        });
    }

    function disableImagesForSource(key: SourceKey): void {
        disableByIds(
            imageAssets.value
                .filter((a) => normalizeSourceKey(a.source) === key && isExporting(a))
                .map((a) => a.id),
        );
    }

    function openManualUploadPicker(): void {
        if (manualUploadBusy.value) return;
        message.value = null;
        error.value = null;
        manualUploadInput.value?.click();
    }

    async function uploadManualFiles(files: File[]): Promise<void> {
        const id = productId();
        if (!id || files.length === 0) return;
        const imageFiles = files.filter((f) => (f.type ?? '').toLowerCase().startsWith('image/'));
        if (imageFiles.length === 0) {
            error.value = 'Please drop image files only.';
            return;
        }
        manualUploadBusy.value = true;
        pendingCount.value += 1;
        message.value = null;
        error.value = null;
        try {
            const form = new FormData();
            for (const f of imageFiles) form.append('files[]', f);
            const res = await api.post<{
                ok: boolean;
                data: { created: number; assets?: ProductInfoAsset[] };
            }>(`/api/v1/products/${id}/assets/manual-upload`, form, {
                headers: { 'Content-Type': 'multipart/form-data' },
                validateStatus: () => true,
            });
            if (res.status !== 201) {
                throw new Error(`Upload failed (HTTP ${res.status}).`);
            }
            const created = res.data.data?.assets ?? [];
            if (created.length > 0) {
                const existingIds = new Set(assets.value.map((a) => a.id));
                assets.value = [...assets.value, ...created.filter((a) => !existingIds.has(a.id))];
            }
            message.value = `Uploaded ${created.length || imageFiles.length} image(s).`;
        } catch (e2: unknown) {
            error.value = e2 instanceof Error ? e2.message : 'Failed to upload images.';
        } finally {
            manualUploadBusy.value = false;
            pendingCount.value = Math.max(0, pendingCount.value - 1);
        }
    }

    async function onManualUploadFilesSelected(e: Event): Promise<void> {
        const input = e.target as HTMLInputElement;
        const files = Array.from(input.files ?? []);
        input.value = '';
        await uploadManualFiles(files);
    }

    function onManualUploadDragOver(e: DragEvent): void {
        e.preventDefault();
        if (manualUploadBusy.value || thumbnailDragInProgress.value) return;
        manualUploadDragOver.value = true;
    }

    function onManualUploadDragEnter(e: DragEvent): void {
        e.preventDefault();
        if (manualUploadBusy.value || thumbnailDragInProgress.value) return;
        manualUploadDragOver.value = true;
    }

    function onManualUploadDragLeave(e: DragEvent): void {
        e.preventDefault();
        if ((e.currentTarget as HTMLElement | null) === e.target) {
            manualUploadDragOver.value = false;
        }
    }

    async function onManualUploadDrop(e: DragEvent): Promise<void> {
        e.preventDefault();
        manualUploadDragOver.value = false;
        if (manualUploadBusy.value) return;
        if (thumbnailDragInProgress.value || dragAssetId.value !== null) return;
        await uploadManualFiles(Array.from(e.dataTransfer?.files ?? []));
    }

    function onThumbDragStart(id: number): void {
        dragAssetId.value = id;
        thumbnailDragInProgress.value = true;
    }

    function onThumbDragEnd(): void {
        dragAssetId.value = null;
        thumbnailDragInProgress.value = false;
    }

    function onDropThumbnail(toIndex: number): void {
        const fromId = dragAssetId.value;
        dragAssetId.value = null;
        thumbnailDragInProgress.value = false;
        if (fromId === null) return;
        const from = visibleImageAssets.value.findIndex((a) => a.id === fromId);
        if (from < 0 || from === toIndex) return;
        const visibleIds = new Set(visibleImageAssets.value.map((a) => a.id));
        const hidden = imageAssets.value.filter((a) => !visibleIds.has(a.id));
        const reordered = [
            ...reorder(visibleImageAssets.value, from, toIndex),
            ...hidden,
        ];
        applyImageOrderLocally(reordered);
        persistImageOrderInBackground(reordered);
    }

    function orderImagesAfterShopifyToggle(
        images: ProductInfoAsset[],
        id: number,
        enabled: boolean,
    ): ProductInfoAsset[] | null {
        const tgt = images.find((x) => x.id === id) ?? null;
        if (!tgt) return null;
        const rest = images.filter((x) => x.id !== id);
        const exporting = rest.filter((x) => isExporting(x));
        const notExporting = rest.filter((x) => !isExporting(x));
        return enabled
            ? [...exporting, { ...tgt, shopify_enabled: true }, ...notExporting]
            : [...exporting, ...notExporting, { ...tgt, shopify_enabled: false }];
    }

    async function toggleShopifyEnabled(a: ProductInfoAsset): Promise<void> {
        if (togglingShopify.value[a.id] === true) return;
        const next = !isExporting(a);
        const snapshot = assets.value.slice();
        togglingShopify.value = { ...togglingShopify.value, [a.id]: true };
        error.value = null;
        pendingCount.value += 1;
        const flagged = imageAssets.value.map((x) =>
            x.id === a.id ? { ...x, shopify_enabled: next } : x,
        );
        const ordered = orderImagesAfterShopifyToggle(flagged, a.id, next);
        if (ordered) {
            applyImageOrderLocally(ordered);
            persistImageOrderInBackground(ordered);
        }
        try {
            await api.patch(`/api/v1/product-assets/${a.id}/shopify-enabled`, {
                shopify_enabled: next,
            });
        } catch {
            assets.value = snapshot;
            const rollback = snapshot.filter(isImage);
            persistImageOrderInBackground(rollback);
            error.value = 'Failed to update Shopify export setting.';
        } finally {
            pendingCount.value = Math.max(0, pendingCount.value - 1);
            const { [a.id]: _omit, ...rest } = togglingShopify.value;
            togglingShopify.value = rest;
        }
    }

    function isTogglingShopify(id: number): boolean {
        return togglingShopify.value[id] === true;
    }

    function shopifyToggleLabel(a: ProductInfoAsset): string {
        const exporting = isExporting(a);
        return isManualUploadAsset(a)
            ? exporting
                ? 'On'
                : 'Off'
            : exporting
              ? 'Exporting'
              : 'Not exporting';
    }

    function isDeletingManualAsset(id: number): boolean {
        return deletingManualAssetId.value === id;
    }

    async function deleteManualImage(a: ProductInfoAsset): Promise<void> {
        if (!isManualUploadAsset(a) || isDeletingManualAsset(a.id)) return;
        if (!window.confirm(`Delete the manually uploaded photo "${a.filename}"? This cannot be undone.`)) {
            return;
        }
        deletingManualAssetId.value = a.id;
        error.value = null;
        const snapshot = assets.value.slice();
        assets.value = assets.value.filter((x) => x.id !== a.id);
        message.value = 'Deleted manual upload image.';
        pendingCount.value += 1;
        try {
            await api.delete(`/api/v1/product-assets/${a.id}`);
        } catch {
            assets.value = snapshot;
            error.value = 'Failed to delete manual upload image.';
            message.value = null;
        } finally {
            deletingManualAssetId.value = null;
            pendingCount.value = Math.max(0, pendingCount.value - 1);
        }
    }

    async function disableExactDuplicateImages(): Promise<void> {
        const toDisable: number[] = [];
        const groups = new Map<string, ProductInfoAsset[]>();
        for (const a of imageAssets.value) {
            const sha = (a.checksum_sha256 ?? '').trim();
            if (!sha) continue;
            const list = groups.get(sha) ?? [];
            list.push(a);
            groups.set(sha, list);
        }
        for (const list of groups.values()) {
            if (list.length <= 1) continue;
            for (const a of list.slice(1)) {
                if (isExporting(a)) toDisable.push(a.id);
            }
        }
        if (toDisable.length === 0) {
            message.value = 'No exact duplicates found.';
            return;
        }
        dedupingExact.value = true;
        disableByIds(toDisable);
        message.value = `Disabled ${toDisable.length} exact duplicate(s).`;
        dedupingExact.value = false;
    }

    function sortExportingImagesBySource(): void {
        const current = imageAssets.value.slice();
        if (current.length <= 1) return;
        const enabled = current.filter((a) => isExporting(a));
        const disabled = current.filter((a) => !isExporting(a));
        const order: SourceKey[] = ['plamod', 'hlj', 'newtype', 'gundamhangar', 'gundamplanet'];
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
        for (const k of order) sortedEnabled.push(...(buckets.get(k) ?? []));
        sortedEnabled.push(...otherEnabled);
        const ordered = [...sortedEnabled, ...disabled];
        applyImageOrderLocally(ordered);
        persistImageOrderInBackground(ordered);
        message.value = 'Image order saved.';
    }

    function prevImage(): void {
        const list = visibleImageAssets.value;
        if (list.length <= 1) return;
        const idx = list.findIndex((a) => a.id === activeImage.value?.id);
        const next = (idx - 1 + list.length) % list.length;
        activeImageId.value = list[next]?.id ?? null;
    }

    function nextImage(): void {
        const list = visibleImageAssets.value;
        if (list.length <= 1) return;
        const idx = list.findIndex((a) => a.id === activeImage.value?.id);
        const next = (idx + 1) % list.length;
        activeImageId.value = list[next]?.id ?? null;
    }

    function selectImage(id: number): void {
        activeImageId.value = id;
    }

    function resetPhotoUi(): void {
        hiddenImageSources.value = new Set();
        activeImageId.value = null;
        error.value = null;
        message.value = null;
        pendingCount.value = 0;
        manualUploadBusy.value = false;
        manualUploadDragOver.value = false;
        thumbnailDragInProgress.value = false;
        dragAssetId.value = null;
        togglingShopify.value = {};
        deletingManualAssetId.value = null;
        dedupingExact.value = false;
    }

    return {
        imageAssets,
        visibleImageAssets,
        imageSourceStats,
        hiddenImageSources,
        activeImageId,
        activeImage,
        activeImageDebug,
        pendingCount,
        pending,
        error,
        message,
        manualUploadBusy,
        manualUploadDragOver,
        manualUploadInput,
        thumbnailDragInProgress,
        dedupingExact,
        loadHiddenSourcesFromStorage,
        toggleImageSourceVisibility,
        showAllImageSources,
        openManualUploadPicker,
        onManualUploadFilesSelected,
        onManualUploadDragOver,
        onManualUploadDragEnter,
        onManualUploadDragLeave,
        onManualUploadDrop,
        onDropThumbnail,
        onThumbDragStart,
        onThumbDragEnd,
        toggleShopifyEnabled,
        isTogglingShopify,
        shopifyToggleLabel,
        deleteManualImage,
        isDeletingManualAsset,
        disableExactDuplicateImages,
        sortExportingImagesBySource,
        prevImage,
        nextImage,
        selectImage,
        resetPhotoUi,
        assetThumbUrl,
        isManualUploadAsset,
    };
}
