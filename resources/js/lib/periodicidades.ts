/** Series periodicities (RN-041); values match App\Enums\Periodicidade, in the same order. */
export const periodicidades = [
    { valor: 'mensal', titulo: 'Mensal' },
    { valor: 'bimestral', titulo: 'Bimestral' },
    { valor: 'trimestral', titulo: 'Trimestral' },
    { valor: 'semestral', titulo: 'Semestral' },
    { valor: 'anual', titulo: 'Anual' },
];

/**
 * Title of a periodicity ("Mensal"), or the raw value when it is unknown.
 */
export function tituloDaPeriodicidade(valor: string): string {
    return (
        periodicidades.find((periodicidade) => periodicidade.valor === valor)
            ?.titulo ?? valor
    );
}
