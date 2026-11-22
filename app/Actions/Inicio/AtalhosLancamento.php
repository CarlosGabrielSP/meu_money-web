<?php

namespace App\Actions\Inicio;

use App\Models\Categoria;
use App\Models\User;

class AtalhosLancamento
{
    /**
     * @return list<array{id: int, nome: string, icone: string, tipo: string}>
     */
    public function handle(User $user): array
    {
        $frequencias = Categoria::query()
            ->whereIn('categorias.id', Categoria::query()->visiveisPara($user)->select('id'))
            ->join('cotas', 'cotas.categoria_id', '=', 'categorias.id')
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('lancamentos.user_id', $user->id)
            ->whereNull('lancamentos.parcelamento_id')
            ->where(fn ($query) => $query->where('categorias.tipo', 'ambos')->orWhereColumn('categorias.tipo', 'lancamentos.tipo'))
            ->select('categorias.id', 'categorias.nome', 'categorias.icone')
            ->selectRaw('count(*) as frequencia, max(lancamentos.id) as ultimo_id')
            ->groupBy('categorias.id', 'categorias.nome', 'categorias.icone')
            ->orderByDesc('frequencia')
            ->orderBy('categorias.id')
            ->limit(5)
            ->toBase();

        return array_values(Categoria::query()
            ->fromSub($frequencias, 'atalhos')
            ->join('lancamentos', 'lancamentos.id', '=', 'atalhos.ultimo_id')
            ->orderByDesc('atalhos.frequencia')
            ->orderBy('atalhos.id')
            ->get(['atalhos.id', 'atalhos.nome', 'atalhos.icone', 'lancamentos.tipo'])
            ->map(fn (Categoria $atalho): array => [
                'id' => $atalho->id,
                'nome' => $atalho->nome,
                'icone' => $atalho->icone->value,
                'tipo' => $atalho->tipo->value,
            ])
            ->all());
    }
}
