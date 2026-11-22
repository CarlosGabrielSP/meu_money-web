<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import BotaoPrivacidade from '@/components/BotaoPrivacidade.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dataCompleta } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { index } from '@/routes/recebidas';

type Recebida = {
    id: number;
    nova: boolean;
    descricao: string;
    tipo: 'receita' | 'despesa';
    data: string;
    autor: string;
    valor: number;
    total: number;
    carteira: string;
    categoria: string;
};

defineProps<{
    recebidas: {
        data: Recebida[];
        prev_page_url: string | null;
        next_page_url: string | null;
        current_page: number;
        last_page: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Recebidas', href: index() }],
    },
});
</script>

<template>
    <Head title="Recebidas" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-3">
            <Heading
                title="Recebidas"
                description="Lançamentos que seus contatos dividiram com você"
            />
            <BotaoPrivacidade />
        </div>

        <p
            v-if="recebidas.data.length === 0"
            class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            Nenhum lançamento foi dividido com você ainda.
        </p>

        <ul v-else class="divide-y overflow-hidden rounded-2xl border bg-card">
            <li
                v-for="recebida in recebidas.data"
                :key="recebida.id"
                class="flex items-center justify-between gap-3 p-3"
                :class="{ 'bg-primary/5': recebida.nova }"
            >
                <span class="min-w-0">
                    <span class="flex items-center gap-2">
                        <span class="truncate font-medium">{{
                            recebida.descricao
                        }}</span>
                        <Badge
                            v-if="recebida.nova"
                            class="bg-destaque text-destaque-foreground"
                            >Nova</Badge
                        >
                    </span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{ dataCompleta(recebida.data) }} · de
                        {{ recebida.autor }} · {{ recebida.carteira }} ·
                        {{ recebida.categoria }}
                    </span>
                </span>
                <span class="shrink-0 text-right tabular-nums">
                    <span
                        class="block font-medium"
                        :class="
                            recebida.tipo === 'receita'
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-red-600 dark:text-red-400'
                        "
                        >{{ formatarMoeda(recebida.valor) }}</span
                    >
                    <span class="block text-xs text-muted-foreground"
                        >de {{ formatarMoeda(recebida.total) }}</span
                    >
                </span>
            </li>
        </ul>

        <nav
            v-if="recebidas.last_page > 1"
            class="flex items-center justify-between"
            aria-label="Páginas"
        >
            <Button
                v-if="recebidas.prev_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="recebidas.prev_page_url">Anteriores</Link>
            </Button>
            <span class="text-sm text-muted-foreground">
                Página {{ recebidas.current_page }} de
                {{ recebidas.last_page }}
            </span>
            <Button
                v-if="recebidas.next_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="recebidas.next_page_url">Próximas</Link>
            </Button>
        </nav>
    </div>
</template>
