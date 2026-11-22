<?php

namespace App\Http\Controllers;

use App\Actions\Inicio\CalcularSaldos;
use App\Enums\TipoCarteira;
use App\Models\Carteira;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SimulacaoController extends Controller
{
    /**
     * Returns the current month's balances of an own or actively delegated common wallet (RN-341).
     * Simulations run in the browser and this route only reads the data (RN-342).
     */
    public function __invoke(Request $request, CalcularSaldos $calcularSaldos): Response
    {
        /** @var User $user */
        $user = $request->user();

        $carteiras = Carteira::query()
            ->where('tipo', TipoCarteira::Comum)
            ->where(fn (Builder $consulta) => $consulta
                ->where('user_id', $user->id)
                ->orWhereIn('id', $user->carteirasDelegadas()->select('carteiras.id')))
            ->with('user:id,name')
            ->orderBy('nome')
            ->orderBy('id')
            ->get(['id', 'user_id', 'nome', 'tipo', 'padrao']);

        $informada = $request->query('carteira');
        $proprias = $carteiras->where('user_id', $user->id);

        if (is_string($informada) && ctype_digit($informada)) {
            $carteira = $carteiras->find((int) $informada);
            abort_if($carteira === null, 404);
        } else {
            $carteira = $proprias->find($user->ultima_carteira_id)
                ?? $proprias->firstWhere('padrao', true)
                ?? $carteiras->first();
        }

        $saldos = $carteira === null
            ? null
            : $calcularSaldos->handle($carteira->user, $carteira, CarbonImmutable::today()->startOfMonth())['acumulado'];

        return Inertia::render('Simulacoes', [
            'carteiraId' => $carteira?->id,
            'carteiras' => $carteiras->map(fn (Carteira $opcao): array => [
                'id' => $opcao->id,
                'nome' => $opcao->user_id === $user->id ? $opcao->nome : "{$opcao->nome} · de {$opcao->user->name}",
            ]),
            'saldos' => $saldos,
        ]);
    }
}
