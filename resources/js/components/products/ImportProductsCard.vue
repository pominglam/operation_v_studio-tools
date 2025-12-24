<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '../../lib/api';

const props = defineProps<{
    embedded?: boolean;
}>();

const file = ref<File | null>(null);
const format = ref<'plamod' | 'stedi'>('plamod');
const uploading = ref(false);
const error = ref<string | null>(null);
const imported = ref<number | null>(null);
const issues = ref<Array<Record<string, unknown>> | null>(null);

const containerClass = computed<string>(() => {
    return props.embedded ? '' : 'rounded-lg border border-slate-200 bg-white p-4';
});

function onFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    error.value = null;
    imported.value = null;
    issues.value = null;
}

async function submit(): Promise<void> {
    if (!file.value) {
        error.value = 'Please choose a CSV file.';
        return;
    }

    uploading.value = true;
    error.value = null;
    imported.value = null;
    issues.value = null;

    try {
        const form = new FormData();
        form.append('file', file.value);
        form.append('format', format.value);

        const res = await api.post<{ imported: number }>('/api/v1/products/import', form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        imported.value = res.data.imported;
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        const apiIssues: unknown = anyErr?.response?.data?.issues;

        if (typeof apiMessage === 'string') {
            error.value = apiMessage;
        } else {
            error.value = 'Import failed. Check the CSV format and try again.';
        }

        if (Array.isArray(apiIssues)) {
            issues.value = apiIssues as Array<Record<string, unknown>>;
        }
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div id="import" :class="containerClass">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <div class="text-sm font-medium text-slate-900">Import products</div>
                <div class="mt-1 text-sm text-slate-600">
                    Upload a CSV. For Stedi, selling price is computed only when Multiplier is present (CAD-focused).
                </div>
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

        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3 md:items-end">
            <div>
                <label class="block text-sm font-medium text-slate-800">Format</label>
                <select
                    v-model="format"
                    class="mt-2 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                >
                    <option value="plamod">Plamod</option>
                    <option value="stedi">Stedi</option>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-800">CSV file</label>
                <input
                    class="mt-2 block w-full cursor-pointer rounded-md border border-slate-200 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-800"
                    type="file"
                    accept=".csv,text/csv,text/plain"
                    @change="onFileChange"
                />
            </div>
        </div>

        <div
            v-if="error"
            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>

        <div
            v-if="issues && issues.length"
            class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            <div class="font-medium">Import blocked. Fix these issues, then retry:</div>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li v-for="(issue, idx) in issues" :key="idx">
                    <code class="text-xs">{{ issue }}</code>
                </li>
            </ul>
        </div>

        <div
            v-if="imported !== null"
            class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
        >
            Imported {{ imported }} products.
        </div>
    </div>
</template>
