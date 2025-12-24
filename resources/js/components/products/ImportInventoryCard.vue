<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '../../lib/api';

const props = defineProps<{
    embedded?: boolean;
}>();

const file = ref<File | null>(null);
const uploading = ref(false);
const error = ref<string | null>(null);
const updated = ref<number | null>(null);
const matchColumn = ref<string | null>(null);
const qtyColumn = ref<string | null>(null);
const backupPath = ref<string | null>(null);
const uploadedPath = ref<string | null>(null);
const missingInSystem = ref<string[] | null>(null);
const notUpdated = ref<Array<{ id: string; sku: string; description: string; available: number | null }> | null>(
    null,
);

const containerClass = computed<string>(() => {
    return props.embedded ? '' : 'rounded-lg border border-slate-200 bg-white p-4';
});

function onFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    error.value = null;
    updated.value = null;
    matchColumn.value = null;
    qtyColumn.value = null;
    backupPath.value = null;
    uploadedPath.value = null;
    missingInSystem.value = null;
    notUpdated.value = null;
}

async function submit(): Promise<void> {
    if (!file.value) {
        error.value = 'Please choose a Shopify CSV file.';
        return;
    }

    uploading.value = true;
    error.value = null;
    updated.value = null;
    notUpdated.value = null;

    try {
        const form = new FormData();
        form.append('file', file.value);

        const res = await api.post<{
            updated: number;
            match_column: string;
            qty_column: string;
            backup_before_path: string;
            uploaded_file_path: string;
            missing_in_system: string[];
            not_updated: Array<{ id: string; sku: string; description: string; available: number | null }>;
        }>('/api/v1/products/import-inventory', form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        updated.value = res.data.updated;
        matchColumn.value = res.data.match_column;
        qtyColumn.value = res.data.qty_column;
        backupPath.value = res.data.backup_before_path;
        uploadedPath.value = res.data.uploaded_file_path;
        missingInSystem.value = res.data.missing_in_system;
        notUpdated.value = res.data.not_updated;
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        error.value = typeof apiMessage === 'string' ? apiMessage : 'Inventory import failed.';
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div :class="containerClass">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <div class="text-sm font-medium text-slate-900">Import inventory (Shopify)</div>
                <div class="mt-1 text-sm text-slate-600">
                    Updates <span class="font-medium">Available</span> using Shopify CSV columns
                    <span class="font-mono text-xs">Variant SKU</span> →
                    <span class="font-mono text-xs">Variant Inventory Qty</span>. A backup is saved first.
                </div>
            </div>

            <button
                class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="uploading"
                type="button"
                @click="submit"
            >
                {{ uploading ? 'Uploading…' : 'Upload & Update' }}
            </button>
        </div>

        <div class="mt-3">
            <label class="block text-sm font-medium text-slate-800">Shopify CSV file</label>
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
            {{ error }}
        </div>

        <div
            v-if="updated !== null"
            class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
        >
            Updated {{ updated }} product(s).
            <div class="mt-1 text-xs text-emerald-900">
                Matched by <span class="font-mono">{{ matchColumn }}</span>, updated from
                <span class="font-mono">{{ qtyColumn }}</span>.
            </div>
            <div class="mt-1 text-xs text-emerald-900">
                Backup: <span class="font-mono">{{ backupPath }}</span>
            </div>
            <div class="mt-1 text-xs text-emerald-900">
                Uploaded: <span class="font-mono">{{ uploadedPath }}</span>
            </div>
        </div>

        <div
            v-if="notUpdated && notUpdated.length"
            class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            <div class="font-medium">
                Not updated (no usable inventory qty in CSV): {{ notUpdated.length }}
            </div>
            <div class="mt-2 max-h-64 overflow-auto rounded border border-amber-200 bg-white">
                <table class="min-w-full divide-y divide-amber-100 text-sm">
                    <thead class="bg-amber-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-amber-900">
                            <th class="px-3 py-2">SKU</th>
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2 text-right">Current available</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100">
                        <tr v-for="p in notUpdated" :key="p.id">
                            <td class="px-3 py-2 font-mono text-xs">{{ p.sku }}</td>
                            <td class="px-3 py-2">{{ p.description }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ p.available ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            v-if="missingInSystem && missingInSystem.length"
            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            <div class="font-medium">
                Missing in our system (present in CSV but no matching SKU in DB): {{ missingInSystem.length }}
            </div>
            <div class="mt-2 max-h-48 overflow-auto rounded border border-rose-200 bg-white p-2">
                <ul class="space-y-1">
                    <li v-for="sku in missingInSystem" :key="sku" class="font-mono text-xs text-slate-900">
                        {{ sku }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>


