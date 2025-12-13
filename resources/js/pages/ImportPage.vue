<script setup lang="ts">
import { ref } from 'vue';
import { api } from '../lib/api';

const file = ref<File | null>(null);
const uploading = ref(false);
const error = ref<string | null>(null);
const imported = ref<number | null>(null);

function onFileChange(e: Event): void {
  const input = e.target as HTMLInputElement;
  file.value = input.files?.[0] ?? null;
  error.value = null;
  imported.value = null;
}

async function submit(): Promise<void> {
  if (!file.value) {
    error.value = 'Please choose a CSV file.';
    return;
  }

  uploading.value = true;
  error.value = null;
  imported.value = null;

  try {
    const form = new FormData();
    form.append('file', file.value);

    const res = await api.post<{ imported: number }>('/api/v1/products/import', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    imported.value = res.data.imported;
  } catch (e: unknown) {
    error.value = 'Import failed. Check the CSV format and try again.';
  } finally {
    uploading.value = false;
  }
}
</script>

<template>
  <section class="space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Import products</h1>
      <p class="mt-1 text-sm text-slate-600">
        Upload a CSV with columns: SKU, BARCODE, PRODUCT DESCRIPTION, TYPE, PRICE, ORDER, FILLED, EXTENDED.
      </p>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-4">
      <div class="flex flex-col gap-3 md:flex-row md:items-end">
        <div class="flex-1">
          <label class="block text-sm font-medium text-slate-800">CSV file</label>
          <input
            class="mt-2 block w-full cursor-pointer rounded-md border border-slate-200 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-800"
            type="file"
            accept=".csv,text/csv,text/plain"
            @change="onFileChange"
          />
        </div>

        <button
          class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="uploading"
          type="button"
          @click="submit"
        >
          {{ uploading ? 'Uploading…' : 'Upload & Import' }}
        </button>
      </div>

      <div v-if="error" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
        {{ error }}
      </div>

      <div
        v-if="imported !== null"
        class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
      >
        Imported {{ imported }} products.
      </div>
    </div>
  </section>
</template>


