import type { ModoRateio } from '@/types';

export type ParticipanteCalculo = {
    userId: number;
    percentual: string;
    valor: number;
};

/**
 * Preview of each share, mirroring the server split (RN-072 to RN-075, RN-143): equal parts,
 * percentages rounded down or fixed amounts, with the remaining cents on the author or, without
 * an author share, on the first participant.
 */
export function calcularCotas(
    total: number,
    modo: ModoRateio,
    participantes: ParticipanteCalculo[],
    autorId: number,
): Map<number, number> {
    const cotas = new Map<number, number>();

    if (participantes.length === 0) {
        return cotas;
    }

    for (const participante of participantes) {
        const valor =
            modo === 'iguais'
                ? Math.floor(total / participantes.length)
                : modo === 'percentual'
                  ? Math.floor(
                        (total *
                            Math.round(
                                Number(
                                    participante.percentual.replace(',', '.'),
                                ) * 100,
                            )) /
                            10_000,
                    )
                  : participante.valor;

        cotas.set(participante.userId, Number.isFinite(valor) ? valor : 0);
    }

    const soma = [...cotas.values()].reduce((acc, valor) => acc + valor, 0);
    const recebe = cotas.has(autorId) ? autorId : participantes[0].userId;
    cotas.set(recebe, (cotas.get(recebe) ?? 0) + total - soma);

    return cotas;
}
