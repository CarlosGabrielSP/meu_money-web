<script setup lang="ts">
import { computed } from 'vue';
import { mesPorExtenso, nomeDoMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import type { PrevisaoRelatorio, VisaoRelatorio } from '@/types';

const props = defineProps<{
    previsao: PrevisaoRelatorio;
    /** The simple mode lists only the month and the balance (note 07). */
    simples: boolean;
    /** A card shows its debt in neutral color, with no negative highlight (RN-227, R7). */
    neutro?: boolean;
    /** Month result or accumulated balance (RN-252). */
    visao: VisaoRelatorio;
}>();

type MesPrevisto = PrevisaoRelatorio['meses'][number];

/** The value shown for the month in the chosen view (RN-252). */
function valor(mes: MesPrevisto): number {
    return props.visao === 'mes' ? mes.resultado : mes.acumulado;
}

/** The first negative month is highlighted only in the accumulated view (RN-252). */
const destaque = computed(() =>
    props.visao === 'acumulado' ? props.previsao.primeiroNegativo : null,
);

const maior = computed(() =>
    Math.max(1, ...props.previsao.meses.map((mes) => Math.abs(valor(mes)))),
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
            v-if="destaque"
            class="rounded-xl bg-destructive/10 px-3 py-2 text-sm text-red-700 dark:text-red-300"
        >
            O saldo fica negativo em
            {{ mesPorExtenso(destaque) }}.
        </p>
        <ol class="grid gap-2.5">
            <li
                v-for="mes in previsao.meses"
                :key="mes.mes"
                class="grid gap-1.5 rounded-xl px-2 py-1.5"
                :class="{
                    'bg-destructive/5 ring-1 ring-red-600/30 dark:ring-red-400/30':
                        mes.mes === destaque,
                }"
            >
                <span class="flex items-baseline justify-between gap-3">
                    <span
                        :class="{
                            'font-medium': mes.mes === destaque,
                        }"
                        >{{ nomeDoMes(mes.mes) }}</span
                    >
                    <span
                        class="font-medium tabular-nums"
                        :class="{
                            'text-red-700 dark:text-red-400':
                                valor(mes) < 0 && !neutro,
                        }"
                        >{{ formatarMoeda(valor(mes)) }}</span
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
                                valor(mes) < 0
                                    ? neutro
                                        ? 'right-1/2 bg-muted-foreground'
                                        : 'right-1/2 bg-(--grafico-despesa)'
                                    : 'left-1/2 bg-primary'
                            "
                            :style="{ width: largura(valor(mes)) }"
                        />
                        <span
                            class="absolute inset-y-[-2px] left-1/2 w-px bg-foreground/30"
                        />
                    </span>
                    <span
                        v-if="visao === 'acumulado'"
                        class="w-28 text-right tabular-nums"
                        >{{ comSinal(mes.resultado) }} no mês</span
                    >
                </span>
            </li>
        </ol>
    </div>
</template>
