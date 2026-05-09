<script setup lang="ts">
import { ref, watch } from 'vue';

export type ProductsRecrawlSource =
    | 'bandai'
    | 'hlj'
    | 'gundamplanet'
    | 'newtype'
    | 'gundamhangar'
    | 'argama'
    | 'plamod'
    | 'competitor_price_research';

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm', payload: { sources: ProductsRecrawlSource[] }): void;
}>();

const sources = ref<Record<ProductsRecrawlSource, boolean>>({
    bandai: true,
    hlj: true,
    gundamplanet: true,
    newtype: true,
    gundamhangar: true,
    argama: false,
    plamod: true,
    competitor_price_research: true,
});

watch(
    () => props.open,
    (next) => {
        if (!next) return;
        sources.value = {
            bandai: true,
            hlj: true,
            gundamplanet: true,
            newtype: true,
            gundamhangar: true,
            argama: false,
            plamod: true,
            competitor_price_research: true,
        };
    },
);

function onConfirm(): void {
    const chosen = (Object.entries(sources.value) as Array<[ProductsRecrawlSource, boolean]>)
        .filter(([, v]) => v)
        .map(([k]) => k);
    emit('confirm', { sources: chosen });
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
                        <div class="text-sm font-semibold text-slate-900">
                            Recrawl selected products
                        </div>
                        <div class="mt-1 text-sm text-slate-600">
                            Queue recrawl for
                            <span class="font-semibold text-slate-900">{{ selectedCount }}</span>
                            selected product(s).
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

                <div class="mt-4 space-y-2">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                        Recrawl images
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="sources.bandai"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Bandai PDP (images + description + grade/series/scale + yen/launch date)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="sources.hlj"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        HLJ PDP (images + description)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="sources.gundamplanet"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        GundamPlanet PDP (images)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="sources.newtype"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Newtype PDP (images + description + facts)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="sources.gundamhangar"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        GundamHangar API (images + description)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="sources.argama"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Argama PDP (images)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="sources.plamod"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Plamod (ZIP images)
                    </label>

                    <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        Other
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="sources.competitor_price_research"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Competitor price research sites (Panda/Argama/etc)
                    </label>

                    <div class="text-xs text-slate-500">
                        Tip: open a product’s
                        <span class="font-semibold text-slate-700">Info</span> drawer to compare
                        images by source
                        (Bandai/HLJ/GundamPlanet/Newtype/GundamHangar/Argama/Plamod).
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
                        {{ busy ? 'Queuing…' : 'Recrawl' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
