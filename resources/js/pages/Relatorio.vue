<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import BotaoPrivacidade from '@/components/BotaoPrivacidade.vue';
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
    VisaoRelatorio,
    RateioRelatorio,
    ResumoRelatorio as Resumo,
    SaldoCarteira,
} from '@/types';

const props = defineProps<{
    carteira: CarteiraResumo;
    periodo: PeriodoRelatorio;
    /** Month result or accumulated balance (RN-252). */
    visao: VisaoRelatorio;
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
    /** Cards apart from the common wallets, with their debt (RN-227). */
    cartoes?: SaldoCarteira[];
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

function navegar(mudancas: {
    carteira?: number;
    periodo?: string;
    visao?: VisaoRelatorio;
}): void {
    router.get(
        relatorio.url(),
        modoSimples.value
            ? {
                  periodo: mudancas.periodo ?? props.periodo,
                  visao: mudancas.visao ?? props.visao,
              }
            : {
                  carteira: props.carteira.id,
                  periodo: props.periodo,
                  visao: props.visao,
                  ...mudancas,
              },
        { preserveScroll: true },
    );
}

const visoes: { valor: VisaoRelatorio; rotulo: string }[] = [
    { valor: 'mes', rotulo: 'Resultado do mês' },
    { valor: 'acumulado', rotulo: 'Saldo acumulado' },
];

/** The cards come after the common wallets (nota 07). */
const carteirasDoSeletor = computed(() =>
    props.carteiras
        ? [...props.carteiras, ...(props.cartoes ?? [])]
        : [props.carteira],
);
const ehCartao = computed(() => props.carteira.tipo === 'cartao');
</script>

<template>
    <Head title="Relatório" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:gap-6">
        <header
            class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-3 md:grid-cols-[auto_minmax(0,1fr)_auto]"
        >
            <h1 class="font-display text-2xl font-semibold tracking-tight">
                Relatório
            </h1>
            <BotaoPrivacidade class="col-start-2 row-start-1 md:col-start-3" />
            <div
                class="col-span-2 flex min-w-0 flex-wrap items-center gap-2 md:col-span-1 md:col-start-2 md:row-start-1 md:justify-end"
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
                <!-- Month result or accumulated balance in the evolution and the forecast (RN-252). -->
                <div
                    role="group"
                    aria-label="Visão"
                    class="inline-flex rounded-md border p-0.5"
                >
                    <button
                        v-for="opcao in visoes"
                        :key="opcao.valor"
                        type="button"
                        class="rounded px-3 py-1 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none simples:min-h-12 simples:text-base"
                        :class="
                            visao === opcao.valor
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent'
                        "
                        :aria-pressed="visao === opcao.valor"
                        @click="
                            visao !== opcao.valor &&
                            navegar({ visao: opcao.valor })
                        "
                    >
                        {{ opcao.rotulo }}
                    </button>
                </div>
            </div>
        </header>

        <ResumoRelatorio
            :resumo="resumo"
            :rotulo-periodo="rotuloPeriodo"
            :simples="modoSimples"
            :sem-taxa="ehCartao"
        />

        <div class="grid gap-4 md:grid-cols-2 md:gap-6">
            <BlocoRelatorio v-if="evolucao" titulo="Evolução mensal">
                <GraficoEvolucao
                    :meses="evolucao"
                    :rotulo-despesas="ehCartao ? 'Compras' : 'Despesas'"
                    :visao="visao"
                    :rotulo-linha="
                        visao === 'mes'
                            ? 'Resultado do mês'
                            : ehCartao
                              ? 'Dívida do cartão no fim do mês'
                              : 'Saldo acumulado no fim do mês'
                    "
                />
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
                :descricao="
                    visao === 'mes'
                        ? 'Resultado de cada mês, só com os lançamentos já feitos'
                        : ehCartao
                          ? 'Dívida do cartão no fim de cada mês, só com as compras já feitas'
                          : 'Saldo no fim de cada mês, só com os lançamentos já feitos'
                "
            >
                <PrevisaoMeses
                    :previsao="previsao"
                    :simples="modoSimples"
                    :neutro="ehCartao"
                    :visao="visao"
                />
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
                    descricao="Saldo atual e estimado para o fim do mês"
                >
                    <SaldosCarteiras
                        :carteiras="carteiras"
                        :cartoes="cartoes ?? []"
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
