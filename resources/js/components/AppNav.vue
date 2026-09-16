<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import logoUrl from '../assets/operation-v-logo.svg?url';
import { currentAccessRole } from '../lib/accessRole';
import { employeeInventoryScanNotFoundBg } from '../lib/employeeInventoryScanUi';
import { ADMIN_NAV_ENTRIES, navRouteContext } from '../lib/navCatalog';
import AppNavDropdown from './AppNavDropdown.vue';

const route = useRoute();
const isEmployee = currentAccessRole() === 'employee';
const headerEl = ref<HTMLElement | null>(null);

const routeContext = computed(() => navRouteContext(route.path, route.name));

function setNavHeight(): void {
    const el = headerEl.value;
    if (!(el instanceof HTMLElement)) {
        return;
    }
    document.documentElement.style.setProperty('--app-nav-height', `${el.offsetHeight}px`);
}

onMounted(() => {
    setNavHeight();
    window.addEventListener('resize', setNavHeight);
});

onUnmounted(() => {
    window.removeEventListener('resize', setNavHeight);
});

function linkClass(active: boolean): string {
    return active ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100';
}
</script>

<template>
    <header
        ref="headerEl"
        class="sticky top-0 z-40 border-b transition-[background-color,border-color] duration-300 ease-out"
        :class="
            isEmployee && employeeInventoryScanNotFoundBg
                ? 'border-red-700 bg-red-500'
                : 'border-slate-200 bg-white'
        "
    >
        <div class="mx-auto flex w-full max-w-screen-2xl items-center justify-between px-4 py-3">
            <div class="flex items-center gap-3">
                <img
                    :src="logoUrl"
                    alt="Operation V"
                    class="h-[52px] w-auto max-w-[240px] shrink-0 object-contain"
                    loading="eager"
                />
            </div>

            <nav class="flex flex-wrap items-center justify-end gap-2 text-sm">
                <RouterLink
                    v-if="isEmployee"
                    to="/employee/inventory-count"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="
                        route.path.startsWith('/employee/inventory-count')
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                    "
                >
                    Inventory Count
                </RouterLink>

                <template v-if="!isEmployee">
                    <template v-for="entry in ADMIN_NAV_ENTRIES" :key="entry.id">
                        <AppNavDropdown
                            v-if="entry.kind === 'group'"
                            :label="entry.label"
                            :active="entry.isActive(routeContext)"
                            :children="entry.children"
                            :route-context="routeContext"
                        />
                        <RouterLink
                            v-else
                            :to="entry.path"
                            class="rounded-md px-3 py-1.5 transition"
                            :class="linkClass(entry.isActive(routeContext))"
                        >
                            {{ entry.label }}
                        </RouterLink>
                    </template>
                </template>
            </nav>
        </div>
    </header>
</template>
