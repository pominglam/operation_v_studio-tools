<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api, extractApiError } from '../../lib/api';
import { storePreorderManualSku } from '../../lib/storePreorderManualSku';

type StagedPhoto = { id: string; preview_url: string };

type ListingPreview = {
    title: string;
    suggested_sku: string;
    description_html: string | null;
    eta_date: string | null;
    retail_price_note: string | null;
    images: StagedPhoto[];
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'saved'): void;
}>();

const listingUrl = ref('');
const crawling = ref(false);
const uploading = ref(false);
const saving = ref(false);
const errorMessage = ref<string | null>(null);
const crawlNote = ref<string | null>(null);
const retailNote = ref<string | null>(null);
const productName = ref('');
const sku = ref('');
const skuTouched = ref(false);
const descriptionHtml = ref('');
const sellingPrice = ref('');
const depositPercent = ref('20');
const capQty = ref('');
const windowEndsOn = ref('');
const etaDate = ref('');
const photos = ref<StagedPhoto[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const dragFrom = ref<number | null>(null);
const backdropCloseArmed = ref(false);

const canSave = computed(
    () =>
        productName.value.trim() !== '' &&
        sku.value.trim() !== '' &&
        sellingPrice.value.trim() !== '' &&
        !props.busy &&
        !saving.value &&
        !crawling.value,
);

function resetForm(): void {
    listingUrl.value = '';
    crawling.value = false;
    uploading.value = false;
    saving.value = false;
    errorMessage.value = null;
    crawlNote.value = null;
    retailNote.value = null;
    productName.value = '';
    sku.value = '';
    skuTouched.value = false;
    descriptionHtml.value = '';
    sellingPrice.value = '';
    capQty.value = '';
    windowEndsOn.value = '';
    etaDate.value = '';
    photos.value = [];
}

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }
        resetForm();
        await loadDefaultDeposit();
    },
);

watch(productName, (name) => {
    if (!skuTouched.value) {
        sku.value = name.trim() === '' ? '' : storePreorderManualSku(name);
    }
});

async function loadDefaultDeposit(): Promise<void> {
    try {
        const res = await api.get<{ data: { default_deposit_percent: string } }>(
            '/api/v1/maintenance/opv-catalog-pricing',
        );
        const value = Number(res.data.data.default_deposit_percent);
        depositPercent.value = Number.isFinite(value) ? String(value) : '20';
    } catch {
        depositPercent.value = '20';
    }
}

async function crawlListing(): Promise<void> {
    const url = listingUrl.value.trim();
    if (url === '' || crawling.value) {
        return;
    }
    crawling.value = true;
    errorMessage.value = null;
    crawlNote.value = null;
    try {
        const res = await api.post<{ data: ListingPreview }>(
            '/api/v1/store-preorders/listing-preview',
            {
                url,
            },
        );
        applyPreview(res.data.data);
    } catch (err) {
        errorMessage.value = extractApiError(err);
        crawlNote.value = null;
        retailNote.value = null;
    } finally {
        crawling.value = false;
    }
}

function applyPreview(preview: ListingPreview): void {
    productName.value = preview.title;
    if (!skuTouched.value) {
        sku.value = preview.suggested_sku;
    }
    if (preview.description_html) {
        descriptionHtml.value = preview.description_html;
    }
    if (preview.eta_date && etaDate.value === '') {
        etaDate.value = preview.eta_date;
    }
    retailNote.value = preview.retail_price_note;
    photos.value = [...photos.value, ...preview.images];
    crawlNote.value = `Filled from ${listingUrl.value.trim()}. Set sell $ yourself.`;
}

async function uploadFiles(fileList: FileList | File[]): Promise<void> {
    const files = Array.from(fileList).filter((file) => file.type.startsWith('image/'));
    if (files.length === 0) {
        return;
    }
    uploading.value = true;
    errorMessage.value = null;
    try {
        const body = new FormData();
        for (const file of files) {
            body.append('files[]', file);
        }
        const res = await api.post<{ data: { images: StagedPhoto[] } }>(
            '/api/v1/store-preorders/listing-photos',
            body,
        );
        photos.value = [...photos.value, ...res.data.data.images];
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        uploading.value = false;
    }
}

function removePhoto(id: string): void {
    photos.value = photos.value.filter((photo) => photo.id !== id);
}

function onDropThumb(toIndex: number): void {
    const from = dragFrom.value;
    dragFrom.value = null;
    if (from === null || from === toIndex) {
        return;
    }
    const next = [...photos.value];
    const [moved] = next.splice(from, 1);
    if (moved === undefined) {
        return;
    }
    next.splice(toIndex, 0, moved);
    photos.value = next;
}

async function save(): Promise<void> {
    if (!canSave.value) {
        return;
    }
    saving.value = true;
    errorMessage.value = null;
    try {
        const capRaw = capQty.value.trim();
        await api.post('/api/v1/store-preorders/manual', {
            sku: sku.value.trim(),
            product_name: productName.value.trim(),
            description_html: descriptionHtml.value.trim() || null,
            selling_price: sellingPrice.value.trim(),
            deposit_percent: depositPercent.value.trim(),
            cap_qty: capRaw === '' ? null : Number(capRaw),
            window_ends_on: windowEndsOn.value || null,
            eta_date: etaDate.value || null,
            photo_ids: photos.value.map((photo) => photo.id),
        });
        emit('saved');
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        saving.value = false;
    }
}

function onBackdropPointerDown(event: PointerEvent): void {
    backdropCloseArmed.value = event.target === event.currentTarget;
}

function onBackdropClick(event: MouseEvent): void {
    const shouldClose = backdropCloseArmed.value && event.target === event.currentTarget;
    backdropCloseArmed.value = false;
    if (shouldClose) {
        emit('cancel');
    }
}

function onKeyDown(e: KeyboardEvent): void {
    if (!props.open) return;
    if (e.key === 'Escape') emit('cancel');
}

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            data-testid="store-preorder-add-offer-dialog"
            @pointerdown="onBackdropPointerDown"
            @click="onBackdropClick"
        >
            <div
                class="my-6 max-h-[min(90vh,56rem)] w-full max-w-3xl overflow-y-auto rounded-lg bg-white p-4 shadow-xl"
                data-testid="store-preorder-add-offer-panel"
                @pointerdown.stop
                @click.stop
            >
                <div class="text-sm font-semibold text-slate-900">Add store preorder</div>
                <p class="mt-1 text-sm text-slate-600">
                    Paste a product link to prefill name, description, photos, SKU, and ETA when a
                    crawler exists. Or fill the form yourself. Sell $ is always yours.
                </p>

                <div class="mt-3 flex flex-col gap-3">
                    <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                        Product URL (optional)
                        <div class="flex gap-2">
                            <input
                                v-model="listingUrl"
                                type="url"
                                placeholder="https://fuwafuwaland.ca/products/…"
                                class="h-9 flex-1 rounded-md border border-slate-300 px-2 text-sm font-normal text-slate-800"
                                data-testid="store-preorder-add-url"
                                @keydown.enter.prevent="crawlListing"
                                @paste="
                                    window.setTimeout(() => {
                                        void crawlListing();
                                    }, 0)
                                "
                            />
                            <button
                                type="button"
                                class="h-9 rounded-md bg-slate-900 px-3 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                :disabled="crawling || listingUrl.trim() === ''"
                                data-testid="store-preorder-add-crawl"
                                @click="crawlListing"
                            >
                                {{ crawling ? 'Crawling…' : 'Crawl' }}
                            </button>
                        </div>
                    </label>
                    <p v-if="crawlNote" class="text-sm text-emerald-800">{{ crawlNote }}</p>
                    <p v-if="retailNote" class="text-sm text-slate-600">
                        Retailer price: {{ retailNote }}
                    </p>
                    <p v-if="errorMessage" class="text-sm text-red-700">{{ errorMessage }}</p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            class="flex flex-col gap-1 text-xs font-medium text-slate-600 sm:col-span-2"
                        >
                            Product name
                            <input
                                v-model="productName"
                                type="text"
                                class="h-9 rounded-md border border-slate-300 px-2 text-sm font-normal text-slate-800"
                                data-testid="store-preorder-add-name"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                            SKU
                            <input
                                v-model="sku"
                                type="text"
                                class="h-9 rounded-md border border-slate-300 px-2 text-sm font-normal text-slate-800"
                                data-testid="store-preorder-add-sku"
                                @input="skuTouched = true"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                            Sell $
                            <input
                                v-model="sellingPrice"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                class="h-9 rounded-md border border-slate-300 px-2 text-sm font-normal text-slate-800"
                                data-testid="store-preorder-add-sell"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                            Deposit %
                            <input
                                v-model="depositPercent"
                                type="number"
                                min="1"
                                max="100"
                                step="1"
                                class="h-9 rounded-md border border-slate-300 px-2 text-sm font-normal text-slate-800"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                            Cap
                            <input
                                v-model="capQty"
                                type="number"
                                min="1"
                                max="9999"
                                placeholder="No cap"
                                class="h-9 rounded-md border border-slate-300 px-2 text-sm font-normal text-slate-800"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                            Closing
                            <input
                                v-model="windowEndsOn"
                                type="date"
                                class="h-9 rounded-md border border-slate-300 px-2 text-sm font-normal text-slate-800"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                            ETA
                            <input
                                v-model="etaDate"
                                type="date"
                                class="h-9 rounded-md border border-slate-300 px-2 text-sm font-normal text-slate-800"
                            />
                        </label>
                    </div>

                    <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                        Description
                        <textarea
                            v-model="descriptionHtml"
                            rows="4"
                            class="rounded-md border border-slate-300 px-2 py-1 text-sm font-normal text-slate-800"
                        />
                    </label>

                    <div class="space-y-2">
                        <div class="text-xs font-medium text-slate-600">Photos</div>
                        <div
                            class="flex min-h-24 flex-wrap gap-2 rounded-md border border-dashed border-slate-300 bg-slate-50 p-2"
                            @dragover.prevent
                            @drop.prevent="
                                uploadFiles(($event as DragEvent).dataTransfer?.files ?? [])
                            "
                        >
                            <button
                                v-for="(photo, index) in photos"
                                :key="photo.id"
                                type="button"
                                class="relative h-20 w-20 overflow-hidden rounded border border-slate-200 bg-white"
                                draggable="true"
                                @dragstart="dragFrom = index"
                                @dragend="dragFrom = null"
                                @dragover.prevent
                                @drop.prevent="onDropThumb(index)"
                            >
                                <img
                                    :src="photo.preview_url"
                                    alt=""
                                    class="h-full w-full object-cover"
                                />
                                <span
                                    class="absolute right-0.5 top-0.5 rounded bg-white/90 px-1 text-xs text-slate-700"
                                    @click.stop="removePhoto(photo.id)"
                                    >×</span
                                >
                            </button>
                            <button
                                type="button"
                                class="flex h-20 w-20 items-center justify-center rounded border border-slate-300 bg-white text-xs font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                                :disabled="uploading"
                                @click="fileInput?.click()"
                            >
                                {{ uploading ? '…' : 'Upload' }}
                            </button>
                        </div>
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/*"
                            multiple
                            class="hidden"
                            @change="
                                uploadFiles(($event.target as HTMLInputElement).files ?? []);
                                ($event.target as HTMLInputElement).value = '';
                            "
                        />
                    </div>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="h-9 rounded-md border border-slate-300 px-3 text-sm font-medium text-slate-800 hover:bg-slate-100"
                        :disabled="saving"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="h-9 rounded-md bg-slate-900 px-3 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="!canSave"
                        data-testid="store-preorder-add-save"
                        @click="save"
                    >
                        {{ saving ? 'Saving…' : 'Open & push to Shopify' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
