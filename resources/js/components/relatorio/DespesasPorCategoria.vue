<script setup lang="ts">
import VisDonut from '@unovis/vue/components/donut';
import VisSingleContainer from '@unovis/vue/containers/single-container';
import { computed } from 'vue';
import CategoriaIcone from '@/components/categorias/CategoriaIcone.vue';
import { formatarMoeda } from '@/lib/dinheiro';
import {
    coresCategorias,
    corOutras,
    formatarPercentual,
    formatarVariacao,
} from '@/lib/relatorio';
import type { DespesasCategoria } from '@/types';

type Fatia = DespesasCategoria['rosca'][number];

const props = defineProps<{
    despesas: DespesasCategoria;
    /** The simple mode shows only the list, without the donut and the change (RN-188). */
    simples: boolean;
}>();

const total = computed(() =>
    props.despesas.ranking.reduce(
        (soma, categoria) => soma + categoria.valor,
        0,
    ),
);

function corDaFatia(fatia: Fatia, indice: number): string {
    return fatia.outras ? corOutras : coresCategorias[indice];
}

/**
 * Color of a ranking row: the same as its slice; the ones after the sixth are inside "Outras".
 */
function corDaPosicao(indice: number): string {
    return coresCategorias[indice] ?? corOutras;
}
</script>

<template>
    <!-- Expenses by category in the period: donut with the six largest and "Outras", and the
         ranking of every category (RN-182). The ranking is the accessible version of the donut
         (RNF-008). -->
    <div class="grid gap-5 md:grid-cols-[minmax(0,11rem)_1fr] md:items-start">
        <div
            v-if="!simples"
            aria-hidden="true"
            class="grafico relative mx-auto size-44"
        >
            <VisSingleContainer :data="despesas.rosca" :height="176">
                <VisDonut
                    :value="(fatia: Fatia) => fatia.valor"
                    :color="corDaFatia"
                    :arc-width="22"
                    :pad-angle="0.02"
                    :corner-radius="3"
                    :sort-function="() => 0"
                />
            </VisSingleContainer>
            <span
                class="pointer-events-none absolute inset-0 grid place-content-center text-center"
            >
                <span class="text-xs text-muted-foreground">Total</span>
                <span class="font-display text-base font-semibold tabular-nums">
                    {{ formatarMoeda(total) }}
                </span>
            </span>
        </div>

        <ol class="grid gap-3" :class="{ 'md:col-span-2': simples }">
            <li
                v-for="(categoria, indice) in despesas.ranking"
                :key="categoria.id"
                class="flex items-center gap-3"
            >
                <span class="relative">
                    <CategoriaIcone
                        :icone="categoria.icone"
                        class="size-9 simples:size-11"
                    />
                    <span
                        v-if="!simples"
                        aria-hidden="true"
                        class="absolute -right-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-card"
                        :style="{ backgroundColor: corDaPosicao(indice) }"
                    />
                </span>
                <span class="grid min-w-0 flex-1">
                    <span class="flex items-baseline justify-between gap-3">
                        <span class="truncate">{{ categoria.nome }}</span>
                        <span class="shrink-0 font-medium tabular-nums">{{
                            formatarMoeda(categoria.valor)
                        }}</span>
                    </span>
                    <span
                        class="flex justify-between gap-3 text-sm text-muted-foreground tabular-nums"
                    >
                        <span
                            >{{ formatarPercentual(categoria.percentual) }} do
                            total</span
                        >
                        <span v-if="!simples">
                            <span class="sr-only">Variação:</span>
                            {{ formatarVariacao(categoria.variacao) }}
                        </span>
                    </span>
                </span>
            </li>
        </ol>
    </div>
</template>
