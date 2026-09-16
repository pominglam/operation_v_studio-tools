<script setup lang="ts">
import { computed, ref, watch } from 'vue';

export type ProductsImageRecrawlSource =
    | 'bandai'
    | 'hlj'
    | 'gundamplanet'
    | 'newtype'
    | 'gundamhangar'
    | 'argama'
    | 'cool_dragon'
    | 'plamod';

export type ProductsPriceRecrawlSource =
    | 'aliexpress'
    | 'argama_hobby'
    | 'panda_hobby'
    | 'canada_computers'
    | 'canadian_gundam'
    | 'hobby_bee'
    | 'hobby_wholesale'
    | 'meeplemart'
    | 'hobby_sense'
    | 'gundam_hangar'
    | 'cool_dragon_hobby';

export type ProductsRecrawlSource =
    ProductsImageRecrawlSource | ProductsPriceRecrawlSource | 'competitor_price_research';

const PRICE_SITES: Array<{ key: ProductsPriceRecrawlSource; name: string }> = [
    { key: 'argama_hobby', name: 'Argama Hobby' },
    { key: 'panda_hobby', name: 'Panda Hobby' },
    { key: 'canada_computers', name: 'Canada Computers' },
    { key: 'canadian_gundam', name: 'Canadian Gundam' },
    { key: 'hobby_bee', name: 'Hobby Bee' },
    { key: 'hobby_wholesale', name: 'HobbyWholesale' },
    { key: 'meeplemart', name: 'Meeplemart' },
    { key: 'hobby_sense', name: 'Hobby Sense' },
    { key: 'gundam_hangar', name: 'Gundam Hangar' },
    { key: 'cool_dragon_hobby', name: 'Cool Dragon Hobby' },
    { key: 'aliexpress', name: 'AliExpress' },
];

function defaultImageSources(): Record<ProductsImageRecrawlSource, boolean> {
    return {
        bandai: true,
        hlj: true,
        gundamplanet: true,
        newtype: true,
        gundamhangar: true,
        argama: false,
        cool_dragon: false,
        plamod: true,
    };
}

function defaultPriceSources(): Record<ProductsPriceRecrawlSource, boolean> {
    const next = {} as Record<ProductsPriceRecrawlSource, boolean>;
    for (const site of PRICE_SITES) {
        next[site.key] = site.key !== 'aliexpress';
    }
    return next;
}

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm', payload: { sources: ProductsRecrawlSource[] }): void;
}>();

const imageSources = ref<Record<ProductsImageRecrawlSource, boolean>>(defaultImageSources());
const priceSources = ref<Record<ProductsPriceRecrawlSource, boolean>>(defaultPriceSources());

const chosenSources = computed<ProductsRecrawlSource[]>(() => {
    const images = (
        Object.entries(imageSources.value) as Array<[ProductsImageRecrawlSource, boolean]>
    )
        .filter(([, on]) => on)
        .map(([key]) => key);
    const prices = (
        Object.entries(priceSources.value) as Array<[ProductsPriceRecrawlSource, boolean]>
    )
        .filter(([, on]) => on)
        .map(([key]) => key);
    return [...images, ...prices];
});

const canConfirm = computed(() => chosenSources.value.length > 0 && !props.busy);

watch(
    () => props.open,
    (next) => {
        if (!next) return;
        imageSources.value = defaultImageSources();
        priceSources.value = defaultPriceSources();
    },
);

function setAllPriceSites(on: boolean): void {
    const next = { ...priceSources.value };
    for (const site of PRICE_SITES) {
        next[site.key] = on;
    }
    priceSources.value = next;
}

function onConfirm(): void {
    if (!canConfirm.value) return;
    emit('confirm', { sources: chosenSources.value });
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
            <div
                class="flex max-h-[90vh] w-full max-w-xl flex-col rounded-lg bg-white p-4 shadow-xl"
            >
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

                <div class="mt-4 min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                        Recrawl images
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="imageSources.bandai"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Bandai PDP (images + description + grade/series/scale + yen/launch date)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="imageSources.hlj"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        HLJ PDP (images + description)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="imageSources.gundamplanet"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        GundamPlanet PDP (images)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="imageSources.newtype"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Newtype PDP (images + description + facts)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="imageSources.gundamhangar"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        GundamHangar API (images + description)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="imageSources.argama"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Argama PDP (images)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="imageSources.cool_dragon"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Cool Dragon Hobby PDP (images)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="imageSources.plamod"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="busy"
                        />
                        Plamod (ZIP images)
                    </label>

                    <div
                        class="mt-4 flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >
                        <span>Recrawl prices</span>
                        <span
                            class="flex items-center gap-2 font-medium normal-case tracking-normal"
                        >
                            <button
                                type="button"
                                class="text-slate-600 underline-offset-2 hover:underline disabled:opacity-50"
                                :disabled="busy"
                                @click="setAllPriceSites(true)"
                            >
                                All
                            </button>
                            <button
                                type="button"
                                class="text-slate-600 underline-offset-2 hover:underline disabled:opacity-50"
                                :disabled="busy"
                                @click="setAllPriceSites(false)"
                            >
                                None
                            </button>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label
                            v-for="site in PRICE_SITES"
                            :key="site.key"
                            class="flex items-center gap-2 text-sm text-slate-800"
                        >
                            <input
                                v-model="priceSources[site.key]"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :disabled="busy"
                            />
                            {{ site.name }}
                        </label>
                    </div>

                    <div class="text-xs text-slate-500">
                        Tip: open a product’s
                        <span class="font-semibold text-slate-700">Info</span> drawer to compare
                        images by source (Bandai/HLJ/GundamPlanet/Newtype/GundamHangar/Argama/Cool
                        Dragon/Plamod).
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
                        :disabled="!canConfirm"
                        @click="onConfirm"
                    >
                        {{ busy ? 'Queuing…' : 'Recrawl' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
