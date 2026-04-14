<script setup lang="ts">
import { RouterView, useRoute } from 'vue-router';
import { employeeInventoryScanNotFoundBg } from '../lib/employeeInventoryScanUi';
import AppNav from './AppNav.vue';

const route = useRoute();

function isFullWidthPath(path: string): boolean {
    return path.startsWith('/price-research');
}
</script>

<template>
    <div
        class="min-h-screen text-slate-900 transition-[background-color] duration-300 ease-out"
        :class="employeeInventoryScanNotFoundBg ? 'bg-red-500' : 'bg-slate-50'"
    >
        <AppNav />
        <main
            class="mx-auto w-full px-4 py-6"
            :class="isFullWidthPath(route.path) ? 'max-w-none' : 'max-w-screen-2xl'"
        >
            <RouterView />
        </main>
    </div>
</template>
