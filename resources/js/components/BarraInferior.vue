<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartPie, House, Menu, Plus, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useModoSimples } from '@/composables/useModoSimples';
import { useNovoLancamento } from '@/composables/useNovoLancamento';
import { home, relatorio } from '@/routes';
import { index as carteirasIndex } from '@/routes/carteiras';

const page = usePage();
const { setOpenMobile } = useSidebar();
const { adicionar } = useNovoLancamento();
const modoSimples = useModoSimples();
const { isCurrentUrl } = useCurrentUrl();

const pendenciasDoMais = computed(
    () =>
        page.props.contadores.recebidas +
        (modoSimples.value ? 0 : page.props.contadores.convites),
);

/**
 * The item of the current page, by URL path (wallet and month parameters do not count); any page
 * outside the three main ones marks "Mais" (DEC-096).
 */
const atual = computed<'inicio' | 'carteiras' | 'relatorio' | 'mais'>(() => {
    if (isCurrentUrl(home())) {
        return 'inicio';
    }

    if (isCurrentUrl(carteirasIndex())) {
        return 'carteiras';
    }

    if (isCurrentUrl(relatorio())) {
        return 'relatorio';
    }

    return 'mais';
});

/** Current item: primary color and bold label; the others stay muted (DEC-096). */
function classeDoItem(item: string): string {
    return item === atual.value
        ? 'font-semibold text-primary'
        : 'text-muted-foreground';
}

/** Current item: icon inside a soft primary pill (DEC-096). */
function classeDoIcone(item: string): string {
    return item === atual.value ? 'bg-primary/15' : '';
}
</script>

<template>
    <!-- Same bar in both modes (RN-336). Recebidas stays in the menu opened by "Mais". -->
    <nav
        class="fixed inset-x-3 bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-20 grid grid-cols-5 rounded-2xl border border-white/40 bg-card/60 shadow-lg ring-1 shadow-black/10 ring-black/5 backdrop-blur-xl backdrop-saturate-150 md:hidden dark:border-white/10 dark:bg-card/55 dark:ring-white/5"
        aria-label="Navegação principal"
        data-tutorial="menu"
        data-tutorial-fixo
    >
        <Link
            :href="home()"
            class="flex flex-col items-center gap-0.5 py-2 text-xs"
            :class="classeDoItem('inicio')"
            :aria-current="atual === 'inicio' ? 'page' : undefined"
        >
            <span
                class="flex h-7 w-12 items-center justify-center rounded-full transition-colors"
                :class="classeDoIcone('inicio')"
                ><House class="size-5"
            /></span>
            Início
        </Link>
        <Link
            :href="carteirasIndex()"
            class="relative flex flex-col items-center gap-0.5 py-2 text-xs"
            :class="classeDoItem('carteiras')"
            :aria-current="atual === 'carteiras' ? 'page' : undefined"
        >
            <span
                class="flex h-7 w-12 items-center justify-center rounded-full transition-colors"
                :class="classeDoIcone('carteiras')"
                ><Wallet class="size-5"
            /></span>
            Carteiras
            <span
                v-if="page.props.contadores.membros > 0"
                class="absolute top-1 right-1/4 rounded-full bg-destaque px-1.5 text-[10px] text-destaque-foreground simples:text-xs"
                :aria-label="`${page.props.contadores.membros} convites de membro pendentes`"
                >{{ page.props.contadores.membros }}</span
            >
        </Link>
        <button
            type="button"
            class="flex items-center justify-center"
            aria-label="Adicionar lançamento"
            data-test="adicionar-lancamento-mobile"
            data-tutorial="adicionar"
            @click="adicionar"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-primary text-primary-foreground shadow simples:size-12"
            >
                <Plus class="size-6" />
            </span>
        </button>
        <Link
            :href="relatorio()"
            class="flex flex-col items-center gap-0.5 py-2 text-xs"
            :class="classeDoItem('relatorio')"
            :aria-current="atual === 'relatorio' ? 'page' : undefined"
        >
            <span
                class="flex h-7 w-12 items-center justify-center rounded-full transition-colors"
                :class="classeDoIcone('relatorio')"
                ><ChartPie class="size-5"
            /></span>
            Relatório
        </Link>
        <button
            type="button"
            class="relative flex flex-col items-center gap-0.5 py-2 text-xs"
            :class="classeDoItem('mais')"
            :aria-current="atual === 'mais' ? 'page' : undefined"
            data-tutorial="mais"
            @click="setOpenMobile(true)"
        >
            <span
                class="flex h-7 w-12 items-center justify-center rounded-full transition-colors"
                :class="classeDoIcone('mais')"
                ><Menu class="size-5"
            /></span>
            Mais
            <span
                v-if="pendenciasDoMais > 0"
                class="absolute top-1 right-1/4 rounded-full bg-destaque px-1.5 text-[10px] text-destaque-foreground"
                :aria-label="`${pendenciasDoMais} notificações pendentes`"
                >{{ pendenciasDoMais }}</span
            >
        </button>
    </nav>
</template>
