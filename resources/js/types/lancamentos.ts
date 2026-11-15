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
    categoriaId: number;
    carteiraId: number;
    observacao: string | null;
    executado: boolean;
    anexoNome: string | null;
    serie: SerieDaParcela | null;
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
    cotaId: number;
    lancamentoId: number;
    data: string;
    descricao: string;
    categoria: string;
    valor: number;
    executado: boolean;
    vencido: boolean;
    parcela: string | null;
    recorrente: boolean;
    compartilhado: boolean;
    temAnexo: boolean;
    edicao: LancamentoEdicao | null;
};

export type Subsecoes = {
    avista: ItemLancamento[];
    parceladas: ItemLancamento[];
    recorrentes: ItemLancamento[];
};
