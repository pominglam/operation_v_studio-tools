<script setup lang="ts">
import { computed, ref } from 'vue';

type Entry = {
    raw: string;
    source: string;
    event: string | null;
    kind:
        | 'gp_plan'
        | 'gp_search_try'
        | 'gp_search_picked'
        | 'gp_pdp'
        | 'gp_images'
        | 'source_step'
        | 'job'
        | 'other';
    q?: string;
    url?: string;
    pdp?: string;
    score?: string;
    count?: string;
    title?: string;
    sources?: string;
    result?: string;
    attempted?: string;
    downloaded?: string;
    skipped_non_200?: string;
    skipped_non_image?: string;
    skipped_empty?: string;
    message?: string;
    duration_ms?: string;
    assets?: string;
    has_description?: string;
    processed?: string;
    quotes_written?: string;
    failed_sources?: string;
};

const props = defineProps<{
    debugLog: string;
    maxHeight?: string;
}>();

const wrap = ref(true);
const showRaw = ref(false);
const maxHeight = computed<string>(() => props.maxHeight ?? '14rem'); // ~ max-h-56

const lines = computed<string[]>(() =>
    props.debugLog
        .split('\n')
        .map((l) => l.trimEnd())
        .filter((l) => l.trim() !== ''),
);

function parseLine(raw: string): Entry {
    // Formats:
    // - [job] sources=...
    // - [gundamplanet][plan] q=... url=...
    // - [gundamplanet][search_try] q=... url=...
    // - [gundamplanet][search_picked] ... pdp=... score=... title=...
    const m = raw.match(/^\[([^\]]+)\](?:\[([^\]]+)\])?\s*(.*)$/);
    const source = (m?.[1] ?? 'other').trim();
    const event = m?.[2] ? m[2].trim() : null;
    const rest = (m?.[3] ?? '').trim();

    const e: Entry = {
        raw,
        source,
        event,
        kind: 'other',
    };

    if (source === 'job') {
        e.kind = 'job';
        const mm = rest.match(/\bsources=([^\s]+)\b/);
        if (mm) e.sources = mm[1];
        const sm = rest.match(/\bresult=([^\s]+)\b/);
        if (sm) e.result = sm[1];
        const fm = rest.match(/\bfailed_sources=([^\s]+)\b/);
        if (fm) e.failed_sources = fm[1];
        return e;
    }

    if (source === 'gundamplanet') {
        if (event === 'plan') {
            e.kind = 'gp_plan';
            // q can contain spaces; url does not.
            const mm = rest.match(/\bq=(.*)\s+url=(\S+)\b/);
            if (mm) {
                e.q = mm[1]?.trim();
                e.url = mm[2]?.trim();
                return e;
            }
            const c = rest.match(/\bterms_count=(\d+)\b/);
            if (c) {
                e.count = c[1]?.trim();
            }
            return e;
        }

        if (event === 'search_try') {
            e.kind = 'gp_search_try';
            const mm = rest.match(/\bq=(.*)\s+url=(\S+)\b/);
            if (mm) {
                e.q = mm[1]?.trim();
                e.url = mm[2]?.trim();
            }
            return e;
        }

        if (event === 'search_picked') {
            e.kind = 'gp_search_picked';
            const qmm = rest.match(/\bq=(.*)\s+cands=(\d+)\s+pdp=(\S+)\s+score=([0-9.]+)\s+title=(.*)$/);
            if (qmm) {
                e.q = qmm[1]?.trim();
                e.count = qmm[2]?.trim();
                e.pdp = qmm[3]?.trim();
                e.score = qmm[4]?.trim();
                e.title = qmm[5]?.trim();
            }
            return e;
        }

        if (event === 'pdp_found' || event === 'pdp_not_found' || event === 'pdp_fetch_failed') {
            e.kind = 'gp_pdp';
            const pdp = rest.match(/\bpdp=(\S+)/)?.[1]?.trim();
            if (pdp) e.pdp = pdp;
            const term = rest.match(/\bterm=(.*)\s+score=/)?.[1]?.trim();
            if (term) e.q = term;
            const score = rest.match(/\bscore=([0-9.]+)/)?.[1]?.trim();
            if (score) e.score = score;
            return e;
        }

        if (event === 'images_extracted') {
            e.kind = 'gp_images';
            const count = rest.match(/\bcount=(\d+)/)?.[1]?.trim();
            if (count) e.count = count;
            const pdp = rest.match(/\bpdp=(\S+)/)?.[1]?.trim();
            if (pdp) e.pdp = pdp;
            return e;
        }

        if (event === 'images_downloaded') {
            e.kind = 'gp_images';
            e.attempted = rest.match(/\battempted=(\d+)/)?.[1]?.trim();
            e.downloaded = rest.match(/\bdownloaded=(\d+)/)?.[1]?.trim();
            e.skipped_non_200 = rest.match(/\bskipped_non_200=(\d+)/)?.[1]?.trim();
            e.skipped_non_image = rest.match(/\bskipped_non_image=(\d+)/)?.[1]?.trim();
            e.skipped_empty = rest.match(/\bskipped_empty=(\d+)/)?.[1]?.trim();
            return e;
        }

        if (event === 'summary') {
            e.kind = 'gp_pdp';
            e.result = rest.match(/\bresult=([^\s]+)/)?.[1]?.trim();
            e.count = rest.match(/\bimages_extracted=(\d+)/)?.[1]?.trim() ?? undefined;
            e.downloaded = rest.match(/\bimages_downloaded=(\d+)/)?.[1]?.trim();
            const pdp = rest.match(/\bpdp=(\S+)/)?.[1]?.trim();
            if (pdp) e.pdp = pdp;
            return e;
        }
    }

    // Standardized crawler logs: [source][start|done|error] key=val ...
    if (['plamod', 'hlj', 'bandai', 'gundamhangar', 'competitor_price_research'].includes(source)) {
        e.kind = 'source_step';
        // Back-compat: older format was "[plamod] start" (event missing, verb in rest).
        if (!e.event && rest && !rest.includes('=')) {
            e.event = rest.split(/\s+/)[0] ?? null;
        }
        e.result = rest.match(/\bresult=([^\s]+)\b/)?.[1]?.trim();
        e.duration_ms = rest.match(/\bduration_ms=([^\s]+)\b/)?.[1]?.trim();
        e.assets = rest.match(/\bassets=([^\s]+)\b/)?.[1]?.trim();
        e.has_description = rest.match(/\bhas_description=([^\s]+)\b/)?.[1]?.trim();
        e.processed = rest.match(/\bprocessed=([^\s]+)\b/)?.[1]?.trim();
        e.quotes_written = rest.match(/\bquotes_written=([^\s]+)\b/)?.[1]?.trim();
        // message may contain spaces; take everything after "message=".
        const msgIdx = rest.indexOf('message=');
        if (msgIdx >= 0) {
            e.message = rest.slice(msgIdx + 'message='.length).trim();
        }
        return e;
    }

    return e;
}

const entries = computed<Entry[]>(() => lines.value.map(parseLine));

const sourceChips = computed<string[]>(() => {
    const seen = new Set<string>();
    for (const e of entries.value) {
        if (e.source === 'other') continue;
        seen.add(e.source);
    }
    return Array.from(seen);
});

async function copyAll(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.debugLog);
    } catch {
        // ignore (browser permission / insecure context)
    }
}

function badgeClass(source: string): string {
    if (source === 'gundamplanet') return 'bg-violet-100 text-violet-800 border-violet-200';
    if (source === 'hlj') return 'bg-sky-100 text-sky-800 border-sky-200';
    if (source === 'bandai') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
    if (source === 'gundamhangar') return 'bg-teal-100 text-teal-800 border-teal-200';
    if (source === 'plamod') return 'bg-slate-100 text-slate-800 border-slate-200';
    if (source === 'job') return 'bg-amber-100 text-amber-900 border-amber-200';
    return 'bg-slate-100 text-slate-800 border-slate-200';
}
</script>

<template>
    <div class="rounded border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-3 py-2">
            <div class="flex flex-wrap items-center gap-2">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">Debug</div>
                <div class="flex flex-wrap items-center gap-1">
                    <span
                        v-for="s in sourceChips"
                        :key="s"
                        class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                        :class="badgeClass(s)"
                    >
                        {{ s }}
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-900 hover:bg-slate-50"
                    @click="copyAll"
                >
                    Copy
                </button>
                <label class="flex items-center gap-1 text-xs text-slate-700">
                    <input v-model="wrap" type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300" />
                    Wrap
                </label>
                <label class="flex items-center gap-1 text-xs text-slate-700">
                    <input v-model="showRaw" type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300" />
                    Raw
                </label>
            </div>
        </div>

        <div class="overflow-auto px-3 py-2" :style="{ maxHeight }">
            <div v-if="entries.length === 0" class="text-sm text-slate-600">—</div>
            <ul v-else class="space-y-2">
                <li v-for="(e, idx) in entries" :key="idx" class="rounded border border-slate-100 bg-slate-50 px-2 py-1.5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                :class="badgeClass(e.source)"
                            >
                                {{ e.source }}
                            </span>
                            <span
                                v-if="e.event"
                                class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-800"
                            >
                                {{ e.event }}
                            </span>
                        </div>
                    </div>

                    <div v-if="e.kind === 'job' && e.sources" class="mt-1 text-sm text-slate-800">
                        <span class="font-semibold">sources</span>: <span class="font-mono">{{ e.sources }}</span>
                    </div>

                    <div v-else-if="e.kind === 'source_step'" class="mt-1 text-sm text-slate-800">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <div v-if="e.event"><span class="font-semibold">event</span>: {{ e.event }}</div>
                            <div v-if="e.result"><span class="font-semibold">result</span>: {{ e.result }}</div>
                            <div v-if="e.duration_ms"><span class="font-semibold">duration</span>: {{ e.duration_ms }}ms</div>
                        </div>
                        <div v-if="e.assets || e.has_description || e.processed || e.quotes_written" class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600">
                            <div v-if="e.assets">assets {{ e.assets }}</div>
                            <div v-if="e.has_description">has_desc {{ e.has_description }}</div>
                            <div v-if="e.processed">processed {{ e.processed }}</div>
                            <div v-if="e.quotes_written">quotes {{ e.quotes_written }}</div>
                        </div>
                        <div v-if="e.message" class="mt-0.5">
                            <span class="font-semibold">message</span>: {{ e.message }}
                        </div>
                    </div>

                    <div v-else-if="e.kind === 'gp_plan' || e.kind === 'gp_search_try'" class="mt-1 text-sm text-slate-800">
                        <div v-if="e.kind === 'gp_plan' && e.count && !e.url">
                            <span class="font-semibold">terms</span>: {{ e.count }}
                        </div>
                        <div v-if="e.q">
                            <span class="font-semibold">query</span>:
                            <span class="font-mono">{{ e.q }}</span>
                        </div>
                        <div v-if="e.url" class="mt-0.5">
                            <span class="font-semibold">url</span>:
                            <a class="font-mono text-sky-700 hover:underline" :href="e.url" target="_blank" rel="noreferrer">
                                {{ e.url }}
                            </a>
                        </div>
                    </div>

                    <div v-else-if="e.kind === 'gp_search_picked'" class="mt-1 text-sm text-slate-800">
                        <div v-if="e.q">
                            <span class="font-semibold">query</span>: <span class="font-mono">{{ e.q }}</span>
                        </div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <div v-if="e.count"><span class="font-semibold">candidates</span>: {{ e.count }}</div>
                            <div v-if="e.score"><span class="font-semibold">score</span>: {{ e.score }}</div>
                        </div>
                        <div v-if="e.pdp" class="mt-0.5">
                            <span class="font-semibold">pdp</span>:
                            <a class="font-mono text-sky-700 hover:underline" :href="e.pdp" target="_blank" rel="noreferrer">
                                {{ e.pdp }}
                            </a>
                        </div>
                        <div v-if="e.title" class="mt-0.5">
                            <span class="font-semibold">title</span>: {{ e.title }}
                        </div>
                    </div>

                    <div v-else-if="e.kind === 'gp_pdp' || e.kind === 'gp_images'" class="mt-1 text-sm text-slate-800">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <div v-if="e.count"><span class="font-semibold">count</span>: {{ e.count }}</div>
                            <div v-if="e.score"><span class="font-semibold">score</span>: {{ e.score }}</div>
                            <div v-if="e.result"><span class="font-semibold">result</span>: {{ e.result }}</div>
                            <div v-if="e.downloaded"><span class="font-semibold">downloaded</span>: {{ e.downloaded }}</div>
                        </div>
                        <div v-if="e.attempted || e.skipped_non_200 || e.skipped_non_image || e.skipped_empty" class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600">
                            <div v-if="e.attempted">attempted {{ e.attempted }}</div>
                            <div v-if="e.skipped_non_200">non-200 {{ e.skipped_non_200 }}</div>
                            <div v-if="e.skipped_non_image">non-image {{ e.skipped_non_image }}</div>
                            <div v-if="e.skipped_empty">empty {{ e.skipped_empty }}</div>
                        </div>
                        <div v-if="e.pdp" class="mt-0.5">
                            <span class="font-semibold">pdp</span>:
                            <a class="font-mono text-sky-700 hover:underline" :href="e.pdp" target="_blank" rel="noreferrer">
                                {{ e.pdp }}
                            </a>
                        </div>
                    </div>

                    <pre
                        v-if="showRaw"
                        class="mt-2 rounded border border-slate-200 bg-white p-2 text-[11px] leading-4 text-slate-800"
                        :class="wrap ? 'whitespace-pre-wrap break-words' : 'whitespace-pre'"
                    >{{ e.raw }}</pre>
                </li>
            </ul>
        </div>
    </div>
</template>

