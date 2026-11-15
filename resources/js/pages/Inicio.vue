<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    ListChecks,
    ListFilter,
    Plus,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import CarteiraIcone from '@/components/carteiras/CarteiraIcone.vue';
import AgruparDialog from '@/components/lancamentos/AgruparDialog.vue';
import EscopoSerieDialog from '@/components/lancamentos/EscopoSerieDialog.vue';
import GrupoItem from '@/components/lancamentos/GrupoItem.vue';
import LancamentoFormulario from '@/components/lancamentos/LancamentoFormulario.vue';
import LancamentoItem from '@/components/lancamentos/LancamentoItem.vue';
import ParticipacaoFormulario from '@/components/lancamentos/ParticipacaoFormulario.vue';
import { Button } from '@/components/ui/button';
import { useNovoLancamento } from '@/composables/useNovoLancamento';
import { deslocarMes, nomeDoMes } from '@/lib/datas';
import { formatarMoeda } from '@/lib/dinheiro';
import { home } from '@/routes';
import { desagrupar } from '@/routes/grupos';
import type {
    CarteiraResumo,
    CategoriaOpcao,
    ContatoOpcao,
    EscopoSerie,
    GrupoOpcao,
    ItemLancamento,
    LancamentoEdicao,
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

type Saldo = { estimado: number; atual: number };

const props = defineProps<{
    carteira: CarteiraResumo;
    carteiras: CarteiraResumo[];
    categorias: CategoriaOpcao[];
    contatos: ContatoOpcao[];
    grupos: GrupoOpcao[];
    mes: string;
    hoje: string;
    filtros: Filtros;
    saldos: {
        acumulado: Saldo;
        mes: Saldo;
        receitas: number;
        despesas: number;
    };
    lancamentos: Record<TipoLancamento, Subsecoes>;
}>();

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

const secoesVisiveis = computed(() =>
    secoes.filter(
        (secao) =>
            (props.filtros.tipo === null ||
                props.filtros.tipo === secao.tipo) &&
            quantidade(props.lancamentos[secao.tipo]) > 0,
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

function filtrar(campo: keyof Filtros, valor: string): void {
    navegar({
        [campo]:
            valor === '' ? null : campo === 'categoria' ? Number(valor) : valor,
    });
}

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

const { pedidos } = useNovoLancamento();

watch(pedidos, abrirNovo);

onMounted(() => {
    if (new URLSearchParams(window.location.search).get('novo') === '1') {
        abrirNovo();
    }
});
</script>

<template>
    <Head title="Início" />

    <div class="flex flex-col">
        <div class="sticky top-0 z-10 grid gap-3 border-b bg-background p-4">
            <div class="flex items-center justify-between gap-2">
                <label class="flex min-w-0 items-center gap-2">
                    <CarteiraIcone
                        :cor="carteira.cor"
                        :icone="carteira.icone"
                    />
                    <span class="sr-only">Carteira</span>
                    <select
                        :value="carteira.id"
                        class="h-9 min-w-0 rounded-md border bg-transparent px-2 text-sm font-medium"
                        @change="
                            navegar({
                                carteira: Number(
                                    ($event.target as HTMLSelectElement).value,
                                ),
                            })
                        "
                    >
                        <option
                            v-for="opcao in carteiras"
                            :key="opcao.id"
                            :value="opcao.id"
                        >
                            {{ opcao.nome }}
                        </option>
                    </select>
                </label>
                <Button
                    class="hidden md:inline-flex"
                    data-test="adicionar-lancamento"
                    @click="abrirNovo"
                >
                    <Plus /> Adicionar
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Mês anterior"
                    @click="navegar({ mes: deslocarMes(mes, -1) })"
                >
                    <ChevronLeft />
                </Button>
                <span class="min-w-36 text-center font-semibold">
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
                    :disabled="mes === mesAtual"
                    @click="navegar({ mes: mesAtual })"
                >
                    Hoje
                </Button>
                <div class="ml-auto flex items-center gap-1">
                    <label>
                        <span class="sr-only">Mês</span>
                        <select
                            :value="mes.slice(5, 7)"
                            class="h-8 rounded-md border bg-transparent px-2 text-sm"
                            @change="
                                navegar({
                                    mes: `${mes.slice(0, 4)}-${($event.target as HTMLSelectElement).value}`,
                                })
                            "
                        >
                            <option
                                v-for="(nome, indice) in nomesDosMeses"
                                :key="nome"
                                :value="String(indice + 1).padStart(2, '0')"
                            >
                                {{ nome }}
                            </option>
                        </select>
                    </label>
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

            <dl class="grid grid-cols-[auto_1fr_1fr] gap-x-4 gap-y-1 text-sm">
                <span />
                <span class="text-right text-xs text-muted-foreground"
                    >Estimado</span
                >
                <span class="text-right text-xs text-muted-foreground"
                    >Atual</span
                >
                <dt>Acumulado</dt>
                <dd class="text-right font-medium tabular-nums">
                    {{ formatarMoeda(saldos.acumulado.estimado) }}
                </dd>
                <dd class="text-right font-medium tabular-nums">
                    {{ formatarMoeda(saldos.acumulado.atual) }}
                </dd>
                <dt>Mês</dt>
                <dd class="text-right font-medium tabular-nums">
                    {{ formatarMoeda(saldos.mes.estimado) }}
                </dd>
                <dd class="text-right font-medium tabular-nums">
                    {{ formatarMoeda(saldos.mes.atual) }}
                </dd>
            </dl>
            <div class="flex justify-between text-sm tabular-nums">
                <span class="text-emerald-600 dark:text-emerald-400">
                    ▲ Receitas {{ formatarMoeda(saldos.receitas) }}
                </span>
                <span class="text-red-600 dark:text-red-400">
                    ▼ Despesas {{ formatarMoeda(saldos.despesas) }}
                </span>
            </div>
        </div>

        <div class="grid gap-4 p-4">
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :aria-expanded="mostrarFiltros"
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
                    @click="selecionando = true"
                >
                    <ListChecks /> Selecionar
                </Button>
            </div>

            <div
                v-if="mostrarFiltros"
                class="grid grid-cols-2 gap-3 md:grid-cols-4"
            >
                <label class="grid gap-1 text-sm">
                    Tipo
                    <select
                        :value="filtros.tipo ?? ''"
                        class="h-9 rounded-md border bg-transparent px-2"
                        @change="
                            filtrar(
                                'tipo',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">Todos</option>
                        <option value="receita">Receitas</option>
                        <option value="despesa">Despesas</option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    Categoria
                    <select
                        :value="filtros.categoria ?? ''"
                        class="h-9 rounded-md border bg-transparent px-2"
                        @change="
                            filtrar(
                                'categoria',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">Todas</option>
                        <option
                            v-for="categoria in categorias"
                            :key="categoria.id"
                            :value="categoria.id"
                        >
                            {{ categoria.nome }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    Status
                    <select
                        :value="filtros.status ?? ''"
                        class="h-9 rounded-md border bg-transparent px-2"
                        @change="
                            filtrar(
                                'status',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">Todos</option>
                        <option value="executado">Executado</option>
                        <option value="pendente">Pendente</option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    Compartilhado
                    <select
                        :value="filtros.compartilhado ?? ''"
                        class="h-9 rounded-md border bg-transparent px-2"
                        @change="
                            filtrar(
                                'compartilhado',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">Todos</option>
                        <option value="sim">Sim</option>
                        <option value="nao">Não</option>
                    </select>
                </label>
            </div>

            <div
                v-if="secoesVisiveis.length === 0"
                class="grid justify-items-center gap-3 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
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

            <div v-else class="grid items-start gap-6 md:grid-cols-2">
                <section
                    v-for="secao in secoesVisiveis"
                    :key="secao.tipo"
                    class="grid gap-3"
                >
                    <h2 class="text-sm font-semibold tracking-wide uppercase">
                        {{ secao.titulo }}
                    </h2>
                    <template
                        v-for="subsecao in subsecoes"
                        :key="subsecao.chave"
                    >
                        <div
                            v-if="
                                lancamentos[secao.tipo][subsecao.chave].length >
                                0
                            "
                            class="grid gap-1"
                        >
                            <h3 class="text-xs text-muted-foreground">
                                {{ subsecao.titulo }}
                            </h3>
                            <ul class="divide-y rounded-lg border">
                                <LancamentoItem
                                    v-for="item in lancamentos[secao.tipo][
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
                        v-if="lancamentos[secao.tipo].grupos.length > 0"
                        class="grid gap-1"
                    >
                        <h3 class="text-xs text-muted-foreground">Grupos</h3>
                        <ul class="divide-y rounded-lg border">
                            <GrupoItem
                                v-for="grupo in lancamentos[secao.tipo].grupos"
                                :key="grupo.chave"
                                :grupo="grupo"
                                :selecionando="selecionando"
                                :selecionados="idsSelecionados"
                                @editar="editar($event, secao.tipo)"
                                @selecionar="selecionar($event, secao.tipo)"
                            />
                        </ul>
                    </div>
                </section>
            </div>
        </div>

        <div
            v-if="selecionando"
            class="sticky bottom-20 z-10 mx-4 mb-4 flex flex-wrap items-center gap-2 rounded-lg border bg-background p-3 shadow-lg md:bottom-4"
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
        />

        <ParticipacaoFormulario
            v-model:open="participacaoAberta"
            :item="emParticipacao?.item ?? null"
            :tipo="emParticipacao?.tipo ?? 'despesa'"
            :carteiras="carteiras"
            :categorias="categorias"
        />
    </div>
</template>
