<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartPie, House, Inbox, Menu, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useModoSimples } from '@/composables/useModoSimples';
import { useNovoLancamento } from '@/composables/useNovoLancamento';
import { home, relatorio } from '@/routes';
import { index as recebidasIndex } from '@/routes/recebidas';

const page = usePage();
const { setOpenMobile } = useSidebar();
const { adicionar } = useNovoLancamento();
const modoSimples = useModoSimples();
const { isCurrentUrl } = useCurrentUrl();

/**
 * "Mais" counts contact invitations (full mode only, RN-163) and member invitations (both modes,
 * nota 07, RN-271).
 */
const convitesDoMais = computed(
    () =>
        (modoSimples.value ? 0 : page.props.contadores.convites) +
        page.props.contadores.membros,
);

/**
 * The item of the current page, by URL path (wallet and month parameters do not count); any page
 * outside the three main ones marks "Mais" (DEC-096).
 */
const atual = computed<'inicio' | 'recebidas' | 'relatorio' | 'mais'>(() => {
    if (isCurrentUrl(home())) {
        return 'inicio';
    }

    if (isCurrentUrl(recebidasIndex())) {
        return 'recebidas';
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
    <!-- Same bar in both modes (RN-163, RN-189, DEC-075): Início, Recebidas, (+), Relatório and
         Mais; Contatos is in "Mais", whose badge counts the pending invitations. The simple menu
         has no Contatos, so it shows no badge there. Frosted glass over the page (DEC-076). -->
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
            :href="recebidasIndex()"
            class="relative flex flex-col items-center gap-0.5 py-2 text-xs"
            :class="classeDoItem('recebidas')"
            :aria-current="atual === 'recebidas' ? 'page' : undefined"
            data-tutorial="recebidas"
        >
            <span
                class="flex h-7 w-12 items-center justify-center rounded-full transition-colors"
                :class="classeDoIcone('recebidas')"
                ><Inbox class="size-5"
            /></span>
            Recebidas
            <span
                v-if="page.props.contadores.recebidas > 0"
                class="absolute top-1 right-1/4 rounded-full bg-destaque px-1.5 text-[10px] text-destaque-foreground simples:text-xs"
                :aria-label="`${page.props.contadores.recebidas} recebidas novas`"
                >{{ page.props.contadores.recebidas }}</span
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
                v-if="convitesDoMais > 0"
                class="absolute top-1 right-1/4 rounded-full bg-destaque px-1.5 text-[10px] text-destaque-foreground"
                :aria-label="`${convitesDoMais} convites pendentes`"
                >{{ convitesDoMais }}</span
            >
        </button>
    </nav>
</template>
