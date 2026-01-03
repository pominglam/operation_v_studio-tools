<script setup lang="ts">
import { computed, ref } from 'vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import BulkUpdateDialog from './BulkUpdateDialog.vue';
import { formatMoney2 } from '../../lib/money';

export type ProductRow = {
    id: string; // uuid
    sku: string;
    barcode: string | null;
    description: string;
    handle: string | null;
    type: string | null;
    vendor: string | null;
    published_on_shopify?: boolean;
    price: string | null;
    selling_price?: string | null;
    pdp?: {
        has_description: boolean;
        plamod_image_count: number;
    };
    order: number | null;
    filled: number | null;
    available: number | null;
    extended: string | null;
};

function isMissingBarcode(p: ProductRow): boolean {
    return !p.barcode || p.barcode.trim() === '';
}

function isMissingSellingPrice(p: ProductRow): boolean {
    return !p.selling_price || String(p.selling_price).trim() === '';
}

function isMissingPdpDescription(p: ProductRow): boolean {
    return !(p.pdp?.has_description ?? false);
}

function isMissingPdpImages(p: ProductRow): boolean {
    return (p.pdp?.plamod_image_count ?? 0) <= 0;
}

export type UpdateProductPayload = {
    sku: string;
    barcode: string | null;
    description: string;
    handle: string | null;
    type: string | null;
    vendor: string | null;
    price: string | null;
    order: number | null;
    filled: number | null;
    available: number | null;
    extended: string | null;
};

export type BulkUpdateProductChanges = {
    sku?: string;
    barcode?: string | null;
    description?: string;
    handle?: string | null;
    type?: string | null;
    vendor?: string | null;
    published_on_shopify?: boolean;
    price?: string | null;
    order?: number | null;
    filled?: number | null;
    // available intentionally omitted for now; add when needed
    extended?: string | null;
};

export type ProductSortKey =
    | 'sku'
    | 'barcode'
    | 'description'
    | 'type'
    | 'vendor'
    | 'price'
    | 'order'
    | 'filled'
    | 'available'
    | 'extended';

const props = defineProps<{
    loading: boolean;
    products: ProductRow[];
    sortBy: ProductSortKey;
    sortDir: 'asc' | 'desc';
    onSortChange: (sortBy: ProductSortKey) => void;
    onRefresh: () => Promise<void>;
    onBulkDelete: (ids: string[]) => Promise<number>;
    onBulkUpdate: (ids: string[], changes: BulkUpdateProductChanges) => Promise<number>;
    onBulkRenamePlamodAssets: (ids: string[]) => Promise<number>;
    onUpdate: (id: string, payload: UpdateProductPayload) => Promise<void>;
    onOpenPlamod: (id: string) => void;
    vendorOptions?: string[];
}>();

const vendorChoices = computed<string[]>(() => {
    const base = (props.vendorOptions ?? []).map((v) => v.trim()).filter((v) => v !== '');
    const current = draft.value?.vendor?.trim() ?? '';
    const merged = current !== '' ? [...base, current] : base;
    return Array.from(new Set(merged)).sort((a, b) => a.localeCompare(b));
});

const selected = ref<Set<string>>(new Set());
const bulkDeleting = ref(false);
const bulkUpdating = ref(false);
const bulkMessage = ref<string | null>(null);
const bulkError = ref<string | null>(null);
const confirmBulkDeleteOpen = ref(false);
const confirmBulkDeleteCount = ref(0);
const bulkUpdateOpen = ref(false);

const editingId = ref<string | null>(null);
const draft = ref<UpdateProductPayload | null>(null);
const saving = ref(false);
const rowError = ref<string | null>(null);

const allSelected = computed(
    () => props.products.length > 0 && selected.value.size === props.products.length,
);

function sortLabel(key: ProductSortKey): string {
    const map: Record<ProductSortKey, string> = {
        sku: 'SKU',
        barcode: 'Barcode',
        description: 'Name',
        type: 'Type',
        vendor: 'Vendor',
        price: 'Unit cost',
        order: 'Ordered',
        filled: 'Shipped',
        available: 'Available',
        extended: 'Total cost',
    };
    return map[key];
}

function sortIndicator(key: ProductSortKey): string {
    if (props.sortBy !== key) return '';
    return props.sortDir === 'asc' ? ' ▲' : ' ▼';
}

function sortHeaderClass(key: ProductSortKey): string {
    return props.sortBy === key ? 'text-slate-900' : 'text-slate-600';
}

function syncSelection(): void {
    const allowed = new Set(props.products.map((p) => p.id));
    selected.value = new Set(Array.from(selected.value).filter((id) => allowed.has(id)));
}

function toggleAll(checked: boolean): void {
    if (checked) {
        selected.value = new Set(props.products.map((p) => p.id));
        return;
    }
    selected.value = new Set();
}

function toggleOne(id: string, checked: boolean): void {
    const next = new Set(selected.value);
    if (checked) next.add(id);
    else next.delete(id);
    selected.value = next;
}

function requestBulkDelete(): void {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    confirmBulkDeleteCount.value = ids.length;
    confirmBulkDeleteOpen.value = true;
}

function requestBulkUpdate(): void {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkUpdateOpen.value = true;
}

async function confirmBulkDelete(): Promise<void> {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkDeleting.value = true;

    try {
        const deleted = await props.onBulkDelete(ids);
        bulkMessage.value = `Deleted ${deleted} product(s).`;
        selected.value = new Set();
        await props.onRefresh();
        syncSelection();
    } catch (e: unknown) {
        bulkError.value = 'Failed to delete selected products.';
    } finally {
        bulkDeleting.value = false;
        confirmBulkDeleteOpen.value = false;
    }
}

async function confirmBulkUpdate(payload: { changes: BulkUpdateProductChanges; renamePlamodAssets: boolean }): Promise<void> {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkUpdating.value = true;
    try {
        const changes = payload.changes;
        const doRename = payload.renamePlamodAssets;

        let parts: string[] = [];
        if (Object.keys(changes).length > 0) {
            const updated = await props.onBulkUpdate(ids, changes);
            parts.push(`Updated ${updated} product(s).`);
        }
        if (doRename) {
            const renamed = await props.onBulkRenamePlamodAssets(ids);
            parts.push(`Renamed ${renamed} Plamod asset(s).`);
        }
        if (parts.length === 0) {
            parts = ['No changes selected.'];
        }
        bulkMessage.value = parts.join(' ');
        await props.onRefresh();
        syncSelection();
        bulkUpdateOpen.value = false;
    } catch (e: unknown) {
        bulkError.value = 'Failed to update selected products.';
    } finally {
        bulkUpdating.value = false;
    }
}

function startEdit(p: ProductRow): void {
    rowError.value = null;
    editingId.value = p.id;
    draft.value = {
        sku: p.sku,
        barcode: p.barcode,
        description: p.description,
        handle: p.handle,
        type: p.type,
        vendor: p.vendor,
        price: p.price,
        order: p.order,
        filled: p.filled,
        available: p.available,
        extended: p.extended,
    };
}

function cancelEdit(): void {
    editingId.value = null;
    draft.value = null;
    rowError.value = null;
}

async function saveEdit(): Promise<void> {
    if (!editingId.value || !draft.value) return;

    rowError.value = null;
    if (!draft.value.sku.trim() || !draft.value.description.trim()) {
        rowError.value = 'SKU and Name are required.';
        return;
    }

    saving.value = true;
    try {
        await props.onUpdate(editingId.value, {
            sku: draft.value.sku.trim(),
            barcode: draft.value.barcode?.trim() || null,
            description: draft.value.description.trim(),
            handle: draft.value.handle?.trim() || null,
            type: draft.value.type?.trim() || null,
            vendor: draft.value.vendor?.trim() || null,
            price: draft.value.price?.trim() || null,
            order: draft.value.order,
            filled: draft.value.filled,
            available: draft.value.available,
            extended: draft.value.extended?.trim() || null,
        });
        await props.onRefresh();
        cancelEdit();
    } catch (e: unknown) {
        rowError.value = 'Failed to save changes (check SKU uniqueness and required fields).';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading…</div>

        <div v-else class="overflow-x-auto">
            <div
                v-if="selected.size > 0"
                class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm"
            >
                <div class="text-slate-700">
                    <span class="font-semibold">{{ selected.size }}</span> selected
                </div>
                <div class="flex items-center gap-2">
                    <button
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkUpdating"
                        @click="selected = new Set()"
                    >
                        Clear
                    </button>
                    <button
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkUpdating"
                        @click="requestBulkUpdate"
                    >
                        {{ bulkUpdating ? 'Updating…' : 'Update selected' }}
                    </button>
                    <button
                        class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-rose-700 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkUpdating"
                        @click="requestBulkDelete"
                    >
                        {{ bulkDeleting ? 'Deleting…' : 'Delete selected' }}
                    </button>
                </div>
            </div>

            <div
                v-if="bulkError"
                class="m-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ bulkError }}
            </div>
            <div
                v-if="bulkMessage"
                class="m-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ bulkMessage }}
            </div>
            <div
                v-if="rowError"
                class="m-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ rowError }}
            </div>

            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-xs font-semibold tracking-wide text-slate-600">
                        <th class="w-12 px-4 py-3">
                            <input
                                class="h-4 w-4 rounded border-slate-300"
                                type="checkbox"
                                :checked="allSelected"
                                :disabled="products.length === 0"
                                @change="toggleAll(($event.target as HTMLInputElement).checked)"
                            />
                        </th>
                        <th class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('description')"
                                @click="onSortChange('description')"
                            >
                                Product{{ sortIndicator('description') }}
                            </button>
                        </th>
                        <th class="px-4 py-3">Handle</th>
                        <th class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('type')"
                                @click="onSortChange('type')"
                            >
                                {{ sortLabel('type') }}{{ sortIndicator('type') }}
                            </button>
                        </th>
                        <th class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('vendor')"
                                @click="onSortChange('vendor')"
                            >
                                {{ sortLabel('vendor') }}{{ sortIndicator('vendor') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('price')"
                                @click="onSortChange('price')"
                            >
                                {{ sortLabel('price') }}{{ sortIndicator('price') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('order')"
                                @click="onSortChange('order')"
                            >
                                {{ sortLabel('order') }}{{ sortIndicator('order') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('filled')"
                                @click="onSortChange('filled')"
                            >
                                {{ sortLabel('filled') }}{{ sortIndicator('filled') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('available')"
                                @click="onSortChange('available')"
                            >
                                {{ sortLabel('available') }}{{ sortIndicator('available') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('extended')"
                                @click="onSortChange('extended')"
                            >
                                {{ sortLabel('extended') }}{{ sortIndicator('extended') }}
                            </button>
                        </th>
                        <th class="px-4 py-3">Info</th>
                        <th class="px-4 py-3">Published on Shopify</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="products.length === 0">
                        <td class="px-4 py-4 text-slate-600" colspan="13">
                            No products yet. Import a CSV or add one manually above.
                        </td>
                    </tr>

                    <tr v-for="p in products" :key="p.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <input
                                class="h-4 w-4 rounded border-slate-300"
                                type="checkbox"
                                :checked="selected.has(p.id)"
                                @change="
                                    toggleOne(p.id, ($event.target as HTMLInputElement).checked)
                                "
                            />
                        </td>

                        <td class="px-4 py-3">
                            <template v-if="editingId === p.id">
                                <div class="flex flex-col gap-2">
                                    <input
                                        v-model="draft!.description"
                                        class="w-[28rem] rounded-md border border-slate-200 px-2 py-1 text-sm"
                                        type="text"
                                    />
                                    <div class="flex flex-wrap gap-2">
                                        <input
                                            v-model="draft!.sku"
                                            class="w-40 rounded-md border border-slate-200 px-2 py-1 font-mono text-xs"
                                            type="text"
                                        />
                                        <input
                                            v-model="draft!.barcode"
                                            class="w-44 rounded-md border border-slate-200 px-2 py-1 font-mono text-xs"
                                            type="text"
                                        />
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <div class="max-w-[28rem] truncate font-medium text-slate-900" :title="p.description">
                                    {{ p.description }}
                                </div>
                                <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-slate-600">
                                    <span class="font-mono">{{ p.sku }}</span>
                                    <span class="font-mono">{{ p.barcode ?? '—' }}</span>
                                </div>
                            </template>
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model="draft!.handle"
                                    class="w-[18rem] rounded-md border border-slate-200 px-2 py-1 text-sm"
                                    type="text"
                                />
                            </template>
                            <template v-else>{{ p.handle ?? '—' }}</template>
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model="draft!.type"
                                    class="w-24 rounded-md border border-slate-200 px-2 py-1 text-sm"
                                    type="text"
                                />
                            </template>
                            <template v-else>{{ p.type ?? '—' }}</template>
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <select
                                    v-model="draft!.vendor"
                                    class="w-36 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm"
                                >
                                    <option :value="null">—</option>
                                    <option v-for="v in vendorChoices" :key="v" :value="v">{{ v }}</option>
                                </select>
                            </template>
                            <template v-else>{{ p.vendor ?? '—' }}</template>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model="draft!.price"
                                    class="w-24 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="text"
                                />
                            </template>
                            <template v-else>{{ p.price ? formatMoney2(p.price) : '—' }}</template>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model.number="draft!.order"
                                    class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="number"
                                    min="0"
                                />
                            </template>
                            <template v-else>{{ p.order ?? '—' }}</template>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model.number="draft!.filled"
                                    class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="number"
                                    min="0"
                                />
                            </template>
                            <template v-else>{{ p.filled ?? '—' }}</template>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model.number="draft!.available"
                                    class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="number"
                                    min="0"
                                />
                            </template>
                            <template v-else>{{ p.available ?? '—' }}</template>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model="draft!.extended"
                                    class="w-24 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="text"
                                />
                            </template>
                            <template v-else>{{ p.extended ? formatMoney2(p.extended) : '—' }}</template>
                        </td>

                        <td class="px-4 py-3">
                            <button
                                v-if="editingId !== p.id"
                                class="flex cursor-pointer flex-wrap gap-1 rounded-md text-left transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300"
                                type="button"
                                data-testid="product-info-open"
                                :aria-label="`Open PDP info for ${p.sku}`"
                                @click="props.onOpenPlamod(p.id)"
                            >
                                <span
                                    v-if="isMissingPdpImages(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    images
                                </span>
                                <span
                                    v-if="isMissingPdpDescription(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    desc
                                </span>
                                <span
                                    v-if="isMissingSellingPrice(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    selling
                                </span>
                                <span
                                    v-if="isMissingBarcode(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    barcode
                                </span>

                                <span
                                    v-if="!isMissingPdpImages(p) && !isMissingPdpDescription(p) && !isMissingSellingPrice(p) && !isMissingBarcode(p)"
                                    class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-900"
                                >
                                    ok
                                </span>
                            </button>

                            <div v-else class="flex flex-wrap gap-1">
                                <span
                                    v-if="isMissingPdpImages(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    images
                                </span>
                                <span
                                    v-if="isMissingPdpDescription(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    desc
                                </span>
                                <span
                                    v-if="isMissingSellingPrice(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    selling
                                </span>
                                <span
                                    v-if="isMissingBarcode(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    barcode
                                </span>

                                <span
                                    v-if="!isMissingPdpImages(p) && !isMissingPdpDescription(p) && !isMissingSellingPrice(p) && !isMissingBarcode(p)"
                                    class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-900"
                                >
                                    ok
                                </span>
                            </div>
                        </td>

                        <td class="px-4 py-3">
                            <span
                                class="rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                :class="
                                    p.published_on_shopify
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                        : 'border-slate-200 bg-slate-50 text-slate-700'
                                "
                            >
                                {{ p.published_on_shopify ? 'yes' : 'no' }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-right">
                            <div v-if="editingId === p.id" class="flex justify-end gap-2">
                                <button
                                    class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50"
                                    type="button"
                                    :disabled="saving"
                                    @click="saveEdit"
                                >
                                    {{ saving ? 'Saving…' : 'Save' }}
                                </button>
                                <button
                                    class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50"
                                    type="button"
                                    :disabled="saving"
                                    @click="cancelEdit"
                                >
                                    Cancel
                                </button>
                            </div>
                            <div v-else class="flex justify-end">
                                <button
                                    class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50"
                                    type="button"
                                    @click="startEdit(p)"
                                >
                                    Edit
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ConfirmDialog
        :open="confirmBulkDeleteOpen"
        title="Delete selected products"
        :message="`Delete ${confirmBulkDeleteCount} selected product(s)?`"
        confirm-text="Delete"
        variant="danger"
        :busy="bulkDeleting"
        @cancel="confirmBulkDeleteOpen = false"
        @confirm="confirmBulkDelete"
    />

    <BulkUpdateDialog
        :open="bulkUpdateOpen"
        :selected-count="selected.size"
        :busy="bulkUpdating"
        @cancel="bulkUpdateOpen = false"
        @confirm="confirmBulkUpdate"
    />
</template>
