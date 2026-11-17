<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { mesPorExtenso } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { tituloDaPeriodicidade } from '@/lib/periodicidades';
import { show } from '@/routes/parcelamentos';
import type { CompromissosRelatorio } from '@/types';

defineProps<{
    compromissos: CompromissosRelatorio;
}>();
</script>

<template>
    <!-- Expense series that still bind the wallet (RN-185): installments with what is left and
         active recurring series with their monthly equivalent. -->
    <div class="grid gap-5">
        <div class="grid gap-2">
            <h3 class="flex items-baseline justify-between gap-3 font-medium">
                Parceladas
                <span
                    class="text-sm font-normal text-muted-foreground tabular-nums"
                    >Restante
                    {{ formatarMoeda(compromissos.parceladas.total) }}</span
                >
            </h3>
            <p
                v-if="compromissos.parceladas.series.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nenhuma parcela pendente.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="serie in compromissos.parceladas.series"
                    :key="serie.id"
                    class="flex items-baseline justify-between gap-3 py-2"
                >
                    <span class="grid min-w-0">
                        <Link
                            :href="show(serie.id)"
                            class="truncate underline-offset-4 hover:underline"
                            >{{ serie.descricao }}</Link
                        >
                        <span class="text-sm text-muted-foreground">
                            {{ serie.parcelasPendentes }}
                            {{
                                serie.parcelasPendentes === 1
                                    ? 'parcela pendente'
                                    : 'parcelas pendentes'
                            }}, termina em
                            {{ mesPorExtenso(serie.ultimaParcela) }}
                        </span>
                    </span>
                    <span class="shrink-0 font-medium tabular-nums">{{
                        formatarMoeda(serie.restante)
                    }}</span>
                </li>
            </ul>
        </div>

        <div class="grid gap-2">
            <h3 class="flex items-baseline justify-between gap-3 font-medium">
                Recorrentes
                <span
                    class="text-sm font-normal text-muted-foreground tabular-nums"
                    >{{ formatarMoeda(compromissos.recorrentes.total) }} por
                    mês</span
                >
            </h3>
            <p
                v-if="compromissos.recorrentes.series.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nenhuma despesa recorrente ativa.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="serie in compromissos.recorrentes.series"
                    :key="serie.id"
                    class="flex items-baseline justify-between gap-3 py-2"
                >
                    <span class="grid min-w-0">
                        <Link
                            :href="show(serie.id)"
                            class="truncate underline-offset-4 hover:underline"
                            >{{ serie.descricao }}</Link
                        >
                        <span
                            class="text-sm text-muted-foreground tabular-nums"
                        >
                            {{ formatarMoeda(serie.proximoValor) }}
                            {{ tituloDaPeriodicidade(serie.periodicidade) }}
                        </span>
                    </span>
                    <span class="shrink-0 text-right tabular-nums">
                        <span class="font-medium">{{
                            formatarMoeda(serie.mensal)
                        }}</span>
                        <span class="block text-xs text-muted-foreground"
                            >por mês</span
                        >
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
