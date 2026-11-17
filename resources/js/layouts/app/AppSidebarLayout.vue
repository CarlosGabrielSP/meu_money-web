<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import BarraInferior from '@/components/BarraInferior.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

/**
 * Pages still declare breadcrumbs, but the top bar that showed them was removed (DEC-078); the prop
 * is kept so they do not fall through as an HTML attribute.
 */
type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="min-w-0 overflow-x-clip pb-28 md:pb-0"
        >
            <slot />
        </AppContent>
        <BarraInferior />
        <Toaster />
    </AppShell>
</template>
