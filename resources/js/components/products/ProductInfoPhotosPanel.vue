<script setup lang="ts">
import { watch } from 'vue';
import {
    SOURCE_LABELS,
    normalizeSourceKey,
    sourceBadgeClass,
    type ProductInfoAsset,
} from './productInfoTypes';
import { useProductInfoPhotos } from './useProductInfoPhotos';

const assets = defineModel<ProductInfoAsset[]>('assets', { required: true });
const props = defineProps<{
    productId: string | null;
}>();
const emit = defineEmits<{
    error: [value: string | null];
    message: [value: string | null];
    pending: [value: boolean];
}>();

const {
    imageAssets,
    visibleImageAssets,
    imageSourceStats,
    hiddenImageSources,
    activeImage,
    activeImageDebug,
    pendingCount,
    pending,
    error,
    message,
    manualUploadBusy,
    manualUploadDragOver,
    manualUploadInput,
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
} = useProductInfoPhotos(() => props.productId, assets);

watch(error, (v) => emit('error', v), { flush: 'sync' });
watch(message, (v) => {
    if (v) emit('message', v);
}, { flush: 'sync' });
watch(pending, (v) => emit('pending', v), { immediate: true });
watch(
    () => props.productId,
    () => {
        loadHiddenSourcesFromStorage();
    },
    { immediate: true },
);

defineExpose({
    resetPhotoUi,
    loadHiddenSourcesFromStorage,
});
</script>

<template>
    <div
        class="rounded-md border border-slate-200 bg-white p-3 transition"
        data-testid="manual-image-dropzone"
        :class="manualUploadDragOver ? 'border-blue-400 bg-blue-50/60' : ''"
        @dragenter="onManualUploadDragEnter"
        @dragover="onManualUploadDragOver"
        @dragleave="onManualUploadDragLeave"
        @drop="onManualUploadDrop"
    >
        <div class="flex items-center justify-between gap-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Photos</div>
            <div class="text-xs text-slate-500">
                {{ visibleImageAssets.length }} shown · {{ imageAssets.length }} total
            </div>
        </div>
        <div class="mt-1 text-xs text-slate-600">
            Drag to reorder (Shopify export follows this order). Toggle export per photo (color vs
            grayed out).
        </div>
        <div
            v-if="pending"
            class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900"
            data-testid="photo-saves-pending"
        >
            Saving photo changes… ({{ pendingCount }} in progress)
        </div>
        <div
            v-if="message"
            class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-800"
        >
            {{ message }}
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-2">
            <input
                ref="manualUploadInput"
                class="hidden"
                type="file"
                accept="image/*"
                multiple
                @change="onManualUploadFilesSelected"
            />
            <template v-if="imageAssets.length > 0">
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
            </template>
            <div class="grow" />
            <button
                type="button"
                class="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                :disabled="manualUploadBusy"
                @click="openManualUploadPicker"
                title="Upload images from your computer (source: Manual upload)."
            >
                {{ manualUploadBusy ? 'Uploading…' : 'Upload images' }}
            </button>
            <button
                v-if="imageAssets.length > 0"
                type="button"
                class="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                :disabled="dedupingExact"
                @click="disableExactDuplicateImages"
                title="Exact duplicates are detected by checksum (identical bytes). Duplicates will be disabled for Shopify export."
            >
                {{ dedupingExact ? 'Disabling…' : 'Disable exact duplicates' }}
            </button>
            <button
                v-if="imageAssets.length > 0"
                type="button"
                class="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-900 hover:bg-slate-50"
                @click="sortExportingImagesBySource"
                title="Reorders exporting (On) photos by source: Plamod → HLJ → Newtype → GundamHangar → GundamPlanet."
            >
                Sort exporting by source
            </button>
        </div>
        <button
            type="button"
            class="mt-2 w-full rounded-md border-2 border-dashed px-3 py-4 text-center text-sm transition disabled:cursor-not-allowed disabled:opacity-60"
            :class="
                manualUploadDragOver
                    ? 'border-blue-500 bg-blue-50 text-blue-900'
                    : 'border-slate-300 bg-slate-50 text-slate-700 hover:border-slate-400 hover:bg-slate-100'
            "
            :disabled="manualUploadBusy"
            @click="openManualUploadPicker"
        >
            {{ manualUploadBusy ? 'Uploading…' : 'Drop images here, or click to upload' }}
        </button>

        <div v-if="imageAssets.length === 0" class="mt-2 text-sm text-slate-600">
            No images found yet.
        </div>

        <div v-else class="mt-2 rounded-md border border-slate-200 bg-slate-50">
            <div class="relative">
                <img
                    v-if="activeImage"
                    data-testid="photo-hero-image"
                    :key="activeImage.id"
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
                        {{
                            SOURCE_LABELS[normalizeSourceKey(activeImage.source)] ??
                            activeImage.source
                        }}
                    </div>
                    <button
                        v-if="activeImage"
                        type="button"
                        data-testid="active-shopify-export-toggle"
                        class="rounded-full px-2 py-0.5 text-xs font-semibold transition disabled:opacity-50"
                        :class="
                            (activeImage.shopify_enabled ?? true)
                                ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200'
                                : 'bg-slate-200 text-slate-700 hover:bg-slate-300'
                        "
                        :disabled="isTogglingShopify(activeImage.id)"
                        @click="toggleShopifyEnabled(activeImage)"
                    >
                        {{ shopifyToggleLabel(activeImage) }}
                    </button>
                </div>

                <button
                    v-if="activeImage && isManualUploadAsset(activeImage)"
                    type="button"
                    data-testid="delete-manual-photo"
                    class="absolute right-2 top-2 rounded-full bg-rose-600 px-2 py-0.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:opacity-50"
                    :disabled="
                        isDeletingManualAsset(activeImage.id) || isTogglingShopify(activeImage.id)
                    "
                    @click="deleteManualImage(activeImage)"
                >
                    {{ isDeletingManualAsset(activeImage.id) ? 'Deleting…' : 'Delete' }}
                </button>

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

            <div
                v-if="visibleImageAssets.length > 1"
                class="border-t border-slate-200 bg-white p-2"
            >
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <button
                        v-for="(img, idx) in visibleImageAssets"
                        :key="img.id"
                        type="button"
                        class="group relative aspect-square overflow-hidden rounded border p-0.5 disabled:cursor-not-allowed disabled:opacity-50"
                        :class="
                            img.id === activeImage?.id
                                ? 'border-slate-900'
                                : 'border-slate-200 hover:border-slate-400'
                        "
                        draggable="true"
                        @dragstart="onThumbDragStart(img.id)"
                        @dragend="onThumbDragEnd"
                        @dragover.prevent.stop
                        @drop.prevent.stop="onDropThumbnail(idx)"
                        @click="selectImage(img.id)"
                    >
                        <img
                            data-testid="photo-grid-thumb"
                            loading="lazy"
                            :src="assetThumbUrl(img)"
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
                                    {{
                                        SOURCE_LABELS[normalizeSourceKey(img.source)] ?? img.source
                                    }}
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
            </div>
        </div>
    </div>
</template>
