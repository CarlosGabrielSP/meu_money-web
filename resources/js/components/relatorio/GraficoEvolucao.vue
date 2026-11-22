<script setup lang="ts">
import VisAxis from '@unovis/vue/components/axis';
import VisGroupedBar from '@unovis/vue/components/grouped-bar';
import VisLine from '@unovis/vue/components/line';
import VisXYContainer from '@unovis/vue/containers/xy-container';
import { computed } from 'vue';
import { mesCurto, nomeDoMes } from '@/lib/datas';
import {
    formatarMoeda,
    usePrivacidadeDosValores,
    VALOR_OCULTO,
} from '@/lib/dinheiro';
import type { MesEvolucao, VisaoRelatorio } from '@/types';

const props = withDefaults(
    defineProps<{
        meses: MesEvolucao[];
        /** A card shows its debt in place of the accumulated balance (RN-227, R7). */
        rotuloLinha?: string;
        /** "Compras" on a card (nota 07). */
        rotuloDespesas?: string;
        /** The line shows the month result or the accumulated balance (RN-252). */
        visao?: VisaoRelatorio;
    }>(),
    {
        rotuloLinha: 'Saldo acumulado no fim do mês',
        rotuloDespesas: 'Despesas',
        visao: 'acumulado',
    },
);

const x = (_: MesEvolucao, indice: number): number => indice;
const barras = [
    (mes: MesEvolucao): number => mes.receitas / 100,
    (mes: MesEvolucao): number => mes.despesas / 100,
];
const coresBarras = ['var(--grafico-receita)', 'var(--grafico-despesa)'];
function valorDaLinha(mes: MesEvolucao): number {
    return props.visao === 'mes' ? mes.receitas - mes.despesas : mes.acumulado;
}

const acumulado = (mes: MesEvolucao): number => valorDaLinha(mes) / 100;

function rotuloDoMes(indice: number | Date): string {
    const mes = props.meses[Number(indice)];

    return mes === undefined ? '' : mesCurto(mes.mes);
}

const formatoCompacto = new Intl.NumberFormat('pt-BR', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

const valoresOcultos = usePrivacidadeDosValores();
const rotuloDoValor = computed(() =>
    valoresOcultos.value
        ? () => VALOR_OCULTO
        : (valor: number | Date) => formatoCompacto.format(Number(valor)),
);
</script>

<template>
    <!-- Bars of the month's income and expenses and the line of the accumulated balance (RN-181).
         The table carries the same values for screen readers (RNF-008). -->
    <div class="grid gap-3">
        <div aria-hidden="true" class="grafico">
            <VisXYContainer :data="meses" :height="220" :padding="{ top: 8 }">
                <VisGroupedBar
                    :x="x"
                    :y="barras"
                    :color="
                        (_: MesEvolucao, indice: number) => coresBarras[indice]
                    "
                    :rounded-corners="3"
                    :group-max-width="40"
                    :bar-padding="0.15"
                />
                <VisLine
                    :x="x"
                    :y="acumulado"
                    color="var(--primary)"
                    :line-width="2.5"
                />
                <VisAxis
                    type="x"
                    :tick-format="rotuloDoMes"
                    :num-ticks="meses.length"
                    :tick-values="meses.map((_, indice) => indice)"
                    :grid-line="false"
                    :domain-line="false"
                    :tick-line="false"
                />
                <VisAxis
                    type="y"
                    :tick-format="rotuloDoValor"
                    :num-ticks="4"
                    :domain-line="false"
                    :tick-line="false"
                />
            </VisXYContainer>
        </div>
        <ul
            aria-hidden="true"
            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <li class="flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm bg-(--grafico-receita)" />
                Receitas
            </li>
            <li class="flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm bg-(--grafico-despesa)" />
                {{ rotuloDespesas }}
            </li>
            <li class="flex items-center gap-1.5">
                <span class="h-0.5 w-3 rounded-full bg-primary" />
                {{ rotuloLinha }}
            </li>
        </ul>
        <table class="sr-only">
            <caption>
                Evolução mensal
            </caption>
            <thead>
                <tr>
                    <th scope="col">Mês</th>
                    <th scope="col">Receitas</th>
                    <th scope="col">{{ rotuloDespesas }}</th>
                    <th scope="col">{{ rotuloLinha }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="mes in meses" :key="mes.mes">
                    <th scope="row">{{ nomeDoMes(mes.mes) }}</th>
                    <td>{{ formatarMoeda(mes.receitas) }}</td>
                    <td>{{ formatarMoeda(mes.despesas) }}</td>
                    <td>{{ formatarMoeda(valorDaLinha(mes)) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
