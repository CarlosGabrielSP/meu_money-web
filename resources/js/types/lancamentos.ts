export type TipoLancamento = 'receita' | 'despesa';

export type CarteiraResumo = {
    id: number;
    nome: string;
    cor: string;
    icone: string;
};

export type CategoriaOpcao = {
    id: number;
    nome: string;
    tipo: TipoLancamento | 'ambos';
};

export type LancamentoEdicao = {
    tipo: TipoLancamento;
    descricao: string;
    valor: number;
    data: string;
    categoriaId: number | null;
    carteiraId: number;
    observacao: string | null;
    executado: boolean;
    anexoNome: string | null;
    rateio: RateioEdicao | null;
};

export type ModoRateio = 'iguais' | 'percentual' | 'fixo';

export type ParticipanteRateio = {
    userId: number;
    nome: string;
    valor: number;
    percentual: string | null;
    executado: boolean;
};

export type RateioEdicao = {
    modo: ModoRateio;
    autorParticipa: boolean;
    bloqueado: boolean;
    participantes: ParticipanteRateio[];
};

export type Participacao = {
    carteiraId: number;
    categoriaId: number;
    executado: boolean;
    observacao: string | null;
    anexoNome: string | null;
};

export type ContatoOpcao = {
    id: number;
    nome: string;
};

export type SerieDaParcela = {
    id: number;
    recorrente: boolean;
    numero: number;
    total: number | null;
    diaVencimento: number;
    encerradaEm: string | null;
};

export type EscopoSerie = 'esta' | 'proximas' | 'todas';

export type ItemLancamento = {
    chave: string;
    cotaId: number | null;
    grupoId: number | null;
    lancamentoId: number;
    data: string;
    descricao: string;
    categoria: string | null;
    /** Icon of the share category (RN-176); null for an author without a share. */
    categoriaIcone: string | null;
    valor: number;
    total: number;
    autor: string | null;
    executado: boolean;
    vencido: boolean;
    parcela: string | null;
    recorrente: boolean;
    compartilhado: boolean;
    temAnexo: boolean;
    serie: SerieDaParcela | null;
    edicao: LancamentoEdicao | null;
    participacao: Participacao | null;
};

export type StatusGrupo = 'executado' | 'parcial' | 'pendente';

export type GrupoLinha = {
    chave: string;
    id: number;
    nome: string;
    quantidade: number;
    valor: number;
    status: StatusGrupo;
    filtrado: boolean;
    itens: ItemLancamento[];
};

export type GrupoOpcao = {
    id: number;
    nome: string;
    tipo: TipoLancamento;
};

export type Subsecoes = {
    avista: ItemLancamento[];
    parceladas: ItemLancamento[];
    recorrentes: ItemLancamento[];
    grupos: GrupoLinha[];
};

export type Saldo = { estimado: number; atual: number };

/** Balances of the main card (RN-092, RN-169). */
export type SaldosInicio = {
    acumulado: Saldo;
    mes: Saldo;
    receitas: number;
    despesas: number;
    aReceber: number;
    aPagar: number;
};

/** One pending share of the overdue or upcoming panel (RN-172, RN-173). */
export type CotaPendente = {
    cotaId: number;
    data: string;
    descricao: string;
    tipo: TipoLancamento;
    valor: number;
};

export type GastoDoMes = {
    nome: string;
    icone: string;
    valor: number;
};

/** Highlights of the main screen in the full mode (RN-171 to RN-174). */
export type DestaquesInicio = {
    saldosCarteiras: Record<string, number>;
    vencidas: { quantidade: number; receitas: number; despesas: number };
    proximas: { quantidade: number; total: number };
    gastosDoMes: GastoDoMes[];
};
