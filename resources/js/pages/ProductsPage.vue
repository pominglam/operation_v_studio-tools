<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../lib/api';
import AddProductForm, { type CreateProductPayload } from '../components/products/AddProductForm.vue';
import ProductsTable, { type ProductRow, type UpdateProductPayload } from '../components/products/ProductsTable.vue';

type Paginated<T> = {
  data: T[];
};

const loading = ref(false);
const error = ref<string | null>(null);
const products = ref<ProductRow[]>([]);

const creating = ref(false);
const createError = ref<string | null>(null);
const createMessage = ref<string | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;

  try {
    const res = await api.get<Paginated<ProductRow>>('/api/v1/products', { params: { per_page: 50 } });
    products.value = res.data.data;
  } catch (e: unknown) {
    error.value = 'Failed to load products.';
  } finally {
    loading.value = false;
  }
}

async function create(payload: CreateProductPayload): Promise<void> {
  creating.value = true;
  createError.value = null;
  createMessage.value = null;

  try {
    await api.post('/api/v1/products', payload);
    createMessage.value = 'Product created.';
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

onMounted(() => {
  void load();
});
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold">Products</h1>
        <p class="mt-1 text-sm text-slate-600">Products currently stored in the database.</p>
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

    <div v-if="error" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
      {{ error }}
    </div>

    <AddProductForm :busy="creating" :error="createError" :message="createMessage" :on-create="create" />

    <ProductsTable
      :loading="loading"
      :products="products"
      :on-refresh="load"
      :on-bulk-delete="bulkDelete"
      :on-update="updateProduct"
    />
  </section>
</template>


