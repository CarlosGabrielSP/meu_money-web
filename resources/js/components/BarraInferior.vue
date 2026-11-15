<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { House, Inbox, Menu, Plus, Users } from '@lucide/vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useNovoLancamento } from '@/composables/useNovoLancamento';
import { home } from '@/routes';
import { index as contatosIndex } from '@/routes/contatos';

const page = usePage();
const { setOpenMobile } = useSidebar();
const { solicitar } = useNovoLancamento();

/**
 * The add button is always available (RN-096): on the main screen it opens the form, elsewhere it goes there first.
 */
function adicionar(): void {
    if (page.component === 'Inicio') {
        solicitar();

        return;
    }

    router.visit(home.url({ query: { novo: '1' } }));
}
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-5 border-t bg-background pb-[env(safe-area-inset-bottom)] md:hidden"
        aria-label="Navegação principal"
    >
        <Link
            :href="home()"
            class="flex flex-col items-center gap-0.5 py-2 text-xs"
        >
            <House class="size-5" /> Início
        </Link>
        <span
            class="flex flex-col items-center gap-0.5 py-2 text-xs opacity-50"
            aria-disabled="true"
        >
            <Inbox class="size-5" /> Recebidas
        </span>
        <button
            type="button"
            class="flex items-center justify-center"
            aria-label="Adicionar lançamento"
            data-test="adicionar-lancamento-mobile"
            @click="adicionar"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-primary text-primary-foreground shadow"
            >
                <Plus class="size-6" />
            </span>
        </button>
        <Link
            :href="contatosIndex()"
            class="relative flex flex-col items-center gap-0.5 py-2 text-xs"
        >
            <Users class="size-5" /> Contatos
            <span
                v-if="page.props.contadores.convites > 0"
                class="absolute top-1 right-1/4 rounded-full bg-primary px-1.5 text-[10px] text-primary-foreground"
                :aria-label="`${page.props.contadores.convites} convites pendentes`"
                >{{ page.props.contadores.convites }}</span
            >
        </Link>
        <button
            type="button"
            class="flex flex-col items-center gap-0.5 py-2 text-xs"
            @click="setOpenMobile(true)"
        >
            <Menu class="size-5" /> Mais
        </button>
    </nav>
</template>
