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
const wouldUpdate = ref<number | null>(null);
const matchColumn = ref<string | null>(null);
const handleColumn = ref<string | null>(null);
const uploadedPath = ref<string | null>(null);
const missingInSystem = ref<string[] | null>(null);
const missingSkuRows = ref<number | null>(null);
const missingHandleRows = ref<number | null>(null);

const containerClass = computed<string>(() => (props.embedded ? '' : 'rounded-lg border border-slate-200 bg-white p-4'));

function resetResult(): void {
    error.value = null;
    updated.value = null;
    wouldUpdate.value = null;
    matchColumn.value = null;
    handleColumn.value = null;
    uploadedPath.value = null;
    missingInSystem.value = null;
    missingSkuRows.value = null;
    missingHandleRows.value = null;
}

function onFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    resetResult();
}

async function submit(): Promise<void> {
    if (!file.value) {
        error.value = 'Please choose a Shopify CSV file.';
        return;
    }

    uploading.value = true;
    resetResult();

    try {
        const form = new FormData();
        form.append('file', file.value);

        const res = await api.post<{
            updated: number;
            would_update: number;
            match_column: string;
            handle_column: string;
            uploaded_file_path: string;
            missing_in_system: string[];
            missing_sku_rows: number;
            missing_handle_rows: number;
        }>('/api/v1/products/import-handles', form, { headers: { 'Content-Type': 'multipart/form-data' } });

        updated.value = res.data.updated;
        wouldUpdate.value = res.data.would_update;
        matchColumn.value = res.data.match_column;
        handleColumn.value = res.data.handle_column;
        uploadedPath.value = res.data.uploaded_file_path;
        missingInSystem.value = res.data.missing_in_system;
        missingSkuRows.value = res.data.missing_sku_rows;
        missingHandleRows.value = res.data.missing_handle_rows;
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        error.value = typeof apiMessage === 'string' ? apiMessage : 'Handle import failed.';
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div :class="containerClass">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <div class="text-sm font-medium text-slate-900">Import handles (Shopify)</div>
                <div class="mt-1 text-sm text-slate-600">
                    Matches
                    <span class="font-mono text-xs">Variant SKU</span>
                    → saves
                    <span class="font-mono text-xs">Handle</span>
                    to
                    <span class="font-mono text-xs">products.handle</span>.
                    <span class="font-semibold">Overwrites existing handles</span> (skips blank Handle rows).
                </div>
            </div>

            <button
                class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="uploading"
                type="button"
                @click="submit"
            >
                {{ uploading ? 'Uploading…' : 'Upload' }}
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

        <div v-if="error" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
            {{ error }}
        </div>

        <div
            v-if="updated !== null"
            class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
        >
            <div class="font-medium">
                Updated {{ updated }} product(s).
            </div>
            <div class="mt-1 text-xs text-emerald-900">
                Matched by <span class="font-mono">{{ matchColumn }}</span>, imported from
                <span class="font-mono">{{ handleColumn }}</span>.
            </div>
            <div class="mt-1 text-xs text-emerald-900">
                Uploaded: <span class="font-mono">{{ uploadedPath }}</span>
            </div>
            <div v-if="missingSkuRows !== null || missingHandleRows !== null" class="mt-1 text-xs text-emerald-900">
                Skipped rows: missing SKU={{ missingSkuRows ?? 0 }}, missing Handle={{ missingHandleRows ?? 0 }}.
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


