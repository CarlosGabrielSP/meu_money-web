/**
 * "2026-11-05" as "05/11".
 */
export function diaEMes(data: string): string {
    const [, mes, dia] = data.split('-');

    return `${dia}/${mes}`;
}

/**
 * Lowercase month name of "YYYY-MM" ("novembro") and its year.
 */
function partesDoMes(mes: string): { nome: string; ano: number } {
    const [ano, numero] = mes.split('-').map(Number);
    const nome = new Intl.DateTimeFormat('pt-BR', { month: 'long' }).format(
        new Date(ano, numero - 1, 1),
    );

    return { nome, ano };
}

/**
 * "2026-11" as "Novembro 2026".
 */
export function nomeDoMes(mes: string): string {
    const { nome, ano } = partesDoMes(mes);

    return `${nome.charAt(0).toUpperCase()}${nome.slice(1)} ${ano}`;
}

/**
 * "2026-11" as "novembro de 2026", for sentences; without the year: "novembro".
 */
export function mesPorExtenso(mes: string, comAno = true): string {
    const { nome, ano } = partesDoMes(mes);

    return comAno ? `${nome} de ${ano}` : nome;
}

/**
 * "2026-11" as "nov/26", for chart axes.
 */
export function mesCurto(mes: string): string {
    const [ano, numero] = mes.split('-').map(Number);
    const nome = new Intl.DateTimeFormat('pt-BR', { month: 'short' })
        .format(new Date(ano, numero - 1, 1))
        .replace('.', '');

    return `${nome}/${String(ano).slice(2)}`;
}

/**
 * Month "YYYY-MM" shifted by the given number of months.
 */
export function deslocarMes(mes: string, meses: number): string {
    const [ano, numero] = mes.split('-').map(Number);
    const data = new Date(ano, numero - 1 + meses, 1);

    return `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * Status a new share gets when the user does not choose one (RN-013).
 */
export function executadoPorPadrao(
    tipo: 'receita' | 'despesa',
    data: string,
    hoje: string,
): boolean {
    if (data === hoje) {
        return tipo === 'receita';
    }

    return data < hoje;
}

/**
 * Today as "Y-m-d" in America/Sao_Paulo (RN-118), for the max of date fields.
 */
export function hojeEmSaoPaulo(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Sao_Paulo',
    }).format(new Date());
}

/**
 * "2026-11-05" as "05/11/2026" (RNF-004).
 */
export function dataCompleta(data: string): string {
    const [ano, mes, dia] = data.split('-');

    return `${dia}/${mes}/${ano}`;
}
