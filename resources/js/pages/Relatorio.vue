<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import CampoSelecao from '@/components/CampoSelecao.vue';
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import BlocoRelatorio from '@/components/relatorio/BlocoRelatorio.vue';
import CompromissosSeries from '@/components/relatorio/CompromissosSeries.vue';
import DespesasPorCategoria from '@/components/relatorio/DespesasPorCategoria.vue';
import DespesasPorTipo from '@/components/relatorio/DespesasPorTipo.vue';
import GraficoEvolucao from '@/components/relatorio/GraficoEvolucao.vue';
import PrevisaoMeses from '@/components/relatorio/PrevisaoMeses.vue';
import RateioPeriodo from '@/components/relatorio/RateioPeriodo.vue';
import ResumoRelatorio from '@/components/relatorio/ResumoRelatorio.vue';
import SaldosCarteiras from '@/components/relatorio/SaldosCarteiras.vue';
import { useModoSimples } from '@/composables/useModoSimples';
import { mesPorExtenso } from '@/lib/datas';
import { relatorio } from '@/routes';
import type {
    CarteiraResumo,
    CompromissosRelatorio,
    DespesaTipo,
    DespesasCategoria,
    MesEvolucao,
    PeriodoRelatorio,
    PrevisaoRelatorio,
    RateioRelatorio,
    ResumoRelatorio as Resumo,
    SaldoCarteira,
} from '@/types';

const props = defineProps<{
    carteira: CarteiraResumo;
    periodo: PeriodoRelatorio;
    intervalo: { inicio: string; fim: string };
    hoje: string;
    resumo: Resumo;
    despesasPorCategoria: DespesasCategoria;
    previsao: PrevisaoRelatorio;
    /** The blocks below are not sent in the simple mode (RN-188). */
    evolucao?: MesEvolucao[];
    despesasPorTipo?: DespesaTipo[];
    compromissos?: CompromissosRelatorio;
    carteiras?: SaldoCarteira[];
    rateio?: RateioRelatorio;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Relatório', href: relatorio() }],
    },
});

const modoSimples = useModoSimples();

const periodos: { valor: PeriodoRelatorio; rotulo: string }[] = [
    { valor: 'mes', rotulo: 'Este mês' },
    { valor: '3m', rotulo: 'Últimos 3 meses' },
    { valor: '6m', rotulo: 'Últimos 6 meses' },
    { valor: '12m', rotulo: 'Últimos 12 meses' },
    { valor: 'ano', rotulo: 'Este ano' },
];

/**
 * Months of the period in words: "novembro de 2026", "junho a novembro de 2026" or
 * "dezembro de 2025 a novembro de 2026".
 */
const rotuloPeriodo = computed(() => {
    const { inicio, fim } = props.intervalo;
    if (inicio === fim) {
        return mesPorExtenso(fim);
    }

    const comeco = mesPorExtenso(
        inicio,
        inicio.slice(0, 4) !== fim.slice(0, 4),
    );

    return `${comeco} a ${mesPorExtenso(fim)}`;
});

function navegar(mudancas: { carteira?: number; periodo?: string }): void {
    router.get(
        relatorio.url(),
        modoSimples.value
            ? { periodo: mudancas.periodo ?? props.periodo }
            : {
                  carteira: props.carteira.id,
                  periodo: props.periodo,
                  ...mudancas,
              },
        { preserveScroll: true },
    );
}

const carteirasDoSeletor = computed(() => props.carteiras ?? [props.carteira]);
</script>

<template>
    <Head title="Relatório" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:gap-6">
        <header class="flex flex-wrap items-center gap-x-4 gap-y-3">
            <h1 class="font-display text-2xl font-semibold tracking-tight">
                Relatório
            </h1>
            <div
                class="flex min-w-0 flex-1 flex-wrap items-center gap-2 md:justify-end"
            >
                <!-- The simple mode has only the period selector (RN-188). -->
                <span
                    v-if="modoSimples"
                    class="flex min-w-0 items-center gap-2 text-lg font-medium"
                >
                    <CarteiraIcone
                        :cor="carteira.cor"
                        :icone="carteira.icone"
                    />
                    <span class="truncate">{{ carteira.nome }}</span>
                </span>
                <div v-else class="flex min-w-0 items-center gap-2">
                    <CarteiraIcone
                        :cor="carteira.cor"
                        :icone="carteira.icone"
                    />
                    <CampoSelecao
                        :model-value="carteira.id"
                        :opcoes="
                            carteirasDoSeletor.map((opcao) => ({
                                valor: opcao.id,
                                rotulo: opcao.nome,
                            }))
                        "
                        rotulo="Carteira"
                        class="font-medium"
                        @update:model-value="
                            (id) => id !== null && navegar({ carteira: id })
                        "
                    />
                </div>
                <CampoSelecao
                    :model-value="periodo"
                    :opcoes="
                        periodos.map((opcao) => ({
                            valor: opcao.valor,
                            rotulo: opcao.rotulo,
                        }))
                    "
                    rotulo="Período"
                    class="w-auto font-medium simples:text-lg"
                    @update:model-value="
                        (valor) => valor !== null && navegar({ periodo: valor })
                    "
                />
            </div>
        </header>

        <ResumoRelatorio
            :resumo="resumo"
            :rotulo-periodo="rotuloPeriodo"
            :simples="modoSimples"
        />

        <div class="grid gap-4 md:grid-cols-2 md:gap-6">
            <BlocoRelatorio v-if="evolucao" titulo="Evolução mensal">
                <GraficoEvolucao :meses="evolucao" />
            </BlocoRelatorio>

            <BlocoRelatorio titulo="Despesas por categoria">
                <p
                    v-if="despesasPorCategoria.ranking.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nenhuma despesa em {{ rotuloPeriodo }}.
                </p>
                <DespesasPorCategoria
                    v-else
                    :despesas="despesasPorCategoria"
                    :simples="modoSimples"
                />
            </BlocoRelatorio>

            <BlocoRelatorio v-if="despesasPorTipo" titulo="Despesas por tipo">
                <DespesasPorTipo :tipos="despesasPorTipo" />
            </BlocoRelatorio>

            <BlocoRelatorio
                titulo="Previsão"
                descricao="Saldo no fim de cada mês, só com os lançamentos já feitos"
            >
                <PrevisaoMeses :previsao="previsao" :simples="modoSimples" />
            </BlocoRelatorio>

            <BlocoRelatorio
                v-if="compromissos"
                titulo="Compromissos"
                descricao="Despesas parceladas e recorrentes desta carteira"
            >
                <CompromissosSeries :compromissos="compromissos" />
            </BlocoRelatorio>

            <div
                v-if="carteiras && rateio"
                class="grid content-start gap-4 md:gap-6"
            >
                <BlocoRelatorio
                    titulo="Saldo por carteira"
                    descricao="Saldo atual e previsto para o fim do mês"
                >
                    <SaldosCarteiras
                        :carteiras="carteiras"
                        :atual="carteira.id"
                        @escolher="(id) => navegar({ carteira: id })"
                    />
                </BlocoRelatorio>
                <BlocoRelatorio titulo="Rateio">
                    <RateioPeriodo :rateio="rateio" />
                </BlocoRelatorio>
            </div>
        </div>
    </div>
</template>
