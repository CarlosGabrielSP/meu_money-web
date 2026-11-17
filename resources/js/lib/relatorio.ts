const formatadorPercentual = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

/**
 * Percentage with one decimal place ("74,0%"), or "—" when there is none (RN-180).
 */
export function formatarPercentual(valor: number | null): string {
    return valor === null ? '—' : `${formatadorPercentual.format(valor)}%`;
}

/**
 * Change against the previous period with its sign ("+150,0%", "−12,5%"), or "—" (RN-180).
 */
export function formatarVariacao(valor: number | null): string {
    if (valor === null) {
        return '—';
    }

    const sinal = valor > 0 ? '+' : valor < 0 ? '−' : '';

    return `${sinal}${formatadorPercentual.format(Math.abs(valor))}%`;
}

/**
 * Fixed category colors of the charts, the same in every palette (DEC-071, RNF-008); the last one is
 * "Outras".
 */
export const coresCategorias = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
    'var(--chart-6)',
];

export const corOutras = 'var(--chart-outras)';
