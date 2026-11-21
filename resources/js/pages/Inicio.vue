<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronLeft,
    ChevronRight,
    ListChecks,
    ListFilter,
    Plus,
} from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';
import CampoSelecao from '@/components/CampoSelecao.vue';
import CardFatura from '@/components/cartoes/CardFatura.vue';
import PagamentosFatura from '@/components/cartoes/PagamentosFatura.vue';
import PartesFatura from '@/components/cartoes/PartesFatura.vue';
import AReceberItem from '@/components/cartoes/AReceberItem.vue';
import FaturaVinculadaItem from '@/components/cartoes/FaturaVinculadaItem.vue';
import type { OpcaoSelecao } from '@/components/CampoSelecao.vue';
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import AvisosPendencias from '@/components/inicio/AvisosPendencias.vue';
import CardSaldos from '@/components/inicio/CardSaldos.vue';
import CartoesCarteira from '@/components/inicio/CartoesCarteira.vue';
import FaixaSaldos from '@/components/inicio/FaixaSaldos.vue';
import GastosDoMes from '@/components/inicio/GastosDoMes.vue';
import PainelPendencias from '@/components/inicio/PainelPendencias.vue';
import type { TipoPainel } from '@/components/inicio/PainelPendencias.vue';
import AgruparDialog from '@/components/lancamentos/AgruparDialog.vue';
import EscopoSerieDialog from '@/components/lancamentos/EscopoSerieDialog.vue';
import GrupoItem from '@/components/lancamentos/GrupoItem.vue';
import LancamentoFormulario from '@/components/lancamentos/LancamentoFormulario.vue';
import LancamentoItem from '@/components/lancamentos/LancamentoItem.vue';
import LancamentoItemSimples from '@/components/lancamentos/LancamentoItemSimples.vue';
import ParticipacaoFormulario from '@/components/lancamentos/ParticipacaoFormulario.vue';
import PerguntaModoSimples from '@/components/PerguntaModoSimples.vue';
import TourTutorial from '@/components/TourTutorial.vue';
import { Button } from '@/components/ui/button';
import { useModoSimples } from '@/composables/useModoSimples';
import { useNovoLancamento } from '@/composables/useNovoLancamento';
import { deslocarMes, nomeDoMes } from '@/lib/datas';
import { passosModoCompleto, passosModoSimples } from '@/lib/tutorial';
import { home, tutorialVisto } from '@/routes';
import { desagrupar } from '@/routes/grupos';
import type {
    CartaoDoFormulario,
    CarteiraResumo,
    CategoriaOpcao,
    ContatoOpcao,
    Delegacao,
    DestaquesInicio,
    EscopoSerie,
    FaturaDoMes,
    FaturaVinculada,
    ItemAReceber,
    GrupoOpcao,
    ItemLancamento,
    LancamentoEdicao,
    RegistroDeMembro,
    SaldosInicio,
    SerieDaParcela,
    Subsecoes,
    TipoLancamento,
} from '@/types';

type Filtros = {
    tipo: TipoLancamento | null;
    categoria: number | null;
    status: 'executado' | 'pendente' | null;
    compartilhado: 'sim' | 'nao' | null;
};

const props = defineProps<{
    carteira: CarteiraResumo;
    /** Owner of a wallet delegated to the user; null on the user's own wallet (RN-279). */
    delegacao: Delegacao | null;
    carteiras: CarteiraResumo[];
    categorias: CategoriaOpcao[];
    contatos: ContatoOpcao[];
    grupos: GrupoOpcao[];
    mes: string;
    /** Month of "Hoje": the current one, or the due month of the open invoice of a card (R3). */
    mesHoje: string;
    hoje: string;
    filtros: Filtros;
    /** Null on a card, which shows its invoice instead (RN-223). */
    saldos: SaldosInicio | null;
    fatura: FaturaDoMes | null;
    /** Invoices this wallet pays, due in the month (RN-219). */
    faturasVinculadas: FaturaVinculada[];
    /** "A receber" items of the contacts' parts the user settled, in the month (RN-243). */
    aReceber: ItemAReceber[];
    cartoes: Record<string, CartaoDoFormulario>;
    /** Subsections in the full mode; one plain list per type in the simple mode (RN-161). */
    lancamentos:
        | Record<TipoLancamento, Subsecoes>
        | Record<TipoLancamento, ItemLancamento[]>;
    perguntarModoSimples: boolean;
    mostrarTutorial: boolean;
    /** Null in the simple mode (RN-175). */
    destaques: DestaquesInicio | null;
}>();

const modoSimples = useModoSimples();
const ehCartao = computed(() => props.carteira.tipo === 'cartao');

const listaSimples = computed<Record<TipoLancamento, ItemLancamento[]> | null>(
    () =>
        Array.isArray(props.lancamentos.receita)
            ? (props.lancamentos as Record<TipoLancamento, ItemLancamento[]>)
            : null,
);

const listaCompleta = computed<Record<TipoLancamento, Subsecoes> | null>(() =>
    listaSimples.value === null
        ? (props.lancamentos as Record<TipoLancamento, Subsecoes>)
        : null,
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Início', href: home() }],
    },
});

const secoes: { tipo: TipoLancamento; titulo: string }[] = [
    { tipo: 'receita', titulo: 'Receitas' },
    { tipo: 'despesa', titulo: 'Despesas' },
];

const subsecoes: {
    chave: Exclude<keyof Subsecoes, 'grupos'>;
    titulo: string;
}[] = [
    { chave: 'avista', titulo: 'À vista' },
    { chave: 'parceladas', titulo: 'Parceladas' },
    { chave: 'recorrentes', titulo: 'Recorrentes' },
];

const mostrarFiltros = ref(
    Object.values(props.filtros).some((valor) => valor !== null),
);
const formularioAberto = ref(false);
const emEdicao = ref<{
    id: number;
    edicao: LancamentoEdicao;
    serie: SerieDaParcela | null;
    faturaFechada: boolean;
    registro: RegistroDeMembro | null;
} | null>(null);
const participacaoAberta = ref(false);
const emParticipacao = ref<{
    item: ItemLancamento;
    tipo: TipoLancamento;
} | null>(null);

const temFiltros = computed(() =>
    Object.values(props.filtros).some((valor) => valor !== null),
);

const mesAtual = computed(() => props.hoje.slice(0, 7));

const dataPadrao = computed(() =>
    props.mes === mesAtual.value ? props.hoje : `${props.mes}-01`,
);

const quantidade = (subsecoesDoTipo: Subsecoes): number =>
    subsecoesDoTipo.avista.length +
    subsecoesDoTipo.parceladas.length +
    subsecoesDoTipo.recorrentes.length +
    subsecoesDoTipo.grupos.length;

function quantidadeNaSecao(tipo: TipoLancamento): number {
    const faturas =
        tipo === 'despesa'
            ? props.faturasVinculadas.length
            : props.aReceber.length;

    if (listaSimples.value !== null) {
        return listaSimples.value[tipo].length + faturas;
    }

    return listaCompleta.value === null
        ? 0
        : quantidade(listaCompleta.value[tipo]) + faturas;
}

const secoesVisiveis = computed(() =>
    secoes.filter(
        (secao) =>
            (props.filtros.tipo === null ||
                props.filtros.tipo === secao.tipo) &&
            quantidadeNaSecao(secao.tipo) > 0,
    ),
);

function navegar(
    mudancas: Partial<Filtros & { carteira: number; mes: string }>,
): void {
    const query = {
        carteira: props.carteira.id,
        mes: props.mes,
        ...props.filtros,
        ...mudancas,
    };

    router.get(
        home.url(),
        Object.fromEntries(
            Object.entries(query).filter(
                ([, valor]) => valor !== null && valor !== '',
            ),
        ),
        { preserveScroll: true, preserveState: true },
    );
}

function filtrarValor(
    campo: keyof Filtros,
    valor: string | number | null,
): void {
    navegar({ [campo]: valor });
}

const opcoesTipo: OpcaoSelecao<string>[] = [
    { valor: null, rotulo: 'Todos' },
    { valor: 'receita', rotulo: 'Receitas' },
    { valor: 'despesa', rotulo: 'Despesas' },
];

const opcoesCategoria = computed<OpcaoSelecao<number>[]>(() => [
    { valor: null, rotulo: 'Todas' },
    ...props.categorias.map((categoria) => ({
        valor: categoria.id,
        rotulo: categoria.nome,
    })),
]);

const opcoesStatus: OpcaoSelecao<string>[] = [
    { valor: null, rotulo: 'Todos' },
    { valor: 'executado', rotulo: 'Pago ou recebido' },
    { valor: 'pendente', rotulo: 'Pendente' },
];

const opcoesCompartilhado: OpcaoSelecao<string>[] = [
    { valor: null, rotulo: 'Todos' },
    { valor: 'sim', rotulo: 'Sim' },
    { valor: 'nao', rotulo: 'Não' },
];

const nomesDosMeses = Array.from({ length: 12 }, (_, indice) =>
    new Intl.DateTimeFormat('pt-BR', { month: 'short' })
        .format(new Date(2000, indice, 1))
        .replace('.', ''),
);

function escolherAno(ano: string): void {
    if (/^\d{4}$/.test(ano)) {
        navegar({ mes: `${ano}-${props.mes.slice(5, 7)}` });
    }
}

function limparFiltros(): void {
    navegar({ tipo: null, categoria: null, status: null, compartilhado: null });
}

function abrirNovo(): void {
    emEdicao.value = null;
    formularioAberto.value = true;
}

/**
 * The author edits the entry (RN-117); a participant edits only their own share (RN-080).
 */
function editar(item: ItemLancamento, tipo: TipoLancamento): void {
    if (item.edicao !== null) {
        emEdicao.value = {
            id: item.lancamentoId,
            edicao: item.edicao,
            serie: item.serie,
            faturaFechada: item.faturaFechada,
            registro: item.registro,
        };
        formularioAberto.value = true;
    } else if (item.participacao !== null) {
        emParticipacao.value = { item, tipo };
        participacaoAberta.value = true;
    }
}

/**
 * Selection for "Agrupar / Desagrupar" (RN-125): only items with a share of the user.
 */
const selecionando = ref(false);
const selecao = ref(
    new Map<number, { item: ItemLancamento; tipo: TipoLancamento }>(),
);
const agrupando = ref(false);
const escopoDesagrupar = ref(false);
const desagrupando = ref(false);

const idsSelecionados = computed(() => new Set(selecao.value.keys()));

const selecionados = computed(() => [...selecao.value.values()]);

const tipoDaSelecao = computed<TipoLancamento | null>(() => {
    const tipos = new Set(selecionados.value.map(({ tipo }) => tipo));

    return tipos.size === 1 ? [...tipos][0] : null;
});

const temParcelaSelecionada = computed(() =>
    selecionados.value.some(({ item }) => item.serie !== null),
);

const temAgrupado = computed(() =>
    selecionados.value.some(({ item }) => item.grupoId !== null),
);

function selecionar(item: ItemLancamento, tipo: TipoLancamento): void {
    if (item.cotaId === null) {
        return;
    }

    selecionando.value = true;

    if (selecao.value.has(item.cotaId)) {
        selecao.value.delete(item.cotaId);
    } else {
        selecao.value.set(item.cotaId, { item, tipo });
    }
}

function encerrarSelecao(): void {
    selecionando.value = false;
    selecao.value.clear();
}

function pedirDesagrupar(): void {
    const parcelaAgrupada = selecionados.value.some(
        ({ item }) => item.serie !== null && item.grupoId !== null,
    );

    if (parcelaAgrupada) {
        escopoDesagrupar.value = true;
    } else {
        confirmarDesagrupar('esta');
    }
}

function confirmarDesagrupar(escopo: EscopoSerie): void {
    router.post(
        desagrupar.url(),
        {
            cotas: selecionados.value
                .filter(({ item }) => item.grupoId !== null)
                .map(({ item }) => item.cotaId),
            escopo,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (desagrupando.value = true),
            onFinish: () => (desagrupando.value = false),
            onSuccess: () => {
                escopoDesagrupar.value = false;
                encerrarSelecao();
            },
        },
    );
}

watch(
    () => [props.carteira.id, props.mes, JSON.stringify(props.filtros)],
    encerrarSelecao,
);

/**
 * The main card turns into the compact strip while it is off the screen (RN-170).
 */
const card = useTemplateRef<HTMLElement>('card');
const cardVisivel = ref(true);

useIntersectionObserver(card, ([entrada]) => {
    cardVisivel.value = entrada?.isIntersecting ?? true;
});

const painel = ref<TipoPainel | null>(null);

const { pedidos } = useNovoLancamento();

watch(pedidos, abrirNovo);

/**
 * The tour opens after the simple mode question: answering reloads the page without the question,
 * and closing it without an answer leaves the tour for the next visit (RN-165, DEC-069).
 */
const tutorialPendente = computed(
    () => props.mostrarTutorial && !props.perguntarModoSimples,
);
const tutorialAberto = ref(tutorialPendente.value);

watch(tutorialPendente, (pendente) => {
    if (pendente) {
        tutorialAberto.value = true;
    }
});

const terminandoTutorial = ref(false);

/**
 * Finishing or skipping saves the tutorial as seen; the server keeps the first date, so "Ver
 * tutorial" changes nothing (RN-165, RN-167). The server redirects to the same screen without
 * "?tutorial=1" and "?novo=1" (DEC-069). The tour stays open, with its buttons disabled, until
 * the answer arrives, so no click on the page can race the save.
 */
function terminarTutorial(): void {
    if (terminandoTutorial.value) {
        return;
    }

    router.post(
        tutorialVisto.url(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (terminandoTutorial.value = true),
            onFinish: () => {
                terminandoTutorial.value = false;
                tutorialAberto.value = false;
            },
        },
    );
}

onMounted(() => {
    // The first access question and the tour come first; the form opens with (+) after them
    // (RN-156, DEC-069).
    if (
        !props.perguntarModoSimples &&
        !tutorialAberto.value &&
        new URLSearchParams(window.location.search).get('novo') === '1'
    ) {
        abrirNovo();
    }
});
</script>

<template>
    <Head title="Início" />

    <div class="flex flex-col">
        <FaixaSaldos
            :visivel="!cardVisivel"
            :carteira="
                delegacao
                    ? `${carteira.nome} · de ${delegacao.donoNome}`
                    : carteira.nome
            "
            :mes="nomeDoMes(mes)"
            :saldos="saldos"
            :a-pagar="fatura?.aPagar ?? null"
            :simples="modoSimples"
        />

        <div class="grid gap-4 p-4 md:gap-5 md:p-6">
            <div class="flex items-center gap-3">
                <!-- A delegated wallet shows the owner's band instead of the wallet cards and the
                     selector, in both modes (RN-279, nota 07). -->
                <div
                    v-if="delegacao"
                    class="flex min-w-0 flex-1 flex-wrap items-center gap-3"
                    data-test="faixa-delegada"
                >
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="home()">
                            <ArrowLeft /> Minhas carteiras
                        </Link>
                    </Button>
                    <span class="flex min-w-0 items-center gap-2">
                        <CarteiraIcone
                            :cor="carteira.cor"
                            :icone="carteira.icone"
                        />
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{
                                carteira.nome
                            }}</span>
                            <span class="block text-xs text-muted-foreground"
                                >Carteira de {{ delegacao.donoNome }} · você é
                                membro</span
                            >
                        </span>
                    </span>
                </div>
                <CartoesCarteira
                    v-else-if="destaques && destaques.saldosCarteiras"
                    class="min-w-0 flex-1"
                    :carteiras="carteiras"
                    :atual="carteira.id"
                    :saldos="destaques.saldosCarteiras"
                    @escolher="navegar({ carteira: $event })"
                />
                <div
                    v-else
                    class="flex min-w-0 items-center gap-2"
                    data-tutorial="carteira"
                >
                    <CarteiraIcone
                        :cor="carteira.cor"
                        :icone="carteira.icone"
                    />
                    <CampoSelecao
                        :model-value="carteira.id"
                        :opcoes="
                            carteiras.map((opcao) => ({
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
                <Button
                    class="ml-auto hidden shrink-0 md:inline-flex"
                    data-test="adicionar-lancamento"
                    data-tutorial="adicionar"
                    @click="abrirNovo"
                >
                    <Plus /> Adicionar
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div
                    class="flex flex-wrap items-center gap-2"
                    data-tutorial="mes"
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Mês anterior"
                        @click="navegar({ mes: deslocarMes(mes, -1) })"
                    >
                        <ChevronLeft />
                    </Button>
                    <span
                        class="min-w-36 text-center font-display text-lg font-semibold"
                    >
                        {{ nomeDoMes(mes) }}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Mês seguinte"
                        @click="navegar({ mes: deslocarMes(mes, 1) })"
                    >
                        <ChevronRight />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="mes === mesHoje"
                        @click="navegar({ mes: mesHoje })"
                    >
                        Hoje
                    </Button>
                </div>
                <div class="ml-auto flex items-center gap-1">
                    <CampoSelecao
                        :model-value="mes.slice(5, 7)"
                        :opcoes="
                            nomesDosMeses.map((nome, indice) => ({
                                valor: String(indice + 1).padStart(2, '0'),
                                rotulo: nome,
                            }))
                        "
                        rotulo="Mês"
                        class="h-8 w-auto"
                        @update:model-value="
                            (numero) =>
                                numero !== null &&
                                navegar({ mes: `${mes.slice(0, 4)}-${numero}` })
                        "
                    />
                    <label>
                        <span class="sr-only">Ano</span>
                        <input
                            type="number"
                            min="1000"
                            max="9999"
                            :value="mes.slice(0, 4)"
                            class="h-8 w-20 rounded-md border bg-transparent px-2 text-sm"
                            @change="
                                escolherAno(
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </label>
                </div>
            </div>

            <div
                class="grid items-start gap-4"
                :class="
                    destaques
                        ? 'md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]'
                        : 'md:max-w-xl'
                "
            >
                <div ref="card" class="grid gap-4">
                    <CardFatura
                        v-if="fatura"
                        :cartao-id="carteira.id"
                        :cartao-nome="carteira.nome"
                        :autor-do-espelho="carteira.autorDoEspelho ?? null"
                        :fatura="fatura"
                        :hoje="hoje"
                        :simples="modoSimples"
                    />
                    <CardSaldos
                        v-else-if="saldos"
                        :saldos="saldos"
                        :simples="modoSimples"
                    />
                </div>
                <div v-if="destaques" class="grid gap-4">
                    <PartesFatura v-if="fatura" :partes="fatura.partes" />
                    <PagamentosFatura v-if="fatura" :fatura="fatura" />
                    <AvisosPendencias
                        v-if="destaques.vencidas && destaques.proximas"
                        :vencidas="destaques.vencidas"
                        :proximas="destaques.proximas"
                        @abrir="painel = $event"
                    />
                    <GastosDoMes
                        :gastos="destaques.gastosDoMes"
                        :total-despesas="
                            saldos?.despesas ?? fatura?.compras ?? 0
                        "
                        :carteira-id="carteira.id"
                        :titulo="ehCartao ? 'Gastos da fatura' : undefined"
                        :sem-relatorio="delegacao !== null"
                    />
                </div>
            </div>
            <!-- The simple mode has no filters and no selection to group (RN-161). -->
            <div v-if="!modoSimples" class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :aria-expanded="mostrarFiltros"
                    data-tutorial="filtros"
                    @click="mostrarFiltros = !mostrarFiltros"
                >
                    <ListFilter /> Filtros
                </Button>
                <Button
                    v-if="temFiltros"
                    variant="ghost"
                    size="sm"
                    @click="limparFiltros"
                >
                    Limpar filtros
                </Button>
                <Button
                    v-if="!selecionando && secoesVisiveis.length > 0"
                    variant="outline"
                    size="sm"
                    class="ml-auto"
                    data-tutorial="selecionar"
                    @click="selecionando = true"
                >
                    <ListChecks /> Selecionar
                </Button>
            </div>

            <div
                v-if="mostrarFiltros && !modoSimples"
                class="grid grid-cols-2 gap-3 md:grid-cols-4"
            >
                <div class="grid gap-1 text-sm">
                    <span aria-hidden="true">Tipo</span>
                    <CampoSelecao
                        :model-value="filtros.tipo"
                        :opcoes="opcoesTipo"
                        rotulo="Tipo"
                        @update:model-value="
                            (valor) => filtrarValor('tipo', valor)
                        "
                    />
                </div>
                <div class="grid gap-1 text-sm">
                    <span aria-hidden="true">Categoria</span>
                    <CampoSelecao
                        :model-value="filtros.categoria"
                        :opcoes="opcoesCategoria"
                        rotulo="Categoria"
                        @update:model-value="
                            (valor) => filtrarValor('categoria', valor)
                        "
                    />
                </div>
                <!-- A card has no status, so no status filter (RN-223). -->
                <div v-if="!ehCartao" class="grid gap-1 text-sm">
                    <span aria-hidden="true">Status</span>
                    <CampoSelecao
                        :model-value="filtros.status"
                        :opcoes="opcoesStatus"
                        rotulo="Status"
                        @update:model-value="
                            (valor) => filtrarValor('status', valor)
                        "
                    />
                </div>
                <div class="grid gap-1 text-sm">
                    <span aria-hidden="true">Compartilhado</span>
                    <CampoSelecao
                        :model-value="filtros.compartilhado"
                        :opcoes="opcoesCompartilhado"
                        rotulo="Compartilhado"
                        @update:model-value="
                            (valor) => filtrarValor('compartilhado', valor)
                        "
                    />
                </div>
            </div>

            <div
                v-if="secoesVisiveis.length === 0"
                data-tutorial="lista-vazia"
                class="grid justify-items-center gap-3 rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                <template v-if="temFiltros">
                    Nenhum lançamento com esses filtros.
                </template>
                <template v-else>
                    Nenhum lançamento neste mês.
                    <Button size="sm" @click="abrirNovo">
                        <Plus /> Adicionar lançamento
                    </Button>
                </template>
            </div>

            <div
                v-else-if="listaSimples"
                class="grid items-start gap-6 md:grid-cols-2"
            >
                <section
                    v-for="secao in secoesVisiveis"
                    :key="secao.tipo"
                    class="grid gap-3"
                >
                    <h2 class="text-lg font-semibold">
                        {{ secao.titulo }}
                    </h2>
                    <ul
                        class="divide-y overflow-hidden rounded-2xl border bg-card"
                    >
                        <LancamentoItemSimples
                            v-for="item in listaSimples[secao.tipo]"
                            :key="item.chave"
                            :item="item"
                            :tipo="secao.tipo"
                            @editar="editar($event, secao.tipo)"
                        />
                        <template v-if="secao.tipo === 'despesa'">
                            <FaturaVinculadaItem
                                v-for="linha in faturasVinculadas"
                                :key="linha.chave"
                                :fatura="linha"
                                :hoje="hoje"
                                simples
                            />
                        </template>
                        <template v-else>
                            <AReceberItem
                                v-for="linha in aReceber"
                                :key="linha.chave"
                                :item="linha"
                                simples
                            />
                        </template>
                    </ul>
                </section>
            </div>

            <div
                v-else-if="listaCompleta"
                class="grid items-start gap-6 md:grid-cols-2"
            >
                <section
                    v-for="secao in secoesVisiveis"
                    :key="secao.tipo"
                    class="grid gap-3"
                >
                    <h2 class="text-lg font-semibold">
                        {{ secao.titulo }}
                    </h2>
                    <template
                        v-for="subsecao in subsecoes"
                        :key="subsecao.chave"
                    >
                        <div
                            v-if="
                                listaCompleta[secao.tipo][subsecao.chave]
                                    .length > 0
                            "
                            class="grid gap-1"
                        >
                            <h3 class="text-xs text-muted-foreground">
                                {{ subsecao.titulo }}
                            </h3>
                            <ul
                                class="divide-y overflow-hidden rounded-2xl border bg-card"
                            >
                                <LancamentoItem
                                    v-for="item in listaCompleta[secao.tipo][
                                        subsecao.chave
                                    ]"
                                    :key="item.chave"
                                    :item="item"
                                    :selecionando="selecionando"
                                    :selecionado="
                                        item.cotaId !== null &&
                                        idsSelecionados.has(item.cotaId)
                                    "
                                    @editar="editar($event, secao.tipo)"
                                    @selecionar="selecionar($event, secao.tipo)"
                                />
                            </ul>
                        </div>
                    </template>
                    <div
                        v-if="listaCompleta[secao.tipo].grupos.length > 0"
                        class="grid gap-1"
                    >
                        <h3 class="text-xs text-muted-foreground">Grupos</h3>
                        <ul
                            class="divide-y overflow-hidden rounded-2xl border bg-card"
                        >
                            <GrupoItem
                                v-for="grupo in listaCompleta[secao.tipo]
                                    .grupos"
                                :key="grupo.chave"
                                :grupo="grupo"
                                :selecionando="selecionando"
                                :selecionados="idsSelecionados"
                                @editar="editar($event, secao.tipo)"
                                @selecionar="selecionar($event, secao.tipo)"
                            />
                        </ul>
                    </div>
                    <div
                        v-if="secao.tipo === 'receita' && aReceber.length > 0"
                        class="grid gap-1"
                    >
                        <h3 class="text-xs text-muted-foreground">A receber</h3>
                        <ul
                            class="divide-y overflow-hidden rounded-2xl border bg-card"
                        >
                            <AReceberItem
                                v-for="linha in aReceber"
                                :key="linha.chave"
                                :item="linha"
                            />
                        </ul>
                    </div>
                    <div
                        v-if="
                            secao.tipo === 'despesa' &&
                            faturasVinculadas.length > 0
                        "
                        class="grid gap-1"
                    >
                        <h3 class="text-xs text-muted-foreground">Cartões</h3>
                        <ul
                            class="divide-y overflow-hidden rounded-2xl border bg-card"
                        >
                            <FaturaVinculadaItem
                                v-for="linha in faturasVinculadas"
                                :key="linha.chave"
                                :fatura="linha"
                                :hoje="hoje"
                                @editar="editar($event, 'despesa')"
                            />
                        </ul>
                    </div>
                </section>
            </div>
        </div>

        <div
            v-if="selecionando && !modoSimples"
            class="sticky bottom-24 z-10 mx-4 mb-4 flex flex-wrap items-center gap-2 rounded-2xl border bg-background p-3 shadow-lg md:bottom-4"
            role="toolbar"
            aria-label="Ações da seleção"
        >
            <span class="text-sm">
                {{ selecao.size }}
                {{ selecao.size === 1 ? 'selecionado' : 'selecionados' }}
            </span>
            <div class="ml-auto flex gap-2">
                <Button
                    size="sm"
                    :disabled="selecao.size === 0"
                    @click="agrupando = true"
                >
                    Agrupar
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="!temAgrupado || desagrupando"
                    @click="pedirDesagrupar"
                >
                    Desagrupar
                </Button>
                <Button size="sm" variant="ghost" @click="encerrarSelecao">
                    Cancelar
                </Button>
            </div>
        </div>

        <AgruparDialog
            v-model:open="agrupando"
            :cotas="[...idsSelecionados]"
            :tipo="tipoDaSelecao"
            :tem-parcela="temParcelaSelecionada"
            :grupos="grupos"
            @concluido="encerrarSelecao"
        />

        <EscopoSerieDialog
            v-model:open="escopoDesagrupar"
            acao="desagrupar"
            :processando="desagrupando"
            @confirmar="confirmarDesagrupar"
        />

        <LancamentoFormulario
            v-model:open="formularioAberto"
            :lancamento-id="emEdicao?.id ?? null"
            :edicao="emEdicao?.edicao ?? null"
            :serie="emEdicao?.serie ?? null"
            :carteira-id="carteira.id"
            :contatos="contatos"
            :data-padrao="dataPadrao"
            :hoje="hoje"
            :carteiras="carteiras"
            :categorias="categorias"
            :cartoes="cartoes"
            :fatura-fechada="emEdicao?.faturaFechada ?? false"
            :delegacao="delegacao"
            :registro="emEdicao?.registro ?? null"
        />

        <PerguntaModoSimples :perguntar="perguntarModoSimples" />
        <TourTutorial
            v-if="tutorialAberto"
            :passos="modoSimples ? passosModoSimples : passosModoCompleto"
            :terminando="terminandoTutorial"
            @terminar="terminarTutorial"
        />

        <PainelPendencias
            :painel="painel"
            :quantidade="
                painel === 'vencidas'
                    ? (destaques?.vencidas?.quantidade ?? 0)
                    : (destaques?.proximas?.quantidade ?? 0)
            "
            @fechar="painel = null"
        />

        <ParticipacaoFormulario
            v-model:open="participacaoAberta"
            :item="emParticipacao?.item ?? null"
            :tipo="emParticipacao?.tipo ?? 'despesa'"
            :carteiras="carteiras"
            :categorias="categorias"
            :membro="delegacao !== null"
        />
    </div>
</template>
