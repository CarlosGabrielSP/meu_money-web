const formatador = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * Format an amount in cents as pt-BR ("1.234,56").
 */
export function formatarCentavos(centavos: number): string {
    return formatador.format(centavos / 100);
}

const formatadorMoeda = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

/**
 * Format an amount in cents as BRL currency ("R$ 1.234,56", RNF-004).
 */
export function formatarMoeda(centavos: number): string {
    return formatadorMoeda.format(centavos / 100);
}

/**
 * Mask typed text as a pt-BR amount, reading the digits as cents (CA-085.3).
 */
export function mascararValor(digitado: string): string {
    const digitos = digitado.replace(/\D/g, '').replace(/^0+/, '').slice(0, 11);

    return digitos === '' ? '' : formatarCentavos(Number(digitos));
}

/**
 * Cents typed in a pt-BR amount ("1.234,56" = 123456), or 0 when empty.
 */
export function centavosDe(texto: string): number {
    const digitos = texto.replace(/\D/g, '');

    return digitos === '' ? 0 : Number(digitos);
}
