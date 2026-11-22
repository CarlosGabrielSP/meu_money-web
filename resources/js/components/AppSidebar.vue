<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Calculator,
    ChartPie,
    House,
    Inbox,
    Plus,
    ShieldCheck,
    Tags,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted } from 'vue';
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
    SidebarTrigger,
    useSidebar,
} from '@/components/ui/sidebar';
import { useModoSimples } from '@/composables/useModoSimples';
import { useNovoLancamento } from '@/composables/useNovoLancamento';
import { home, relatorio, simulacoes } from '@/routes';
import { index as usuariosIndex } from '@/routes/admin/usuarios';
import { index as carteirasIndex } from '@/routes/carteiras';
import { index as categoriasIndex } from '@/routes/categorias';
import { index as contatosIndex } from '@/routes/contatos';
import { index as recebidasIndex } from '@/routes/recebidas';
import type { CarteiraResumo, NavItem } from '@/types';

const page = usePage();
const modoSimples = useModoSimples();
const { adicionar } = useNovoLancamento();
const { isMobile, setOpenMobile } = useSidebar();

/**
 * On mobile the menu closes on any visit started from it: a menu item, the logo or the user menu
 * (DEC-075). Every visit counts, so a tap on the current page closes it too.
 */
let pararDeOuvir: (() => void) | null = null;

onMounted(() => {
    pararDeOuvir = router.on('start', () => setOpenMobile(false));
});

onUnmounted(() => pararDeOuvir?.());

const administracao = computed<NavItem[]>(() =>
    page.props.auth.user.is_admin
        ? [
              {
                  title: 'Administração',
                  href: usuariosIndex(),
                  icon: ShieldCheck,
              },
          ]
        : [],
);

const itemSimulacoes = computed<NavItem>(() => {
    const carteira = page.props.carteira as CarteiraResumo | undefined;

    return {
        title: 'Simulações',
        href: simulacoes(
            page.component === 'Inicio' && carteira?.tipo === 'comum'
                ? { query: { carteira: carteira.id } }
                : undefined,
        ),
        icon: Calculator,
    };
});

/**
 * The simple mode keeps only Início, Adicionar, Relatório, Carteiras (to create a card, DEC-087) and
 * Recebidas and Simulações; the other pages stay reachable by address. Configurações is in the user menu, as in the
 * full mode (RN-163, DEC-075).
 */
const navSimples = computed<NavItem[]>(() => [
    {
        title: 'Início',
        href: home(),
        icon: House,
    },
    {
        title: 'Adicionar',
        href: home(),
        icon: Plus,
        acao: adicionar,
    },
    {
        title: 'Relatório',
        href: relatorio(),
        icon: ChartPie,
    },
    itemSimulacoes.value,
    {
        title: 'Carteiras',
        href: carteirasIndex(),
        icon: Wallet,
        contador: page.props.contadores.membros,
    },
    {
        title: 'Recebidas',
        href: recebidasIndex(),
        icon: Inbox,
        contador: page.props.contadores.recebidas,
        tutorial: 'recebidas',
    },
    ...administracao.value,
]);

const navCompleta = computed<NavItem[]>(() => [
    {
        title: 'Início',
        href: home(),
        icon: House,
    },
    {
        title: 'Relatório',
        href: relatorio(),
        icon: ChartPie,
    },
    itemSimulacoes.value,
    {
        title: 'Carteiras',
        href: carteirasIndex(),
        icon: Wallet,
        contador: page.props.contadores.membros,
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
        tutorial: 'recebidas',
    },
    ...administracao.value,
]);

const mainNavItems = computed(() =>
    modoSimples.value ? navSimples.value : navCompleta.value,
);
</script>

<template>
    <!-- On mobile the menu opens from the right, next to "Mais" (DEC-075). -->
    <Sidebar
        collapsible="icon"
        variant="inset"
        :side="isMobile ? 'right' : 'left'"
    >
        <!-- Without the top bar (DEC-078), the desktop collapse button lives next to the logo;
             on mobile the menu opens from "Mais". -->
        <SidebarHeader>
            <div
                class="flex items-center gap-1 group-data-[collapsible=icon]:flex-col"
            >
                <SidebarMenu class="min-w-0 flex-1">
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" as-child>
                            <Link :href="home()">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarTrigger v-if="!isMobile" class="shrink-0" />
            </div>
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
