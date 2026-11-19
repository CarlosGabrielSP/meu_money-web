import type { CartaoDoFormulario } from '@/types';

/**
 * The due day in the month, or its last day when shorter (RN-052).
 */
function noDia(ano: number, mes: number, dia: number): Date {
    const ultimo = new Date(Date.UTC(ano, mes + 1, 0)).getUTCDate();

    return new Date(Date.UTC(ano, mes, Math.min(dia, ultimo)));
}

function texto(data: Date): string {
    return data.toISOString().slice(0, 10);
}

/**
 * Due date of the invoice whose period contains the date (RN-192), as the server computes it:
 * default dates of the card, or the dates edited one by one; null when the date falls in a
 * closed invoice, before the open one starts (RN-232).
 */
export function vencimentoDaCompra(
    cartao: CartaoDoFormulario,
    data: string,
): string | null {
    if (data < cartao.abertaDesde) {
        return null;
    }

    const [ano, mes] = data.split('-').map(Number);

    for (let indice = -1; indice <= 3; indice++) {
        const referencia = new Date(Date.UTC(ano, mes - 1 + indice, 1));
        const chave = texto(referencia).slice(0, 7);
        const editada = cartao.editadas.find(
            (fatura) => fatura.referencia === chave,
        );
        const vencimento =
            editada?.vencimento ??
            texto(
                noDia(
                    referencia.getUTCFullYear(),
                    referencia.getUTCMonth(),
                    cartao.diaVencimento,
                ),
            );
        const fechamento =
            editada?.fechamento ??
            texto(
                new Date(
                    Date.parse(vencimento) -
                        cartao.diasFechamento * 24 * 60 * 60 * 1000,
                ),
            );

        if (fechamento >= data) {
            return vencimento;
        }
    }

    return null;
}
