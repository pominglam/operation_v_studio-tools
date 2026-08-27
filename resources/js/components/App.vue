<script setup lang="ts">
import { RouterView, useRoute } from 'vue-router';
import { isFullWidthAppPath, isStandaloneAppChromePath } from '../lib/appLayout';
import { employeeInventoryScanNotFoundBg } from '../lib/employeeInventoryScanUi';
import AppNav from './AppNav.vue';

const route = useRoute();
</script>

<template>
    <div
        class="min-h-screen text-slate-900 transition-[background-color] duration-300 ease-out"
        :class="
            employeeInventoryScanNotFoundBg
                ? 'bg-red-500'
                : isStandaloneAppChromePath(route.path)
                  ? 'bg-white'
                  : 'bg-slate-50'
        "
    >
        <AppNav v-if="!isStandaloneAppChromePath(route.path)" />
        <main
            class="mx-auto w-full"
            :class="
                isStandaloneAppChromePath(route.path)
                    ? 'max-w-none px-0 py-0'
                    : isFullWidthAppPath(route.path)
                      ? 'max-w-none px-4 py-6'
                      : 'max-w-screen-2xl px-4 py-6'
            "
        >
            <RouterView />
        </main>
    </div>
</template>
