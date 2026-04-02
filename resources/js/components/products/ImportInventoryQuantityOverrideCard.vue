<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '../../lib/api';

const props = defineProps<{
  embedded?: boolean;
  purchaseOrderUuid?: string | null;
}>();

const file = ref<File | null>(null);
const uploading = ref(false);
const error = ref<string | null>(null);
const canForce = ref(false);
const missingMode = ref<'set_zero' | 'skip'>('set_zero');

const linesParsed = ref<number | null>(null);
const uniqueBarcodes = ref<number | null>(null);
const resetProducts = ref<number | null>(null);
const updatedProducts = ref<number | null>(null);
const backupPath = ref<string | null>(null);
const uploadedPath = ref<string | null>(null);
const missingInSystem = ref<string[] | null>(null);

const containerClass = computed<string>(() => {
  return props.embedded ? '' : 'rounded-lg border border-slate-200 bg-white p-4';
});

function onFileChange(e: Event): void {
  const input = e.target as HTMLInputElement;
  file.value = input.files?.[0] ?? null;
  error.value = null;
  canForce.value = false;
  missingMode.value = 'set_zero';
  linesParsed.value = null;
  uniqueBarcodes.value = null;
  resetProducts.value = null;
  updatedProducts.value = null;
  backupPath.value = null;
  uploadedPath.value = null;
  missingInSystem.value = null;
}

async function submit(force: boolean): Promise<void> {
  if (!file.value) {
    error.value = 'Please choose a barcode scan CSV.';
    return;
  }

  uploading.value = true;
  error.value = null;
  canForce.value = false;
  missingInSystem.value = null;

  try {
    const form = new FormData();
    form.append('file', file.value);
    if (typeof props.purchaseOrderUuid === 'string' && props.purchaseOrderUuid.trim() !== '') {
      form.append('purchase_order_uuid', props.purchaseOrderUuid.trim());
    }
    if (force) {
      form.append('force', '1');
    }
    form.append('missing_products_mode', missingMode.value);

    const res = await api.post<{
      lines_parsed: number;
      unique_barcodes: number;
      reset_products: number;
      updated_products: number;
      backup_before_path: string | null;
      uploaded_file_path: string | null;
      missing_in_system: string[];
      scoped_purchase_order_uuid?: string | null;
      blocked?: boolean;
      can_force?: boolean;
    }>('/api/v1/products/import-inventory-qty-override', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    linesParsed.value = res.data.lines_parsed ?? 0;
    uniqueBarcodes.value = res.data.unique_barcodes ?? 0;
    resetProducts.value = res.data.reset_products ?? 0;
    updatedProducts.value = res.data.updated_products ?? 0;
    backupPath.value = res.data.backup_before_path;
    uploadedPath.value = res.data.uploaded_file_path;
    missingInSystem.value = res.data.missing_in_system ?? [];
  } catch (e: unknown) {
    const anyErr = e as any;
    const apiData: any = anyErr?.response?.data;
    const apiMessage: string | undefined = apiData?.message;
    const missing: unknown = apiData?.missing_in_system;
    const blocked: boolean = Boolean(apiData?.blocked);
    const allowForce: boolean = Boolean(apiData?.can_force);

    if (blocked && Array.isArray(missing)) {
      missingInSystem.value = missing.filter((v: unknown) => typeof v === 'string') as string[];
      canForce.value = allowForce;
      error.value =
        typeof apiMessage === 'string'
          ? apiMessage
          : 'Some items were not found in the database. No quantities were changed.';
    } else {
      error.value =
        typeof apiMessage === 'string'
          ? apiMessage
          : 'Inventory quantity override import failed.';
    }
  } finally {
    uploading.value = false;
  }
}
</script>

<template>
  <div :class="containerClass">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <div class="text-sm font-medium text-slate-900">
          Import inventory quantity override (barcode scan)
        </div>
        <div class="mt-1 text-sm text-slate-600">
          CSV format:
          <span class="font-mono text-xs">barcode[,qty]</span>.
          Qty (2nd column) is optional; blank means <span class="font-medium">1</span>. Duplicate barcodes add up.
          Choose what to do with products <span class="font-medium">not present</span> in this file:
        </div>

        <div class="mt-2 flex flex-col gap-2 text-sm text-slate-700">
          <label class="inline-flex items-center gap-2">
            <input v-model="missingMode" type="radio" value="set_zero" />
            <span>Set <span class="font-medium">0 Available</span></span>
          </label>
          <label class="inline-flex items-center gap-2">
            <input v-model="missingMode" type="radio" value="skip" />
            <span>Skip (leave unchanged)</span>
          </label>
        </div>
      </div>

      <button
        class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="uploading"
        type="button"
        @click="submit(false)"
      >
        {{ uploading ? 'Uploading…' : 'Upload & Override' }}
      </button>
    </div>

    <div class="mt-3">
      <label class="block text-sm font-medium text-slate-800">Barcode scan CSV</label>
      <input
        class="mt-2 block w-full cursor-pointer rounded-md border border-slate-200 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-800"
        type="file"
        accept=".csv,text/csv,text/plain"
        @change="onFileChange"
      />
    </div>

    <div
      v-if="error"
      class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
    >
      <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>{{ error }}</div>
        <button
          v-if="canForce"
          class="inline-flex items-center justify-center rounded-md bg-rose-700 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="uploading"
          type="button"
          @click="submit(true)"
        >
          Force update anyway
        </button>
      </div>
    </div>

    <div
      v-if="updatedProducts !== null"
      class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
    >
      <div class="font-medium">
        Updated {{ updatedProducts }} product(s).
        <span v-if="missingMode === 'set_zero'">Reset inventory to 0 for {{ resetProducts }} product(s).</span>
        <span v-else>Products not present were left unchanged.</span>
      </div>
      <div class="mt-1 text-xs text-emerald-900">
        Parsed {{ linesParsed }} line(s) across {{ uniqueBarcodes }} unique barcode(s).
      </div>
      <div class="mt-1 text-xs text-emerald-900">
        Backup: <span class="font-mono">{{ backupPath }}</span>
      </div>
      <div class="mt-1 text-xs text-emerald-900">
        Uploaded: <span class="font-mono">{{ uploadedPath }}</span>
      </div>
    </div>

    <div
      v-if="missingInSystem && missingInSystem.length"
      class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
    >
      <div class="font-medium">
        Missing in our system (present in file but no matching barcode in DB):
        {{ missingInSystem.length }}
      </div>
      <div class="mt-2 max-h-48 overflow-auto rounded border border-rose-200 bg-white p-2">
        <ul class="space-y-1">
          <li v-for="b in missingInSystem" :key="b" class="font-mono text-xs text-slate-900">
            {{ b }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

