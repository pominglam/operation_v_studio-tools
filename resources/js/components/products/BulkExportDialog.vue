<script setup lang="ts">
import { ref, watch } from 'vue';

export type ProductsBulkExportType = 'shopify' | 'shopify_content' | 'missing_barcode' | 'barcoded';

const props = defineProps<{
  open: boolean;
  selectedCount: number;
  busy: boolean;
}>();

const emit = defineEmits<{
  (e: 'cancel'): void;
  (e: 'confirm', payload: { exportType: ProductsBulkExportType }): void;
}>();

const exportType = ref<ProductsBulkExportType>('shopify');

watch(
  () => props.open,
  (next) => {
    if (next) exportType.value = 'shopify';
  },
);

function onConfirm(): void {
  emit('confirm', { exportType: exportType.value });
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
      role="dialog"
      aria-modal="true"
      @click.self="emit('cancel')"
    >
      <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-xl">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-slate-900">Export selected products</div>
            <div class="mt-1 text-sm text-slate-600">
              Export
              <span class="font-semibold text-slate-900">{{ selectedCount }}</span>
              selected product(s) using an existing export format.
            </div>
          </div>
          <button
            type="button"
            class="rounded px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 disabled:opacity-50"
            :disabled="busy"
            @click="emit('cancel')"
          >
            Close
          </button>
        </div>

        <div class="mt-4">
          <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">
            Export type
          </label>
          <select
            v-model="exportType"
            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
            :disabled="busy"
          >
            <option value="shopify">Shopify (CSV)</option>
            <option value="shopify_content">Shopify content (images + description)</option>
            <option value="missing_barcode">Missing barcode (CSV)</option>
            <option value="barcoded">Barcoded inventory (CSV)</option>
          </select>
          <div class="mt-1 text-xs text-slate-500">
            Note: each export keeps its existing rules (some selected products may be excluded).
          </div>
        </div>

        <div class="mt-4 flex items-center justify-end gap-2">
          <button
            type="button"
            class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
            :disabled="busy"
            @click="emit('cancel')"
          >
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            :disabled="busy"
            @click="onConfirm"
          >
            {{ busy ? 'Exporting…' : 'Export' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

