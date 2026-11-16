<script setup lang="ts">
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const titulo = computed(() => props.breadcrumbs.at(-1)?.title ?? '');
</script>

<template>
    <!-- Lean header (DEC-070): on mobile 48 px with the menu button and the page title; the
         breadcrumbs stay on desktop. -->
    <header
        class="flex h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-4 transition-[width,height] ease-linear md:h-16 md:group-has-data-[collapsible=icon]/sidebar-wrapper:h-12"
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <h1 v-if="titulo" class="truncate text-lg font-semibold md:hidden">
                {{ titulo }}
            </h1>
            <Breadcrumbs
                v-if="breadcrumbs.length > 0"
                class="hidden md:flex"
                :breadcrumbs="breadcrumbs"
            />
        </div>
    </header>
</template>
