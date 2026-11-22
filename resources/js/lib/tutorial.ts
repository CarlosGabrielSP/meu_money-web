/**
 * One step of the tour (RN-164). `alvos` are `data-tutorial` values in order of preference: the
 * first one visible on the screen is highlighted, and a step without any is skipped.
 */
export type PassoTutorial = {
    alvos: string[];
    texto: string;
};

const textoMes =
    'Use as setas para mudar de mês ou escolha o mês e o ano ao lado.';

const passoAtalhos: PassoTutorial = {
    alvos: ['atalhos-lancamento'],
    texto: 'Toque numa categoria frequente para lançar à vista. Informe o valor e, se quiser, edite a descrição antes de salvar.',
};

/**
 * Steps of the full mode (note 07). The list steps fall back to the empty list area (DEC-069).
 */
export const passosModoCompleto: PassoTutorial[] = [
    {
        alvos: ['carteira'],
        texto: 'Cada carteira tem saldo próprio; toque num cartão para trocar.',
    },
    { alvos: ['mes'], texto: textoMes },
    {
        alvos: ['saldos'],
        texto: 'Saldo atual conta o que já foi pago ou recebido, e o trilho mostra o estimado para o fim do mês.',
    },
    {
        alvos: ['adicionar'],
        texto: 'Adicione lançamentos; em "Repetir" crie parcelas ou recorrências e em "Dividir" divida com contatos.',
    },
    passoAtalhos,
    {
        alvos: ['executado', 'lista-vazia'],
        texto: 'Marque o lançamento quando ele for pago ou recebido.',
    },
    {
        alvos: ['filtros'],
        texto: 'Filtre a lista por tipo, categoria, situação ou divisão.',
    },
    {
        alvos: ['selecionar', 'lista-vazia'],
        texto: 'Use "Selecionar" para juntar lançamentos num grupo, como uma fatura.',
    },
    {
        alvos: ['menu'],
        texto: 'No menu ficam Relatório (gráficos e previsão), Carteiras, Categorias, Contatos (para dividir) e Recebidas (cotas que dividiram com você).',
    },
    {
        alvos: ['configuracoes', 'mais'],
        texto: 'Em Configurações você muda a aparência, liga o modo simples e revê este tutorial.',
    },
];

/**
 * Steps of the simple mode, fewer than the full mode (RN-166, note 07).
 */
export const passosModoSimples: PassoTutorial[] = [
    {
        alvos: ['saldos'],
        texto: 'Saldo atual é o que já foi pago e recebido; abaixo, os totais do mês.',
    },
    { alvos: ['mes'], texto: textoMes },
    {
        alvos: ['adicionar'],
        texto: 'Use este botão para adicionar uma receita ou uma despesa.',
    },
    passoAtalhos,
    {
        alvos: ['executado', 'lista-vazia'],
        texto: 'Toque em "Pago" ou "Recebido" quando a conta for paga ou o dinheiro entrar.',
    },
    {
        alvos: ['recebidas'],
        texto: 'Em Recebidas ficam as despesas que outras pessoas dividiram com você.',
    },
    {
        alvos: ['configuracoes', 'mais'],
        texto: 'Em Configurações você desliga o modo simples e revê este tutorial.',
    },
];

/**
 * Whether the element is on the screen: elements hidden by `display: none` (bottom bar on desktop)
 * or `visibility: hidden` ("Pago" of an item without a share) do not count, nor the mobile menu
 * sheet ("Mais"), which the tour closes: on mobile the tour points to the bottom bar.
 */
function naTela(elemento: HTMLElement): boolean {
    return (
        elemento.getClientRects().length > 0 &&
        getComputedStyle(elemento).visibility !== 'hidden' &&
        elemento.closest('[data-mobile="true"]') === null
    );
}

/**
 * First element marked with `data-tutorial="alvo"` that is on the screen.
 */
export function elementoVisivel(alvo: string): HTMLElement | null {
    return (
        Array.from(
            document.querySelectorAll<HTMLElement>(`[data-tutorial="${alvo}"]`),
        ).find(naTela) ?? null
    );
}

/**
 * Brings the element into view when it is off the screen or under the sticky header or the fixed
 * bottom bar (`data-tutorial-fixo`). Elements inside those bars are always on the screen.
 */
export function rolarAte(elemento: HTMLElement): void {
    if (elemento.closest('[data-tutorial-fixo]') !== null) {
        return;
    }

    const meio = window.innerHeight / 2;
    let topo = 0;
    let base = window.innerHeight;

    for (const fixo of document.querySelectorAll<HTMLElement>(
        '[data-tutorial-fixo]',
    )) {
        if (!naTela(fixo)) {
            continue;
        }

        const caixa = fixo.getBoundingClientRect();

        if (caixa.top < meio) {
            topo = Math.max(topo, caixa.bottom);
        } else {
            base = Math.min(base, caixa.top);
        }
    }

    const caixa = elemento.getBoundingClientRect();

    if (caixa.top >= topo && caixa.bottom <= base) {
        return;
    }

    elemento.style.scrollMarginTop = `${topo + 16}px`;
    elemento.style.scrollMarginBottom = `${window.innerHeight - base + 16}px`;
    elemento.scrollIntoView({ block: 'nearest' });
    elemento.style.scrollMarginTop = '';
    elemento.style.scrollMarginBottom = '';
}
