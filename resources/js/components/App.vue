<script setup lang="ts">
import { RouterView, useRoute } from 'vue-router';
import AppNav from './AppNav.vue';

const route = useRoute();

function isFullWidthPath(path: string): boolean {
    return path.startsWith('/price-research');
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <AppNav />
        <main
            class="mx-auto w-full px-4 py-6"
            :class="isFullWidthPath(route.path) ? 'max-w-none' : 'max-w-screen-2xl'"
        >
            <RouterView />
        </main>
    </div>
</template>
