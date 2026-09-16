<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '../../lib/api';
import { decodeHtmlEntitiesDeep } from '../../lib/html';
import { descriptionSourceUrl } from '../../lib/pdpSources';
import ProductInfoPhotosPanel from './ProductInfoPhotosPanel.vue';
import {
    SOURCE_LABELS,
    isImage,
    normalizeSourceKey,
    type ProductInfoAsset,
    type SourceKey,
} from './productInfoTypes';

type ProductInfoContent = {
    source: string;
    source_url: string | null;
    title: string | null;
    description_html: string | null;
    attributes: Record<string, unknown> | null;
    updated_at: string | null;
};

type ProductInfoPayload = {
    preferred_description_source?: string | null;
    contents: ProductInfoContent[];
    assets: ProductInfoAsset[];
};

type DescriptionSelectionMode = 'source' | 'manual';

type SelectedSourceState = {
    contentSource: SourceKey;
    descriptionMode: DescriptionSelectionMode;
};

function preferredContentSource(available: Set<SourceKey>): SourceKey {
    if (available.has('hlj')) return 'hlj';
    if (available.has('bandai')) return 'bandai';
    if (available.has('newtype')) return 'newtype';
    if (available.has('gundamhangar')) return 'gundamhangar';
    if (available.has('other')) return 'other';
    return 'plamod';
}

function isBlank(s: string | null | undefined): boolean {
    return !s || s.trim() === '';
}

function htmlToPlainText(html: string): string {
    const el = document.createElement('div');
    el.innerHTML = html;
    return (el.textContent ?? '').trim();
}

function escapeHtml(s: string): string {
    return s
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function manualDraftToHtml(text: string): string | null {
    const trimmed = text.trim();
    if (trimmed === '') return null;
    const body = escapeHtml(trimmed).replace(/\r\n|\r|\n/g, '<br />');
    return `<p>${body}</p>`;
}

function htmlFromManualIsBlank(html: string | null): boolean {
    return html === null || html.trim() === '';
}

function contentForSource(
    contents: ProductInfoContent[],
    source: SourceKey,
): ProductInfoContent | null {
    const newest = (list: ProductInfoContent[]): ProductInfoContent | null =>
        list.slice().sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''))[0] ??
        null;

    if (source === 'other') {
        const exactOther = contents.filter((c) => c.source.trim().toLowerCase() === 'other');
        if (exactOther.length > 0) {
            return newest(exactOther);
        }
    }

    const src = source === 'other' ? null : source;
    const candidates = src
        ? contents.filter((c) => normalizeSourceKey(c.source) === source)
        : contents.filter((c) => normalizeSourceKey(c.source) === 'other');
    if (candidates.length === 0) return null;

    // Prefer one with description, then most recently updated.
    const withDesc = candidates.filter((c) => !isBlank(c.description_html));
    const list = withDesc.length > 0 ? withDesc : candidates;
    return newest(list);
}

type PlamodPayload = {
    // legacy, kept for backward compatibility if needed later
    source: 'plamod';
    content: null | {
        source: string;
        source_url: string | null;
        title: string | null;
        description_html: string | null;
        attributes: Record<string, string> | null;
        updated_at: string | null;
    };
    assets: ProductInfoAsset[];
};

const props = defineProps<{
    open: boolean;
    productId: string | null;
    productSku: string | null;
    productName: string | null;
    productPrice: string | null;
    onClose: () => void;
}>();

const loading = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
const contents = ref<ProductInfoContent[]>([]);
const assets = ref<ProductInfoAsset[]>([]);
const preferredDescriptionSource = ref<string | null>(null);
const photoSavesPending = ref(false);
const photosPanelRef = ref<{
    resetPhotoUi: () => void;
    loadHiddenSourcesFromStorage: () => void;
} | null>(null);
const availableSources = computed<Set<SourceKey>>(() => {
    const s = new Set<SourceKey>();
    for (const c of contents.value) s.add(normalizeSourceKey(c.source));
    for (const a of assets.value) {
        if (isImage(a)) s.add(normalizeSourceKey(a.source));
    }
    return s;
});

const selectedSource = ref<SelectedSourceState>({
    contentSource: 'plamod',
    descriptionMode: 'source',
});

const preferredContentSourceKey = computed<SourceKey>(() => {
    const avail = availableSources.value;
    const preferredRaw = preferredDescriptionSource.value;
    const preferred =
        typeof preferredRaw === 'string' && preferredRaw.trim() !== ''
            ? normalizeSourceKey(preferredRaw)
            : null;
    return preferred && avail.has(preferred) ? preferred : preferredContentSource(avail);
});

watch(
    preferredContentSourceKey,
    (contentSource) => {
        const keepManual =
            selectedSource.value.descriptionMode === 'manual' && contentSource === 'other';
        selectedSource.value = {
            contentSource,
            descriptionMode: keepManual ? 'manual' : 'source',
        };
    },
    { immediate: true },
);

const selectedContent = computed<ProductInfoContent | null>(() => {
    return contentForSource(contents.value, selectedSource.value.contentSource);
});

const title = computed<string>(
    () => selectedContent.value?.title || props.productSku || 'Product info',
);
const gridName = computed<string>(() => (props.productName ?? '').trim());

type DescriptionCard = {
    key: SourceKey;
    content: ProductInfoContent;
};

const descriptionCards = computed<DescriptionCard[]>(() => {
    const list = contents.value;
    const order: SourceKey[] = [
        'hlj',
        'newtype',
        'gundamhangar',
        'gundamplanet',
        'plamod',
        'bandai',
        'other',
    ];
    const out: DescriptionCard[] = [];
    for (const key of order) {
        const c = contentForSource(list, key);
        if (!c) continue;
        out.push({ key, content: c });
    }
    return out;
});

const manualDescriptionDraft = ref<string>('');
const manualDraftProductId = ref<string | null>(null);
const MANUAL_DRAFT_STORAGE_PREFIX = 'plamod_drawer:manual_description_draft:';
const MANUAL_BLANK_SENTINEL = '__ovs_manual_blank__';
const otherDefaultDescriptionHtml = computed<string | null>(() => {
    const other = contentForSource(contents.value, 'other');
    return other ? descriptionHtmlFor(other) : null;
});
const manualDraftIsDirty = computed<boolean>(() => {
    const draft = manualDescriptionDraft.value.trim();
    const saved = otherDefaultDescriptionHtml.value
        ? htmlToPlainText(otherDefaultDescriptionHtml.value)
        : '';
    return draft !== saved.trim();
});

function storageKeyForManualDraft(productId: string | null): string | null {
    const id = (productId ?? '').trim();
    if (!id) return null;
    return `${MANUAL_DRAFT_STORAGE_PREFIX}${id}`;
}

function loadManualDraftFromStorage(productId: string | null): string | null {
    const key = storageKeyForManualDraft(productId);
    if (!key) return null;
    try {
        const raw = window.localStorage.getItem(key);
        return typeof raw === 'string' ? raw : null;
    } catch {
        return null;
    }
}

function persistManualDraftToStorage(productId: string | null, value: string): void {
    const key = storageKeyForManualDraft(productId);
    if (!key) return;
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Best-effort only.
    }
}

function meaningfulStoredDraft(productId: string | null): string | null {
    const stored = loadManualDraftFromStorage(productId);
    if (stored === null) return null;
    if (stored === MANUAL_BLANK_SENTINEL) return '';
    if (stored.trim() === '') return null;
    return stored;
}

watch(
    () => [props.open, props.productId, otherDefaultDescriptionHtml.value] as const,
    ([open, productId, html]) => {
        if (!open) return;
        if (!productId) return;

        const stored = meaningfulStoredDraft(productId);
        const switchedProduct = manualDraftProductId.value !== productId;
        if (switchedProduct) {
            // On product switch, avoid seeding from stale previous-product HTML.
            // New product content may load asynchronously right after this branch.
            manualDescriptionDraft.value = stored ?? '';
            manualDraftProductId.value = productId;
            return;
        }

        // Same product: only seed when draft is empty.
        if (manualDescriptionDraft.value.trim() !== '') return;
        manualDescriptionDraft.value = stored ?? (html ? htmlToPlainText(html) : '');
    },
    { immediate: true },
);

watch(
    () => [props.open, props.productId, manualDescriptionDraft.value] as const,
    ([open, productId, draft]) => {
        if (!open || !productId) return;
        if (draft.trim() === '') return;
        persistManualDraftToStorage(productId, draft);
    },
);

function useDescriptionSource(key: SourceKey): void {
    selectedSource.value = {
        ...selectedSource.value,
        contentSource: key,
        descriptionMode: 'source',
    };
    void persistPreferredDescriptionSource(key);
}

function useManualDescription(): void {
    const html = manualDraftToHtml(manualDescriptionDraft.value) ?? '';
    selectedSource.value = {
        ...selectedSource.value,
        contentSource: 'other',
        descriptionMode: 'manual',
    };
    void persistPreferredDescriptionSource('other', html);
}

const savingPreferredDescription = ref(false);

async function persistPreferredDescriptionSource(
    key: SourceKey,
    manualDescriptionHtml: string | null = null,
): Promise<void> {
    if (!props.productId) return;
    savingPreferredDescription.value = true;
    error.value = null;
    message.value = null;
    const usingManual = key === 'other' && manualDescriptionHtml !== null;
    try {
        const payload: Record<string, unknown> = {
            preferred_description_source: key,
        };
        if (key === 'other' && manualDescriptionHtml !== null) {
            payload.manual_description_html = manualDescriptionHtml;
        }

        await api.patch(
            `/api/v1/products/${props.productId}/preferred-description-source`,
            payload,
        );
        preferredDescriptionSource.value = key;
        if (usingManual) {
            applyManualDescriptionLocally(manualDescriptionHtml);
        }
        if (usingManual) {
            selectedSource.value = {
                contentSource: 'other',
                descriptionMode: 'manual',
            };
            message.value = htmlFromManualIsBlank(manualDescriptionHtml)
                ? 'Blank manual description saved.'
                : 'Manual description saved.';
            persistManualDraftToStorage(
                props.productId,
                htmlFromManualIsBlank(manualDescriptionHtml)
                    ? MANUAL_BLANK_SENTINEL
                    : manualDescriptionDraft.value,
            );
        }
    } catch {
        error.value = 'Failed to save preferred description.';
    } finally {
        savingPreferredDescription.value = false;
    }
}

const attributes = computed<[string, string][]>(() => {
    const attrs = selectedContent.value?.attributes ?? null;
    if (!attrs) return [];
    return Object.entries(attrs)
        .map(([k, v]) => [k, typeof v === 'string' ? v : JSON.stringify(v)] as [string, string])
        .filter(([, v]) => v.trim() !== '');
});

const descriptionHtml = computed<string | null>(() => {
    const raw = selectedContent.value?.description_html ?? null;
    if (!raw) return null;
    const decoded = decodeHtmlEntitiesDeep(raw);
    const trimmed = decoded.trim();
    return trimmed !== '' ? trimmed : null;
});

function descriptionHtmlFor(content: ProductInfoContent): string | null {
    const raw = content.description_html ?? null;
    if (!raw) return null;
    const decoded = decodeHtmlEntitiesDeep(raw);
    const trimmed = decoded.trim();
    return trimmed !== '' ? trimmed : null;
}

function applyManualDescriptionLocally(html: string | null): void {
    const next = contents.value.slice();
    const idx = next.findIndex((c) => normalizeSourceKey(c.source) === 'other');
    const existing = idx >= 0 ? next[idx] : null;
    const row: ProductInfoContent = {
        source: 'other',
        source_url: existing?.source_url ?? null,
        title: existing?.title ?? 'Other',
        description_html: html,
        attributes: existing?.attributes ?? null,
        updated_at: new Date().toISOString(),
    };
    if (idx >= 0) next[idx] = row;
    else next.push(row);
    contents.value = next;
}

async function load(options?: { quiet?: boolean }): Promise<void> {
    if (!props.productId) return;
    const quiet = options?.quiet === true;
    if (!quiet) {
        loading.value = true;
    }
    error.value = null;
    try {
        const res = await api.get<{ data: ProductInfoPayload }>(
            `/api/v1/products/${props.productId}/product-info`,
        );
        const payload = res.data.data;
        contents.value = payload.contents ?? [];
        assets.value = payload.assets ?? [];
        preferredDescriptionSource.value = payload.preferred_description_source ?? null;
    } catch {
        error.value = 'Failed to load product info.';
    } finally {
        if (!quiet) {
            loading.value = false;
        }
    }
}

function resetDrawerState(): void {
    loading.value = false;
    error.value = null;
    message.value = null;
    contents.value = [];
    assets.value = [];
    preferredDescriptionSource.value = null;
    photoSavesPending.value = false;
    photosPanelRef.value?.resetPhotoUi();
    selectedSource.value = {
        contentSource: 'plamod',
        descriptionMode: 'source',
    };
    manualDescriptionDraft.value = '';
    manualDraftProductId.value = null;
    savingPreferredDescription.value = false;
}

watch(
    () => [props.open, props.productId] as const,
    ([open, productId], previous) => {
        if (!open) {
            resetDrawerState();
            return;
        }
        if (!productId) return;

        const previousWasOpen = previous?.[0] === true;
        const previousProductId = previous?.[1] ?? null;
        if (previousWasOpen && previousProductId !== productId) {
            resetDrawerState();
        }

        void load();
    },
    { immediate: true },
);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-black/30" @click="onClose" />

            <aside
                class="absolute right-0 top-0 flex h-full w-full max-w-5xl flex-col border-l border-slate-200 bg-white shadow-xl"
            >
                <div
                    class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3"
                >
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
                        <div
                            v-if="gridName && gridName !== title"
                            class="mt-0.5 text-sm font-semibold text-slate-900"
                        >
                            {{ gridName }}
                        </div>
                        <p class="mt-0.5 text-xs text-slate-600">
                            PDP content & assets
                            (Plamod/HLJ/Bandai/GundamPlanet/Newtype/GundamHangar) with source
                            attribution
                        </p>
                    </div>

                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-700 transition hover:bg-slate-50"
                        @click="onClose"
                    >
                        Close
                    </button>
                </div>

                <div class="flex-1 space-y-3 overflow-auto p-4">
                    <div
                        v-if="error"
                        class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                    >
                        {{ error }}
                    </div>
                    <div
                        v-if="message"
                        class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                    >
                        {{ message }}
                    </div>
                    <div
                        v-if="photoSavesPending"
                        class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900"
                        data-testid="photo-saves-pending-drawer"
                    >
                        Saving photo changes…
                    </div>

                    <div v-if="loading" class="text-sm text-slate-600">Loading…</div>

                    <div
                        v-if="!loading && attributes.length > 0"
                        class="rounded-md border border-slate-200 bg-white p-3"
                    >
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Attributes
                        </div>
                        <dl class="mt-2 divide-y divide-slate-100">
                            <div
                                v-for="[k, v] in attributes"
                                :key="k"
                                class="grid grid-cols-3 gap-3 py-2 text-sm"
                            >
                                <dt class="col-span-1 font-medium text-slate-700">{{ k }}</dt>
                                <dd class="col-span-2 text-slate-900">{{ v }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div v-if="!loading" class="rounded-md border border-slate-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div
                                class="text-xs font-semibold uppercase tracking-wide text-slate-500"
                            >
                                Descriptions
                            </div>
                            <div class="text-xs text-slate-500">
                                {{ descriptionCards.length }} source(s)
                            </div>
                        </div>

                        <div
                            v-if="descriptionCards.length === 0"
                            class="mt-2 text-sm text-slate-600"
                        >
                            No descriptions found yet.
                        </div>

                        <div class="mt-2 grid gap-3 lg:grid-cols-2">
                            <div
                                v-for="card in descriptionCards"
                                :key="card.key"
                                class="rounded-md border p-3"
                                :class="
                                    selectedSource.descriptionMode === 'source' &&
                                    card.key === selectedSource.contentSource
                                        ? 'border-slate-900 bg-slate-50'
                                        : 'border-slate-200 bg-white hover:border-slate-300'
                                "
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div
                                                class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800"
                                            >
                                                {{ SOURCE_LABELS[card.key] ?? card.key }}
                                            </div>
                                            <div
                                                v-if="card.content.updated_at"
                                                class="text-xs text-slate-500"
                                            >
                                                updated {{ card.content.updated_at.slice(0, 10) }}
                                            </div>
                                        </div>
                                        <div
                                            class="mt-1 truncate text-sm font-semibold text-slate-900"
                                        >
                                            {{ card.content.title ?? '—' }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-600">
                                            <a
                                                v-if="card.content.source_url"
                                                class="font-semibold text-slate-900 underline"
                                                :href="card.content.source_url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Open source
                                            </a>
                                            <span v-else>—</span>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-md border px-3 py-1.5 text-xs font-semibold transition"
                                        :class="
                                            selectedSource.descriptionMode === 'source' &&
                                            card.key === selectedSource.contentSource
                                                ? 'border-slate-900 bg-slate-900 text-white hover:bg-slate-800'
                                                : 'border-slate-200 bg-white text-slate-900 hover:bg-slate-50'
                                        "
                                        :disabled="savingPreferredDescription"
                                        @click="useDescriptionSource(card.key)"
                                    >
                                        {{
                                            selectedSource.descriptionMode === 'source' &&
                                            card.key === selectedSource.contentSource
                                                ? 'Using'
                                                : 'Use this'
                                        }}
                                    </button>
                                </div>

                                <div
                                    class="mt-2 max-h-40 overflow-auto rounded-md border border-slate-100 bg-white p-2"
                                >
                                    <div
                                        v-if="descriptionHtmlFor(card.content)"
                                        class="prose prose-slate max-w-none text-sm"
                                        v-html="descriptionHtmlFor(card.content)"
                                    />
                                    <div v-else class="text-sm text-slate-600">
                                        No description text.
                                    </div>
                                </div>
                            </div>

                            <div
                                class="rounded-md border p-3"
                                :class="
                                    selectedSource.descriptionMode === 'manual'
                                        ? 'border-slate-900 bg-slate-50'
                                        : 'border-slate-200 bg-white hover:border-slate-300'
                                "
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div
                                                class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800"
                                            >
                                                Manual
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                edit, then save
                                            </div>
                                        </div>
                                        <div
                                            class="mt-1 truncate text-sm font-semibold text-slate-900"
                                        >
                                            Editable draft
                                        </div>
                                        <div class="mt-1 text-xs text-slate-600">
                                            Save &amp; use keeps this text, including a blank box.
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-md border px-3 py-1.5 text-xs font-semibold transition"
                                        :class="
                                            selectedSource.descriptionMode === 'manual'
                                                ? 'border-slate-900 bg-slate-900 text-white hover:bg-slate-800'
                                                : 'border-slate-200 bg-white text-slate-900 hover:bg-slate-50'
                                        "
                                        :disabled="savingPreferredDescription"
                                        @click="useManualDescription"
                                    >
                                        {{
                                            selectedSource.descriptionMode === 'manual' &&
                                            !manualDraftIsDirty
                                                ? 'Using'
                                                : manualDraftIsDirty
                                                  ? 'Save & use'
                                                  : 'Use this'
                                        }}
                                    </button>
                                </div>

                                <div
                                    class="mt-2 max-h-40 overflow-auto rounded-md border border-slate-100 bg-white p-2"
                                >
                                    <textarea
                                        data-testid="description-editor-manual"
                                        v-model="manualDescriptionDraft"
                                        class="h-32 w-full resize-none border-0 bg-transparent p-0 text-sm leading-5 text-slate-900 outline-none"
                                        :placeholder="
                                            otherDefaultDescriptionHtml ? '' : 'Type a description…'
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <ProductInfoPhotosPanel
                        ref="photosPanelRef"
                        v-model:assets="assets"
                        :product-id="productId"
                        @error="error = $event"
                        @message="message = $event"
                        @pending="photoSavesPending = $event"
                    />
                </div>
            </aside>
        </div>
    </Teleport>
</template>
