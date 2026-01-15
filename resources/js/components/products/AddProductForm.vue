<script setup lang="ts">
import { ref, watch } from 'vue';

export type CreateProductPayload = {
    sku: string;
    barcode: string | null;
    description: string;
    handle: string | null;
    type: string | null;
    vendor: string | null;
    available: number | null;
    extended: string | null;
};

const props = defineProps<{
    busy: boolean;
    error: string | null;
    message: string | null;
    onCreate: (payload: CreateProductPayload) => Promise<void>;
    vendorOptions?: string[];
    embedded?: boolean;
}>();

const form = ref<CreateProductPayload>({
    sku: '',
    barcode: null,
    description: '',
    handle: null,
    type: null,
    vendor: 'Plamod',
    available: null,
    extended: null,
});

const localError = ref<string | null>(null);

watch(
    () => props.vendorOptions,
    (opts) => {
        if (!opts || opts.length === 0) return;

        const current = form.value.vendor;
        if (current && opts.includes(current)) return;

        form.value.vendor = opts.includes('Plamod') ? 'Plamod' : (opts[0] ?? null);
    },
    { immediate: true },
);

async function submit(): Promise<void> {
    localError.value = null;
    if (!form.value.sku.trim() || !form.value.description.trim()) {
        localError.value = 'SKU and Name are required.';
        return;
    }

    await props.onCreate({
        sku: form.value.sku.trim(),
        barcode: form.value.barcode?.trim() || null,
        description: form.value.description.trim(),
        handle: form.value.handle?.trim() || null,
        type: form.value.type?.trim() || null,
        vendor: form.value.vendor?.trim() || null,
        available: form.value.available,
        extended: form.value.extended?.trim() || null,
    });

    form.value = {
        sku: '',
        barcode: null,
        description: '',
        handle: null,
        type: null,
        vendor: 'Plamod',
        available: null,
        extended: null,
    };
}
</script>

<template>
    <div :class="embedded ? '' : 'rounded-lg border border-slate-200 bg-white p-4'">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="text-sm font-semibold text-slate-900">Add product</div>
                <div class="mt-1 text-sm text-slate-600">
                    Create a product manually (no CSV required).
                </div>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-6">
            <div class="md:col-span-3">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >SKU *</label
                >
                <input
                    v-model="form.sku"
                    class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    type="text"
                    autocomplete="off"
                />
            </div>
            <div class="md:col-span-3">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Barcode</label
                >
                <input
                    v-model="form.barcode"
                    class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    type="text"
                    autocomplete="off"
                />
            </div>
            <div class="md:col-span-6">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Name *</label
                >
                <input
                    v-model="form.description"
                    class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    type="text"
                />
            </div>
            <div class="md:col-span-6">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Handle</label
                >
                <input
                    v-model="form.handle"
                    class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    type="text"
                    autocomplete="off"
                    placeholder="(optional Shopify handle / slug)"
                />
            </div>
            <div class="md:col-span-3">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Vendor</label
                >
                <select
                    v-model="form.vendor"
                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                >
                    <option :value="null">—</option>
                    <option v-for="v in vendorOptions ?? []" :key="v" :value="v">{{ v }}</option>
                </select>
            </div>
            <div class="md:col-span-3">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Type</label
                >
                <input
                    v-model="form.type"
                    class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    type="text"
                />
            </div>

            <div class="md:col-span-2">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Total cost</label
                >
                <input
                    v-model="form.extended"
                    class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    type="text"
                />
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Available</label
                >
                <input
                    v-model.number="form.available"
                    class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    type="number"
                    min="0"
                />
            </div>
        </div>

        <div class="mt-3 flex items-center justify-end">
            <button
                class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                type="button"
                :disabled="busy"
                @click="submit"
            >
                {{ busy ? 'Creating…' : 'Create product' }}
            </button>
        </div>

        <div
            v-if="localError"
            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ localError }}
        </div>
        <div
            v-else-if="error"
            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>
        <div
            v-if="message"
            class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
        >
            {{ message }}
        </div>
    </div>
</template>
