<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '../../lib/api';
import AddProductForm, { type CreateProductPayload } from '../products/AddProductForm.vue';

export type InventoryCheckResolveItem = {
    id: number;
    barcode_scanned: string | null;
    sku: string;
    vendor: string | null;
    type: string | null;
    product_name: string | null;
    quantity_in_store: number | null;
    match_error: string | null;
};

type ProductSearchResult = {
    id: string;
    sku: string;
    barcode: string | null;
    description: string;
    handle: string | null;
    type: string | null;
    vendor: string | null;
    available: number | null;
    selling_price?: string | null;
};

type ProductCreateResponse = {
    data: ProductSearchResult;
};

const props = withDefaults(
    defineProps<{
        open: boolean;
        sessionId: string;
        item: InventoryCheckResolveItem | null;
        intent?: 'resolve' | 'reassign';
        vendorOptions?: string[];
        mainTypeOptions?: string[];
        typeOptions?: string[];
    }>(),
    {
        intent: 'resolve',
    },
);

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'resolved'): void;
}>();

const mode = ref<'search' | 'create'>('search');
const searchQuery = ref('');
const searchBusy = ref(false);
const assignBusy = ref(false);
const createBusy = ref(false);
const error = ref<string | null>(null);
const createMessage = ref<string | null>(null);
const results = ref<ProductSearchResult[]>([]);

const isBusy = computed<boolean>(() => searchBusy.value || assignBusy.value || createBusy.value);

const initialCreateValues = computed<Partial<CreateProductPayload>>(() => {
    const item = props.item;
    if (!item) return {};

    return {
        sku: item.sku ?? '',
        barcode: item.barcode_scanned || item.sku || null,
        description: item.product_name ?? '',
        vendor: item.vendor,
        type: item.type,
        available: item.quantity_in_store,
    };
});

function resetForItem(): void {
    const item = props.item;
    mode.value = 'search';
    searchQuery.value = item?.sku || item?.barcode_scanned || item?.product_name || '';
    searchBusy.value = false;
    assignBusy.value = false;
    createBusy.value = false;
    error.value = null;
    createMessage.value = null;
    results.value = [];
}

async function searchProducts(): Promise<void> {
    const q = searchQuery.value.trim();
    if (q === '') {
        results.value = [];
        return;
    }

    searchBusy.value = true;
    error.value = null;
    try {
        const res = await api.get<{ data: ProductSearchResult[] }>('/api/v1/products', {
            params: {
                search: q,
                per_page: 10,
                sort_by: 'sku',
                sort_dir: 'asc',
            },
        });
        results.value = res.data.data ?? [];
    } catch {
        error.value = 'Failed to search products.';
    } finally {
        searchBusy.value = false;
    }
}

async function assignProduct(productId: string): Promise<void> {
    if (!props.item || assignBusy.value) return;

    assignBusy.value = true;
    error.value = null;
    try {
        await postAssignProduct(productId);
        emit('resolved');
    } catch {
        error.value = 'Failed to assign this product to the row.';
    } finally {
        assignBusy.value = false;
    }
}

async function postAssignProduct(productId: string): Promise<void> {
    if (!props.item) return;

    await api.post(
        `/api/v1/inventory-check/${props.sessionId}/items/${props.item.id}/assign-product`,
        {
            product_id: productId,
        },
    );
}

async function createProduct(payload: CreateProductPayload): Promise<void> {
    if (!props.item || createBusy.value) return;

    createBusy.value = true;
    error.value = null;
    createMessage.value = null;
    try {
        const res = await api.post<ProductCreateResponse>('/api/v1/products', payload);
        const productId = res.data.data.id;
        await postAssignProduct(productId);
        emit('resolved');
        createMessage.value = 'Product created and assigned.';
    } catch {
        error.value = 'Failed to create and assign product.';
    } finally {
        createBusy.value = false;
    }
}

watch(
    () => [props.open, props.item?.id] as const,
    ([open]) => {
        if (open) {
            resetForItem();
            void searchProducts();
        }
    },
);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && item"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="!isBusy && emit('close')"
        >
            <div
                class="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-lg bg-white p-4 shadow-xl"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">
                            {{
                                intent === 'reassign'
                                    ? 'Reassign inventory row'
                                    : 'Resolve inventory row'
                            }}
                        </div>
                        <div class="mt-1 text-sm text-slate-600">
                            {{
                                intent === 'reassign'
                                    ? 'Link this row to a different catalog product, or create a new one without leaving this inventory check.'
                                    : 'Assign the bad scan to an existing product, or create a product without leaving this inventory check.'
                            }}
                        </div>
                        <div class="mt-2 text-xs text-slate-500">
                            Scanned:
                            <span class="font-medium text-slate-700">
                                {{ item.barcode_scanned || item.sku }}
                            </span>
                            <span v-if="item.product_name"> · {{ item.product_name }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 disabled:opacity-50"
                        :disabled="isBusy"
                        @click="emit('close')"
                    >
                        Close
                    </button>
                </div>

                <div class="mt-4 flex gap-2 border-b border-slate-200">
                    <button
                        type="button"
                        class="border-b-2 px-3 py-2 text-sm font-medium"
                        :class="
                            mode === 'search'
                                ? 'border-slate-900 text-slate-900'
                                : 'border-transparent text-slate-500 hover:text-slate-800'
                        "
                        :disabled="isBusy"
                        @click="mode = 'search'"
                    >
                        Assign existing
                    </button>
                    <button
                        type="button"
                        class="border-b-2 px-3 py-2 text-sm font-medium"
                        :class="
                            mode === 'create'
                                ? 'border-slate-900 text-slate-900'
                                : 'border-transparent text-slate-500 hover:text-slate-800'
                        "
                        :disabled="isBusy"
                        @click="mode = 'create'"
                    >
                        Create new
                    </button>
                </div>

                <div
                    v-if="error"
                    class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                >
                    {{ error }}
                </div>

                <div v-if="mode === 'search'" class="mt-4 space-y-3">
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input
                            v-model="searchQuery"
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            type="text"
                            placeholder="Search by SKU, barcode, or name"
                            :disabled="isBusy"
                            @keyup.enter="searchProducts"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-50"
                            :disabled="isBusy || searchQuery.trim() === ''"
                            @click="searchProducts"
                        >
                            {{ searchBusy ? 'Searching...' : 'Search' }}
                        </button>
                    </div>

                    <div class="overflow-x-auto rounded-md border border-slate-200">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr>
                                    <th class="px-3 py-2">SKU</th>
                                    <th class="px-3 py-2">Barcode</th>
                                    <th class="px-3 py-2">Name</th>
                                    <th class="px-3 py-2">Vendor</th>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2 text-right">Available</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="product in results"
                                    :key="product.id"
                                    class="border-t border-slate-200"
                                >
                                    <td class="px-3 py-2 font-medium text-slate-900">
                                        {{ product.sku }}
                                    </td>
                                    <td class="px-3 py-2">{{ product.barcode ?? '' }}</td>
                                    <td class="px-3 py-2">{{ product.description }}</td>
                                    <td class="px-3 py-2">{{ product.vendor ?? '' }}</td>
                                    <td class="px-3 py-2">{{ product.type ?? '' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        {{ product.available ?? '' }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                                            :disabled="isBusy"
                                            @click="assignProduct(product.id)"
                                        >
                                            {{ assignBusy ? 'Assigning...' : 'Assign' }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="!searchBusy && results.length === 0" class="text-sm text-slate-600">
                        No products found yet. Try another search or create a new product.
                    </p>
                </div>

                <div v-else class="mt-4">
                    <AddProductForm
                        embedded
                        :busy="createBusy"
                        :error="error"
                        :message="createMessage"
                        :on-create="createProduct"
                        :vendor-options="vendorOptions"
                        :main-type-options="mainTypeOptions"
                        :type-options="typeOptions"
                        :initial-values="initialCreateValues"
                        :reset-key="`${item.id}:${open}`"
                    />
                </div>
            </div>
        </div>
    </Teleport>
</template>
