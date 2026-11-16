<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();
const { setOpenMobile } = useSidebar();

/**
 * An action item has no navigation to close the mobile menu, so it closes it first.
 */
function executar(acao: () => void): void {
    setOpenMobile(false);
    acao();
}
</script>

<template>
    <SidebarGroup class="px-2 py-0" data-tutorial="menu">
        <SidebarGroupLabel>Menu</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem
                v-for="item in items"
                :key="item.title"
                :data-tutorial="item.tutorial"
            >
                <SidebarMenuButton
                    v-if="item.disabled"
                    disabled
                    :tooltip="`${item.title} (em breve)`"
                >
                    <component :is="item.icon" />
                    <span>{{ item.title }}</span>
                </SidebarMenuButton>
                <SidebarMenuButton
                    v-else-if="item.acao"
                    :tooltip="item.title"
                    @click="executar(item.acao)"
                >
                    <component :is="item.icon" />
                    <span>{{ item.title }}</span>
                </SidebarMenuButton>
                <SidebarMenuButton
                    v-else
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                    class="data-[active=true]:bg-primary data-[active=true]:text-primary-foreground data-[active=true]:hover:bg-primary/90 data-[active=true]:hover:text-primary-foreground"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <span
                            v-if="item.contador"
                            class="ml-auto rounded-full bg-destaque px-1.5 text-xs text-destaque-foreground"
                            :aria-label="`${item.contador} pendentes`"
                            >{{ item.contador }}</span
                        >
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
