<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, RouterView, useRoute } from 'vue-router';
import { REPORT_DEFINITIONS, reportDefinitionForRouteName } from '../lib/reportsCatalog';

const route = useRoute();

const activeReport = computed(() => reportDefinitionForRouteName(String(route.name ?? '')));

function navLinkClass(routeName: string): string {
    const active = route.name === routeName;
    return active
        ? 'border-slate-900 bg-slate-900 text-white'
        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50';
}
</script>

<template>
    <section class="space-y-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Reports</h1>
            <p v-if="activeReport" class="mt-1 text-sm text-slate-600">
                {{ activeReport.description }}
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
            <aside class="rounded-lg border border-slate-200 bg-white p-2">
                <nav class="flex flex-col gap-1" aria-label="Reports">
                    <RouterLink
                        v-for="report in REPORT_DEFINITIONS"
                        :key="report.id"
                        :to="report.path"
                        class="rounded-md border px-3 py-2 text-sm font-medium transition"
                        :class="navLinkClass(report.routeName)"
                    >
                        {{ report.label }}
                    </RouterLink>
                </nav>
            </aside>

            <div class="min-w-0">
                <RouterView />
            </div>
        </div>
    </section>
</template>
