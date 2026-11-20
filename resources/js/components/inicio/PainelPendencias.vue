<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { dataCompleta } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { executado } from '@/routes/cotas';
import type { CotaPendente } from '@/types';

export type TipoPainel = 'vencidas' | 'proximas';

const props = defineProps<{
    painel: TipoPainel | null;
    quantidade: number;
}>();

const emit = defineEmits<{
    fechar: [];
}>();

const desktop = useMediaQuery('(min-width: 768px)');

/**
 * The panel list, loaded on request when the panel opens (RN-172, RN-173); null while loading.
 * Rows stay in the panel after being marked, so a mark can be undone; the page reload after each
 * mark drops the list prop, hence this local copy (RF-042).
 */
const linhas = ref<(CotaPendente & { executado: boolean })[] | null>(null);

/** The count when the panel opened, which matches the frozen rows while marks reload the page. */
const total = ref(0);

watch(
    () => props.painel,
    (painel) => {
        linhas.value = null;

        if (painel === null) {
            return;
        }

        total.value = props.quantidade;
        const chave = painel === 'vencidas' ? 'cotasVencidas' : 'cotasProximas';

        router.reload({
            only: [chave],
            onSuccess: (pagina) => {
                if (props.painel !== painel) {
                    return;
                }

                const cotas = (pagina.props[chave] ?? []) as CotaPendente[];
                linhas.value = cotas.map((cota) => ({
                    ...cota,
                    executado: false,
                }));
            },
        });
    },
);

const titulo = computed(
    () =>
        `${props.painel === 'vencidas' ? 'Contas vencidas' : 'Vencem em 7 dias'} (${total.value})`,
);

const descricao = computed(() =>
    props.painel === 'vencidas'
        ? 'Lançamentos pendentes com data anterior a hoje. Marque os que já foram pagos ou recebidos.'
        : 'Despesas pendentes de hoje até os próximos 6 dias.',
);

function alternar(
    linha: CotaPendente & { executado: boolean },
    marcado: boolean,
): void {
    linha.executado = marcado;
    let salvo = false;

    // Async, so marking several rows in a row does not cancel the earlier requests. Any failure
    // (validation, HTTP error, network, cancel) undoes the mark.
    router.patch(
        executado.url(linha.cotaId),
        { executado: marcado },
        {
            async: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                salvo = true;
            },
            onFinish: () => {
                if (!salvo) {
                    linha.executado = !marcado;
                }
            },
        },
    );
}
</script>

<template>
    <Sheet
        :open="painel !== null"
        @update:open="(aberto) => !aberto && emit('fechar')"
    >
        <SheetContent
            :side="desktop ? 'right' : 'bottom'"
            class="max-h-[85vh] gap-0 rounded-t-3xl md:max-h-none md:rounded-none"
        >
            <SheetHeader>
                <SheetTitle class="font-display text-lg">{{
                    titulo
                }}</SheetTitle>
                <SheetDescription>{{ descricao }}</SheetDescription>
            </SheetHeader>
            <div class="overflow-y-auto px-4 pb-6">
                <div v-if="linhas === null" class="grid gap-3" aria-busy="true">
                    <Skeleton v-for="indice in 3" :key="indice" class="h-12" />
                </div>
                <ul v-else class="divide-y">
                    <li
                        v-for="linha in linhas"
                        :key="linha.cotaId"
                        class="flex items-center gap-3 py-3"
                    >
                        <Checkbox
                            :model-value="linha.executado"
                            :aria-label="`${linha.tipo === 'receita' ? 'Recebido' : 'Pago'}: ${linha.descricao}`"
                            @update:model-value="
                                alternar(linha, $event === true)
                            "
                        />
                        <span class="grid min-w-0 flex-1">
                            <span
                                class="truncate font-medium"
                                :class="{
                                    'text-muted-foreground line-through':
                                        linha.executado,
                                }"
                                >{{ linha.descricao }}</span
                            >
                            <span
                                class="text-sm text-muted-foreground tabular-nums"
                            >
                                {{ dataCompleta(linha.data) }}
                                <template v-if="linha.tipo === 'receita'"
                                    >, receita</template
                                >
                            </span>
                        </span>
                        <span class="font-medium tabular-nums">{{
                            formatarMoeda(linha.valor)
                        }}</span>
                    </li>
                </ul>
                <p
                    v-if="linhas !== null && total > linhas.length"
                    class="pt-3 text-sm text-muted-foreground"
                >
                    Mostrando as {{ linhas.length }} mais recentes de
                    {{ total }}.
                </p>
            </div>
        </SheetContent>
    </Sheet>
</template>
