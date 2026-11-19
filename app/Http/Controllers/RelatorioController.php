<?php

namespace App\Http\Controllers;

use App\Actions\Relatorio\Compromissos;
use App\Actions\Relatorio\DespesasPorCategoria;
use App\Actions\Relatorio\DespesasPorTipo;
use App\Actions\Relatorio\EvolucaoMensal;
use App\Actions\Relatorio\Previsao;
use App\Actions\Relatorio\RateioDoPeriodo;
use App\Actions\Relatorio\ResumoDoPeriodo;
use App\Actions\Relatorio\SaldoPorCarteira;
use App\Http\Requests\RelatorioRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class RelatorioController extends Controller
{
    /**
     * Report of one wallet in one period (RF-140 to RF-148). The simple mode gets only the summary,
     * the expenses by category and the forecast, for the last used or the default wallet (RN-188).
     * Cards are listed apart from the common wallets, with their debt (RN-227).
     */
    public function __invoke(
        RelatorioRequest $request,
        ResumoDoPeriodo $resumo,
        EvolucaoMensal $evolucaoMensal,
        DespesasPorCategoria $despesasPorCategoria,
        DespesasPorTipo $despesasPorTipo,
        Previsao $previsao,
        Compromissos $compromissos,
        SaldoPorCarteira $saldoPorCarteira,
        RateioDoPeriodo $rateio,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $carteira = $request->carteira();
        $periodo = $request->periodo();
        $hoje = CarbonImmutable::today();
        $inicio = $periodo->inicio($hoje);
        $fim = $periodo->fim($hoje);
        $inicioAnterior = $periodo->inicioAnterior($hoje);

        if ($request->carteiraInformada() === $carteira->id && $user->ultima_carteira_id !== $carteira->id) {
            $user->forceFill(['ultima_carteira_id' => $carteira->id])->save();
        }

        $blocosDoModoSimples = [
            'resumo' => $resumo->handle($user, $carteira, $inicioAnterior, $inicio, $fim),
            'despesasPorCategoria' => $despesasPorCategoria->handle($user, $carteira, $inicioAnterior, $inicio, $fim),
            'previsao' => $previsao->handle($user, $carteira, $hoje),
        ];

        return Inertia::render('Relatorio', [
            'carteira' => [
                'id' => $carteira->id,
                'nome' => $carteira->nome,
                'tipo' => $carteira->tipo->value,
                'cor' => $carteira->cor->value,
                'icone' => $carteira->icone->value,
            ],
            'periodo' => $periodo->value,
            'visao' => $request->visao(),
            'intervalo' => ['inicio' => $inicio->format('Y-m'), 'fim' => $fim->subMonth()->format('Y-m')],
            'hoje' => $hoje->toDateString(),
            ...$blocosDoModoSimples,
            ...($user->modo_simples ? [] : [
                ...$saldoPorCarteira->handle($user, $hoje),
                'evolucao' => $evolucaoMensal->handle($user, $carteira, $periodo->meses($hoje)),
                'despesasPorTipo' => $despesasPorTipo->handle($user, $carteira, $inicio, $fim),
                'compromissos' => $compromissos->handle($user, $carteira, $hoje),
                'rateio' => $rateio->handle($user, $carteira, $inicio, $fim),
            ]),
        ]);
    }
}
