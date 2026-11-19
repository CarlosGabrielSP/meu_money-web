<script setup lang="ts">
import { formatarMoeda } from '@/lib/dinheiro';
import { formatarPercentual, formatarVariacao } from '@/lib/relatorio';
import type { ResumoRelatorio } from '@/types';

defineProps<{
    resumo: ResumoRelatorio;
    /** "Junho a novembro de 2026", the months of the period. */
    rotuloPeriodo: string;
    simples: boolean;
    /** A card has no savings rate (RN-227, R7). */
    semTaxa?: boolean;
}>();
</script>

<template>
    <!-- Summary of the period (RN-180), in the main card style of the home screen (DEC-070): the
         result is the large number; the change compares with the previous period. -->
    <section
        class="rounded-3xl bg-saldo p-5 text-saldo-foreground shadow-sm md:p-6 dark:border dark:border-saldo-borda"
        aria-labelledby="resumo-titulo"
    >
        <h2 id="resumo-titulo" class="text-sm text-saldo-foreground/75">
            Resultado de {{ rotuloPeriodo }}
        </h2>
        <p class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <span
                class="font-display text-[2.5rem] leading-none font-semibold tracking-tight tabular-nums md:text-5xl"
                :class="{ 'text-red-300': resumo.resultado < 0 }"
                >{{ formatarMoeda(resumo.resultado) }}</span
            >
            <span class="text-sm text-saldo-foreground/75 tabular-nums">
                {{ formatarVariacao(resumo.variacoes.resultado) }}
                <span class="sr-only">em relação ao período anterior</span>
                <span aria-hidden="true">vs. anterior</span>
            </span>
        </p>

        <dl
            class="mt-5 grid gap-3 border-t border-saldo-foreground/15 pt-4"
            :class="simples ? 'text-lg' : 'grid-cols-3 text-sm'"
        >
            <div
                :class="
                    simples
                        ? 'flex items-baseline justify-between gap-4'
                        : 'grid gap-0.5'
                "
            >
                <dt class="text-saldo-foreground/75">Receitas</dt>
                <dd class="font-medium text-emerald-300 tabular-nums">
                    {{ formatarMoeda(resumo.receitas) }}
                    <span
                        class="block text-xs font-normal text-saldo-foreground/75 simples:inline simples:text-base"
                        >{{ formatarVariacao(resumo.variacoes.receitas) }}</span
                    >
                </dd>
            </div>
            <div
                :class="
                    simples
                        ? 'flex items-baseline justify-between gap-4'
                        : 'grid gap-0.5'
                "
            >
                <dt class="text-saldo-foreground/75">Despesas</dt>
                <dd class="font-medium text-red-300 tabular-nums">
                    {{ formatarMoeda(resumo.despesas) }}
                    <span
                        class="block text-xs font-normal text-saldo-foreground/75 simples:inline simples:text-base"
                        >{{ formatarVariacao(resumo.variacoes.despesas) }}</span
                    >
                </dd>
            </div>
            <div
                v-if="!semTaxa"
                :class="
                    simples
                        ? 'flex items-baseline justify-between gap-4'
                        : 'grid gap-0.5'
                "
            >
                <dt class="text-saldo-foreground/75">Taxa de poupança</dt>
                <dd class="font-medium tabular-nums">
                    {{ formatarPercentual(resumo.taxaPoupanca) }}
                </dd>
            </div>
        </dl>
    </section>
</template>
