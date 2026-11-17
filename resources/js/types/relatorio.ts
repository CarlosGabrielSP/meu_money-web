/** Period of the report (RN-179). */
export type PeriodoRelatorio = 'mes' | '3m' | '6m' | '12m' | 'ano';

/** Summary of the period with the change against the previous one (RN-180). */
export type ResumoRelatorio = {
    receitas: number;
    despesas: number;
    resultado: number;
    taxaPoupanca: number | null;
    variacoes: {
        receitas: number | null;
        despesas: number | null;
        resultado: number | null;
    };
};

/** One month of the monthly evolution (RN-181). */
export type MesEvolucao = {
    mes: string;
    receitas: number;
    despesas: number;
    acumulado: number;
};

/** Expenses by category: ranking and donut (RN-182). */
export type DespesasCategoria = {
    ranking: {
        id: number;
        nome: string;
        icone: string;
        valor: number;
        percentual: number | null;
        variacao: number | null;
    }[];
    rosca: { nome: string; valor: number; outras: boolean }[];
};

/** Expenses by kind (RN-183). */
export type DespesaTipo = {
    tipo: 'avista' | 'parceladas' | 'recorrentes';
    valor: number;
    percentual: number | null;
};

/** Forecast of the next months (RN-184). */
export type PrevisaoRelatorio = {
    meses: { mes: string; resultado: number; acumulado: number }[];
    primeiroNegativo: string | null;
};

/** Series that still bind the wallet (RN-185). */
export type CompromissosRelatorio = {
    parceladas: {
        series: {
            id: number;
            descricao: string;
            parcelasPendentes: number;
            restante: number;
            ultimaParcela: string;
        }[];
        total: number;
    };
    recorrentes: {
        series: {
            id: number;
            descricao: string;
            proximoValor: number;
            periodicidade: string;
            mensal: number;
        }[];
        total: number;
    };
};

/** Balance of one wallet until the end of the current month (RN-186). */
export type SaldoCarteira = {
    id: number;
    nome: string;
    cor: string;
    icone: string;
    atual: number;
    estimado: number;
};

export type TotaisRateio = {
    lancamentos: number;
    despesas: number;
    receitas: number;
};

/** Sharing in the period (RN-187). */
export type RateioRelatorio = {
    dividiu: TotaisRateio;
    recebeu: TotaisRateio;
};
