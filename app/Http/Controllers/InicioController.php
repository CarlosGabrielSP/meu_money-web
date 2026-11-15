<?php

namespace App\Http\Controllers;

use App\Actions\Inicio\CalcularSaldos;
use App\Actions\Inicio\ListarLancamentosDoMes;
use App\Http\Requests\InicioRequest;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class InicioController extends Controller
{
    /**
     * Main screen: one wallet, one month, balances and the month list (RF-080 to RF-085).
     */
    public function __invoke(InicioRequest $request, CalcularSaldos $calcularSaldos, ListarLancamentosDoMes $listarLancamentos): Response
    {
        /** @var User $user */
        $user = $request->user();
        $carteira = $request->carteira();
        $mes = $request->mes();
        $filtros = $request->filtros();
        $hoje = CarbonImmutable::today();

        if ($request->carteiraInformada() === $carteira->id && $user->ultima_carteira_id !== $carteira->id) {
            $user->forceFill(['ultima_carteira_id' => $carteira->id])->save();
        }

        return Inertia::render('Inicio', [
            'carteira' => $this->carteira($carteira),
            'carteiras' => $user->carteiras()
                ->orderByDesc('padrao')
                ->orderBy('nome')
                ->get(['id', 'nome', 'cor', 'icone'])
                ->map($this->carteira(...)),
            'categorias' => Categoria::query()
                ->visiveisPara($user)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo'])
                ->map(fn (Categoria $categoria): array => [
                    'id' => $categoria->id,
                    'nome' => $categoria->nome,
                    'tipo' => $categoria->tipo->value,
                ]),
            'mes' => $mes->format('Y-m'),
            'hoje' => $hoje->toDateString(),
            'filtros' => $filtros,
            'saldos' => $calcularSaldos->handle($user, $carteira, $mes),
            'lancamentos' => $listarLancamentos->handle($user, $carteira, $mes, $filtros, $hoje),
        ]);
    }

    /**
     * @return array{id: int, nome: string, cor: string, icone: string}
     */
    private function carteira(Carteira $carteira): array
    {
        return [
            'id' => $carteira->id,
            'nome' => $carteira->nome,
            'cor' => $carteira->cor->value,
            'icone' => $carteira->icone->value,
        ];
    }
}
