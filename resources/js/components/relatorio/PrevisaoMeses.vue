<script setup lang="ts">
import { computed } from 'vue';
import { mesPorExtenso, nomeDoMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import type { PrevisaoRelatorio } from '@/types';

const props = defineProps<{
    previsao: PrevisaoRelatorio;
    /** The simple mode lists only the month and the balance (note 07). */
    simples: boolean;
}>();

const maior = computed(() =>
    Math.max(1, ...props.previsao.meses.map((mes) => Math.abs(mes.acumulado))),
);

function largura(acumulado: number): string {
    return `${(Math.abs(acumulado) / maior.value) * 50}%`;
}

function comSinal(valor: number): string {
    return `${valor > 0 ? '+' : valor < 0 ? '−' : ''}${formatarMoeda(Math.abs(valor))}`;
}
</script>

<template>
    <!-- Forecast of the next six months from the existing entries; the first month with a negative
         balance is marked (RN-184). The bar grows from the middle: right positive, left negative. -->
    <div class="grid gap-3">
        <p
            v-if="previsao.primeiroNegativo"
            class="rounded-xl bg-destructive/10 px-3 py-2 text-sm text-red-700 dark:text-red-300"
        >
            O saldo fica negativo em
            {{ mesPorExtenso(previsao.primeiroNegativo) }}.
        </p>
        <ol class="grid gap-2.5">
            <li
                v-for="mes in previsao.meses"
                :key="mes.mes"
                class="grid gap-1.5 rounded-xl px-2 py-1.5"
                :class="{
                    'bg-destructive/5 ring-1 ring-red-600/30 dark:ring-red-400/30':
                        mes.mes === previsao.primeiroNegativo,
                }"
            >
                <span class="flex items-baseline justify-between gap-3">
                    <span
                        :class="{
                            'font-medium':
                                mes.mes === previsao.primeiroNegativo,
                        }"
                        >{{ nomeDoMes(mes.mes) }}</span
                    >
                    <span
                        class="font-medium tabular-nums"
                        :class="{
                            'text-red-700 dark:text-red-400': mes.acumulado < 0,
                        }"
                        >{{ formatarMoeda(mes.acumulado) }}</span
                    >
                </span>
                <span
                    v-if="!simples"
                    class="flex items-center justify-between gap-3 text-xs text-muted-foreground"
                >
                    <span
                        class="relative h-1.5 flex-1 rounded-full bg-muted"
                        aria-hidden="true"
                    >
                        <span
                            class="absolute inset-y-0 rounded-full"
                            :class="
                                mes.acumulado < 0
                                    ? 'right-1/2 bg-(--grafico-despesa)'
                                    : 'left-1/2 bg-primary'
                            "
                            :style="{ width: largura(mes.acumulado) }"
                        />
                        <span
                            class="absolute inset-y-[-2px] left-1/2 w-px bg-foreground/30"
                        />
                    </span>
                    <span class="w-28 text-right tabular-nums"
                        >{{ comSinal(mes.resultado) }} no mês</span
                    >
                </span>
            </li>
        </ol>
    </div>
</template>
