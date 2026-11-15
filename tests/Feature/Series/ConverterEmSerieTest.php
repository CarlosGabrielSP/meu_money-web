<?php

use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->user = User::factory()->create();
    $this->carteira = Carteira::factory()->for($this->user)->padrao()->create();
    $this->categoria = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Despesa]);
    $this->lancamento = lancamentoComCota($this->user, $this->carteira, $this->categoria, [
        'data' => '2026-11-10', 'valor' => 10000, 'descricao' => 'Curso', 'observacao' => 'Matrícula',
    ], ['executado' => true]);
    $this->lancamento->forceFill(['anexo_caminho' => 'anexos/x.pdf', 'anexo_nome' => 'x.pdf'])->save();
});

/**
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function conversao(Carteira $carteira, Categoria $categoria, array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'Curso',
        'valor' => '100,00',
        'data' => '2026-11-10',
        'categoria_id' => $categoria->id,
        'carteira_id' => $carteira->id,
        'observacao' => 'Matrícula',
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 5,
        'modo_valor' => 'parcela',
        ...$dados,
    ];
}

it('CA-057.1 turns the entry into installment 1 keeping status, note and attachment', function () {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->lancamento), conversao($this->carteira, $this->categoria))
        ->assertSessionHasNoErrors();

    $serie = $this->user->parcelamentos()->sole();

    expect($this->lancamento->fresh())
        ->parcelamento_id->toBe($serie->id)
        ->numero_parcela->toBe(1)
        ->observacao->toBe('Matrícula')
        ->anexo_nome->toBe('x.pdf')
        ->and($this->lancamento->cotas()->sole()->executado)->toBeTrue()
        ->and($serie->data_inicio->toDateString())->toBe('2026-11-10')
        ->and($serie->lancamentos()->orderBy('numero_parcela')->pluck('numero_parcela')->all())->toBe([1, 2, 3, 4, 5])
        ->and(parcelasDaSerie($serie)[4]['data'])->toBe('2027-03-10');
});

it('CA-057.3 rejects converting an installment again', function () {
    $this->actingAs($this->user)->put(route('lancamentos.update', $this->lancamento), conversao($this->carteira, $this->categoria));
    $outra = Parcelamento::query()->sole()->lancamentos()->where('numero_parcela', 2)->sole();

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $outra), conversao($this->carteira, $this->categoria, ['escopo' => 'esta']))
        ->assertSessionHasErrors('repeticao');
});

it('CA-057.4 splits a total with the remaining cents on the converted entry', function () {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->lancamento), conversao($this->carteira, $this->categoria, ['total_parcelas' => 3, 'modo_valor' => 'total']))
        ->assertSessionHasNoErrors();

    expect(array_column(parcelasDaSerie($this->user->parcelamentos()->sole()), 'valor'))->toBe([3334, 3333, 3333])
        ->and($this->lancamento->cotas()->sole()->valor)->toBe(3334);
});

it('CA-057.4 repeats the installment amount', function () {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->lancamento), conversao($this->carteira, $this->categoria, ['total_parcelas' => 3]))
        ->assertSessionHasNoErrors();

    expect(array_column(parcelasDaSerie($this->user->parcelamentos()->sole()), 'valor'))->toBe([10000, 10000, 10000]);
});
