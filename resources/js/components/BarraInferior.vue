<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { House, Inbox, Menu, Plus, Settings, Users } from '@lucide/vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useModoSimples } from '@/composables/useModoSimples';
import { useNovoLancamento } from '@/composables/useNovoLancamento';
import { home } from '@/routes';
import { index as contatosIndex } from '@/routes/contatos';
import { edit as perfilEdit } from '@/routes/profile';
import { index as recebidasIndex } from '@/routes/recebidas';

const page = usePage();
const { setOpenMobile } = useSidebar();
const { adicionar } = useNovoLancamento();
const modoSimples = useModoSimples();
</script>

<template>
    <nav
        class="fixed inset-x-3 bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-20 grid rounded-2xl border bg-card/95 shadow-lg backdrop-blur md:hidden"
        :class="modoSimples ? 'grid-cols-4' : 'grid-cols-5'"
        aria-label="Navegação principal"
        data-tutorial="menu"
        data-tutorial-fixo
    >
        <Link
            :href="home()"
            class="flex flex-col items-center gap-0.5 py-2 text-xs"
        >
            <House class="size-5" /> Início
        </Link>
        <Link
            :href="recebidasIndex()"
            class="relative flex flex-col items-center gap-0.5 py-2 text-xs"
            data-tutorial="recebidas"
        >
            <Inbox class="size-5" /> Recebidas
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
        <!-- The simple mode bar is Início, Recebidas, (+) and Configurações (RN-163). -->
        <Link
            v-if="modoSimples"
            :href="perfilEdit()"
            class="flex flex-col items-center gap-0.5 py-2 text-xs"
            data-tutorial="configuracoes"
        >
            <Settings class="size-5" /> Configurações
        </Link>
        <template v-else>
            <Link
                :href="contatosIndex()"
                class="relative flex flex-col items-center gap-0.5 py-2 text-xs"
            >
                <Users class="size-5" /> Contatos
                <span
                    v-if="page.props.contadores.convites > 0"
                    class="absolute top-1 right-1/4 rounded-full bg-destaque px-1.5 text-[10px] text-destaque-foreground"
                    :aria-label="`${page.props.contadores.convites} convites pendentes`"
                    >{{ page.props.contadores.convites }}</span
                >
            </Link>
            <button
                type="button"
                class="flex flex-col items-center gap-0.5 py-2 text-xs"
                data-tutorial="mais"
                @click="setOpenMobile(true)"
            >
                <Menu class="size-5" /> Mais
            </button>
        </template>
    </nav>
</template>
