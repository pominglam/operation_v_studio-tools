<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import type { NavChildLink, NavRouteContext } from '../lib/navCatalog';

const props = defineProps<{
    label: string;
    active: boolean;
    children: NavChildLink[];
    routeContext: NavRouteContext;
}>();

const open = ref(false);
const rootEl = ref<HTMLElement | null>(null);

function toggleOpen(): void {
    open.value = !open.value;
}

function close(): void {
    open.value = false;
}

function onDocumentPointerDown(event: MouseEvent): void {
    const target = event.target;
    if (!(target instanceof Node)) {
        return;
    }
    if (rootEl.value?.contains(target)) {
        return;
    }
    close();
}

function onDocumentKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close();
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown);
    document.addEventListener('keydown', onDocumentKeyDown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
    document.removeEventListener('keydown', onDocumentKeyDown);
});

function childActive(child: NavChildLink): boolean {
    return child.isActive(props.routeContext);
}

function triggerClass(): string {
    return props.active
        ? 'bg-slate-900 text-white'
        : 'text-slate-700 hover:bg-slate-100';
}
</script>

<template>
    <div ref="rootEl" class="relative">
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 transition"
            :class="triggerClass()"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="toggleOpen"
        >
            <span>{{ label }}</span>
            <span class="text-xs opacity-70" aria-hidden="true">{{ open ? '▴' : '▾' }}</span>
        </button>

        <div
            v-show="open"
            class="absolute right-0 z-50 mt-1 min-w-[12rem] rounded-md border border-slate-200 bg-white py-1 shadow-lg"
            role="menu"
        >
            <RouterLink
                v-for="child in children"
                :key="child.id"
                :to="child.path"
                role="menuitem"
                class="block px-3 py-2 text-sm transition"
                :class="
                    childActive(child)
                        ? 'bg-slate-100 font-medium text-slate-900'
                        : 'text-slate-700 hover:bg-slate-50'
                "
                @click="close"
            >
                {{ child.label }}
            </RouterLink>
        </div>
    </div>
</template>
