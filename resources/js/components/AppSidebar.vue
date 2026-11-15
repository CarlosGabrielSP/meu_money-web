<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    House,
    Inbox,
    Settings,
    ShieldCheck,
    Tags,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { home } from '@/routes';
import { index as usuariosIndex } from '@/routes/admin/usuarios';
import { index as carteirasIndex } from '@/routes/carteiras';
import { index as categoriasIndex } from '@/routes/categorias';
import { index as contatosIndex } from '@/routes/contatos';
import { index as recebidasIndex } from '@/routes/recebidas';
import { edit as perfilEdit } from '@/routes/profile';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Início',
        href: home(),
        icon: House,
    },
    {
        title: 'Carteiras',
        href: carteirasIndex(),
        icon: Wallet,
    },
    {
        title: 'Categorias',
        href: categoriasIndex(),
        icon: Tags,
    },
    {
        title: 'Contatos',
        href: contatosIndex(),
        icon: Users,
        contador: page.props.contadores.convites,
    },
    {
        title: 'Recebidas',
        href: recebidasIndex(),
        icon: Inbox,
        contador: page.props.contadores.recebidas,
    },
    {
        title: 'Configurações',
        href: perfilEdit(),
        icon: Settings,
    },
    ...(page.props.auth.user.is_admin
        ? [
              {
                  title: 'Administração',
                  href: usuariosIndex(),
                  icon: ShieldCheck,
              },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
