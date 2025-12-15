<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../lib/api';
import AddProductForm, {
    type CreateProductPayload,
} from '../components/products/AddProductForm.vue';
import ImportProductsCard from '../components/products/ImportProductsCard.vue';
import ProductsTable, {
    type ProductRow,
    type ProductSortKey,
    type UpdateProductPayload,
} from '../components/products/ProductsTable.vue';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import PaginationControls from '../components/ui/PaginationControls.vue';

type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

const loading = ref(false);
const error = ref<string | null>(null);
const products = ref<ProductRow[]>([]);
const meta = ref<Paginated<ProductRow>['meta'] | null>(null);

const route = useRoute();

const creating = ref(false);
const createError = ref<string | null>(null);
const createMessage = ref<string | null>(null);

type ProductsToolTab = 'list' | 'add' | 'import';
const activeTab = ref<ProductsToolTab>('list');

const search = ref('');
const perPage = ref(50);
const page = ref(1);
const sortBy = ref<ProductSortKey>('sku');
const sortDir = ref<'asc' | 'desc'>('asc');
const selectedTypes = ref<string[]>([]);

const typeOptions = ref<MultiSelectOption[]>([]);

const total = computed<number>(() => meta.value?.total ?? 0);
const currentPage = computed<number>(() => meta.value?.current_page ?? page.value);
const lastPage = computed<number>(() => meta.value?.last_page ?? 1);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const res = await api.get<Paginated<ProductRow>>('/api/v1/products', {
            params: {
                per_page: perPage.value,
                page: page.value,
                search: search.value.trim() || undefined,
                sort_by: sortBy.value,
                sort_dir: sortDir.value,
                types: selectedTypes.value.length > 0 ? selectedTypes.value : undefined,
            },
        });
        products.value = res.data.data;
        meta.value = res.data.meta;
    } catch (e: unknown) {
        error.value = 'Failed to load products.';
    } finally {
        loading.value = false;
    }
}

async function loadFilterOptions(): Promise<void> {
    try {
        const res = await api.get<{ data: { types: string[] } }>('/api/v1/products/filter-options');
        typeOptions.value = res.data.data.types.map((t) => ({ value: t, label: t }));
    } catch {
        // ignore; filter dropdown will just be empty
    }
}

async function create(payload: CreateProductPayload): Promise<void> {
    creating.value = true;
    createError.value = null;
    createMessage.value = null;

    try {
        await api.post('/api/v1/products', payload);
        createMessage.value = 'Product created.';
        page.value = 1;
        await load();
    } catch (e: unknown) {
        createError.value = 'Failed to create product (check SKU uniqueness and required fields).';
    } finally {
        creating.value = false;
    }
}

async function bulkDelete(ids: string[]): Promise<number> {
    const res = await api.post<{ deleted: number }>('/api/v1/products/bulk-delete', { ids });
    return res.data.deleted;
}

async function updateProduct(id: string, payload: UpdateProductPayload): Promise<void> {
    await api.patch(`/api/v1/products/${id}`, payload);
}

function onSortChange(next: ProductSortKey): void {
    if (sortBy.value === next) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }

    sortBy.value = next;
    sortDir.value = 'asc';
}

function onPageChange(next: number): void {
    page.value = Math.max(1, next);
}

let searchTimer: number | null = null;
watch([search, perPage, selectedTypes, sortBy, sortDir], () => {
    page.value = 1;
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => void load(), 250);
});

watch(page, () => void load());

onMounted(() => {
    void loadFilterOptions();
    void load();
});

watch(
    () => route.hash,
    (hash) => {
        if (hash !== '#import') return;
        activeTab.value = 'import';
        void nextTick(() => {
            document.getElementById('import')?.scrollIntoView({ block: 'start' });
        });
    },
    { immediate: true },
);
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Products</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Products currently stored in the database.
                </p>
            </div>

            <button
                class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                type="button"
                :disabled="loading"
                @click="load"
            >
                Refresh
            </button>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 bg-slate-50 px-3 pt-2">
                <div
                    class="flex flex-wrap items-end gap-2"
                    role="tablist"
                    aria-label="Product tools"
                >
                    <button
                        class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        role="tab"
                        type="button"
                        :aria-selected="activeTab === 'list'"
                        :class="
                            activeTab === 'list'
                                ? 'border-slate-200 border-b-white bg-white text-slate-900'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        "
                        @click="activeTab = 'list'"
                    >
                        Products
                    </button>
                    <button
                        class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        role="tab"
                        type="button"
                        :aria-selected="activeTab === 'add'"
                        :class="
                            activeTab === 'add'
                                ? 'border-slate-200 border-b-white bg-white text-slate-900'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        "
                        @click="activeTab = 'add'"
                    >
                        Add
                    </button>
                    <button
                        class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        role="tab"
                        type="button"
                        :aria-selected="activeTab === 'import'"
                        :class="
                            activeTab === 'import'
                                ? 'border-slate-200 border-b-white bg-white text-slate-900'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        "
                        @click="activeTab = 'import'"
                    >
                        Import
                    </button>
                </div>
            </div>

            <div class="p-4">
                <div v-show="activeTab === 'list'" class="space-y-4">
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-4 md:items-end">
                            <div class="md:col-span-2">
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                                    >Search</label
                                >
                                <input
                                    v-model="search"
                                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                                    type="text"
                                    placeholder="Search SKU / barcode / description…"
                                />
                            </div>

                            <MultiSelectFilter
                                v-model="selectedTypes"
                                label="Type"
                                :options="typeOptions"
                                placeholder="All types"
                            />

                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                                    >Per page</label
                                >
                                <select
                                    v-model.number="perPage"
                                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                >
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                    <option :value="100">100</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-3 text-sm text-slate-600">
                            Showing
                            <span class="font-medium text-slate-900">{{ products.length }}</span> of
                            <span class="font-medium text-slate-900">{{ total }}</span>
                        </div>
                    </div>

                    <ProductsTable
                        :loading="loading"
                        :products="products"
                        :sort-by="sortBy"
                        :sort-dir="sortDir"
                        :on-sort-change="onSortChange"
                        :on-refresh="load"
                        :on-bulk-delete="bulkDelete"
                        :on-update="updateProduct"
                    />

                    <PaginationControls
                        :current-page="currentPage"
                        :last-page="lastPage"
                        :total="total"
                        :on-change="onPageChange"
                    />
                </div>

                <AddProductForm
                    v-show="activeTab === 'add'"
                    :busy="creating"
                    :error="createError"
                    :message="createMessage"
                    :on-create="create"
                    :embedded="true"
                />
                <ImportProductsCard v-show="activeTab === 'import'" :embedded="true" />
            </div>
        </div>
    </section>
</template>
