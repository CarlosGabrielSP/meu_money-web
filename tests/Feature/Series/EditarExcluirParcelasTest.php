<?php

use App\Enums\TipoCategoria;
use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->user = User::factory()->create();
    $this->carteira = Carteira::factory()->for($this->user)->padrao()->create();
    $this->categoria = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Ambos]);

    $this->actingAs($this->user)->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, [
        'valor' => '100,00',
        'data' => '2026-12-10',
        'modo_valor' => 'parcela',
    ]));
    $this->serie = $this->user->parcelamentos()->sole();
});

function parcela(Parcelamento $serie, int $numero): Lancamento
{
    return $serie->lancamentos()->where('numero_parcela', $numero)->sole();
}

function executar(Parcelamento $serie, int $numero): void
{
    parcela($serie, $numero)->cotas()->update(['executado' => true, 'executado_em' => now()]);
}

/**
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function edicaoParcela(Lancamento $parcela, Carteira $carteira, Categoria $categoria, string $escopo, array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => $parcela->descricao,
        'valor' => '100,00',
        'data' => $parcela->data->toDateString(),
        'categoria_id' => $categoria->id,
        'carteira_id' => $carteira->id,
        'executado' => '0',
        'escopo' => $escopo,
        ...$dados,
    ];
}

it('CA-053.1 changes only the open installment with scope "esta"', function () {
    $terceira = parcela($this->serie, 3);

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $terceira), edicaoParcela($terceira, $this->carteira, $this->categoria, 'esta', [
            'valor' => '150,00',
            'data' => '2027-02-15',
            'observacao' => 'Adiada',
            'executado' => '1',
        ]))
        ->assertSessionHasNoErrors();

    expect($terceira->fresh())->valor->toBe(15000)->observacao->toBe('Adiada')
        ->and($terceira->fresh()->data->toDateString())->toBe('2027-02-15')
        ->and($terceira->cotas()->sole()->executado)->toBeTrue()
        ->and(parcela($this->serie, 4)->valor)->toBe(10000)
        ->and($this->serie->fresh()->valor_parcela)->toBe(10000);
});

it('CA-053.2 changes pending installments from the open one and the model', function () {
    executar($this->serie, 6);
    $quarta = parcela($this->serie, 4);

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $quarta), edicaoParcela($quarta, $this->carteira, $this->categoria, 'proximas', ['valor' => '120,00']))
        ->assertSessionHasNoErrors();

    expect($this->serie->lancamentos()->orderBy('numero_parcela')->pluck('valor')->all())
        ->toBe([10000, 10000, 10000, 12000, 12000, 10000, 12000, 12000, 12000, 12000])
        ->and(parcela($this->serie, 7)->cotas()->sole()->valor)->toBe(12000)
        ->and($this->serie->fresh()->valor_parcela)->toBe(12000);
});

it('CA-053.3 changes every pending installment with scope "todas"', function () {
    executar($this->serie, 1);
    $quinta = parcela($this->serie, 5);
    $outraCarteira = Carteira::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $quinta), edicaoParcela($quinta, $outraCarteira, $this->categoria, 'todas', ['descricao' => 'Televisão']))
        ->assertSessionHasNoErrors();

    expect($this->serie->lancamentos()->orderBy('numero_parcela')->pluck('descricao')->all())
        ->toBe([$quinta->descricao, ...array_fill(0, 9, 'Televisão')])
        ->and(parcela($this->serie, 2)->cotas()->sole()->carteira_id)->toBe($outraCarteira->id)
        ->and(parcela($this->serie, 1)->cotas()->sole()->carteira_id)->toBe($this->carteira->id)
        ->and($this->serie->fresh())->descricao->toBe('Televisão')->carteira_id->toBe($outraCarteira->id);
});

it('CA-053.4 moves pending installments to the new due day', function () {
    $primeira = parcela($this->serie, 1);

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $primeira), edicaoParcela($primeira, $this->carteira, $this->categoria, 'todas', ['dia_vencimento' => 31]))
        ->assertSessionHasNoErrors();

    expect($this->serie->lancamentos()->orderBy('numero_parcela')->limit(4)->get()->map(fn ($parcela) => $parcela->data->toDateString())->all())
        ->toBe(['2026-12-31', '2027-01-31', '2027-02-28', '2027-03-31'])
        ->and($this->serie->fresh()->dia_vencimento)->toBe(31);
});

it('CA-053.5 rejects a type change of a series', function () {
    $quarta = parcela($this->serie, 4);

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $quarta), edicaoParcela($quarta, $this->carteira, $this->categoria, 'todas', ['tipo' => 'receita']))
        ->assertSessionHasErrors('tipo');

    expect($this->serie->fresh()->tipo)->toBe(TipoLancamento::Despesa);
});

it('CA-053.6 CA-053.7 keeps notes of other installments and changes the note of the open one only', function () {
    parcela($this->serie, 6)->update(['observacao' => 'Nota da 6']);
    $quarta = parcela($this->serie, 4);

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $quarta), edicaoParcela($quarta, $this->carteira, $this->categoria, 'proximas', [
            'descricao' => 'Televisão',
            'observacao' => 'Nota da 4',
        ]))
        ->assertSessionHasNoErrors();

    expect(parcela($this->serie, 4))->descricao->toBe('Televisão')->observacao->toBe('Nota da 4')
        ->and(parcela($this->serie, 6))->descricao->toBe('Televisão')->observacao->toBe('Nota da 6')
        ->and(parcela($this->serie, 3)->descricao)->not->toBe('Televisão');
});

it('CA-054.1 deletes only the open installment and keeps numbering', function () {
    $this->actingAs($this->user)
        ->delete(route('lancamentos.destroy', parcela($this->serie, 3)), ['escopo' => 'esta'])
        ->assertSessionHasNoErrors();

    expect($this->serie->lancamentos()->orderBy('numero_parcela')->pluck('numero_parcela')->all())->toBe([1, 2, 4, 5, 6, 7, 8, 9, 10]);
});

it('CA-054.2 deletes pending installments from the open one and keeps executed ones', function () {
    executar($this->serie, 5);

    $this->actingAs($this->user)
        ->delete(route('lancamentos.destroy', parcela($this->serie, 4)), ['escopo' => 'proximas'])
        ->assertSessionHasNoErrors();

    expect($this->serie->lancamentos()->orderBy('numero_parcela')->pluck('numero_parcela')->all())->toBe([1, 2, 3, 5]);
});

it('CA-054.3 ends a recurring series on the installment date', function () {
    $this->actingAs($this->user)->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, [
        'data' => '2026-12-05', 'repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null,
    ]));
    $recorrente = $this->user->parcelamentos()->whereNull('total_parcelas')->sole();
    $quarta = parcela($recorrente, 4);

    $this->actingAs($this->user)->delete(route('lancamentos.destroy', $quarta), ['escopo' => 'proximas'])->assertSessionHasNoErrors();

    expect($recorrente->fresh()->encerrado_em->toDateString())->toBe('2027-03-05')
        ->and($recorrente->lancamentos()->max('numero_parcela'))->toBe(3);
});

it('CA-054.4 deletes the series when no installment was executed', function () {
    $this->actingAs($this->user)
        ->delete(route('lancamentos.destroy', parcela($this->serie, 2)), ['escopo' => 'todas'])
        ->assertSessionHasNoErrors();

    expect($this->serie->fresh())->toBeNull()
        ->and(Lancamento::query()->count())->toBe(0);
});

it('CA-054.5 keeps executed installments and ends a recurring series on the last executed one', function () {
    executar($this->serie, 2);

    $this->actingAs($this->user)->delete(route('lancamentos.destroy', parcela($this->serie, 5)), ['escopo' => 'todas'])->assertSessionHasNoErrors();

    expect($this->serie->fresh())->not->toBeNull()
        ->and($this->serie->lancamentos()->pluck('numero_parcela')->all())->toBe([2]);

    $this->actingAs($this->user)->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, [
        'data' => '2026-08-05', 'repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null,
    ]));
    $recorrente = $this->user->parcelamentos()->whereNull('total_parcelas')->sole();

    $this->actingAs($this->user)->delete(route('lancamentos.destroy', parcela($recorrente, 6)), ['escopo' => 'todas'])->assertSessionHasNoErrors();

    expect($recorrente->fresh()->encerrado_em->toDateString())->toBe('2026-11-05')
        ->and($recorrente->lancamentos()->count())->toBe(4);
});

it('CA-055.1 ends a recurring series removing later pending installments', function () {
    $this->actingAs($this->user)->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, [
        'data' => '2026-12-05', 'repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null,
    ]));
    $recorrente = $this->user->parcelamentos()->whereNull('total_parcelas')->sole();
    executar($recorrente, 6);

    $this->actingAs($this->user)
        ->post(route('parcelamentos.encerrar', $recorrente), ['data' => '2027-02-05'])
        ->assertSessionHasNoErrors();

    expect($recorrente->fresh()->encerrado_em->toDateString())->toBe('2027-02-05')
        ->and($recorrente->lancamentos()->orderBy('numero_parcela')->pluck('numero_parcela')->all())->toBe([1, 2, 3, 6]);
});

it('CA-056.1 adds installments at the end with the model amount', function () {
    $this->actingAs($this->user)
        ->patch(route('parcelamentos.total', $this->serie), ['total_parcelas' => 12])
        ->assertSessionHasNoErrors();

    expect(parcela($this->serie, 12))->valor->toBe(10000)
        ->and(parcela($this->serie, 12)->data->toDateString())->toBe('2027-11-10')
        ->and($this->serie->fresh()->total_parcelas)->toBe(12);
});

it('CA-056.2 removes pending installments from the end', function () {
    foreach ([1, 2, 3] as $numero) {
        executar($this->serie, $numero);
    }

    $this->actingAs($this->user)
        ->patch(route('parcelamentos.total', $this->serie), ['total_parcelas' => 6])
        ->assertSessionHasNoErrors();

    expect($this->serie->lancamentos()->max('numero_parcela'))->toBe(6)
        ->and($this->serie->fresh()->total_parcelas)->toBe(6);
});

it('CA-056.3 rejects a total below an executed installment', function () {
    executar($this->serie, 5);

    $this->actingAs($this->user)
        ->patch(route('parcelamentos.total', $this->serie), ['total_parcelas' => 4])
        ->assertSessionHasErrors('total_parcelas');

    expect($this->serie->lancamentos()->count())->toBe(10);
});

it('forbids changing a series of another user', function () {
    $outro = User::factory()->create();

    $this->actingAs($outro)->patch(route('parcelamentos.total', $this->serie), ['total_parcelas' => 12])->assertForbidden();
    $this->actingAs($outro)->post(route('parcelamentos.encerrar', $this->serie), ['data' => '2027-01-01'])->assertForbidden();
});

it('CA-054.4 removes the series when "esta e próximas" leaves no installment', function () {
    $this->actingAs($this->user)
        ->delete(route('lancamentos.destroy', parcela($this->serie, 1)), ['escopo' => 'proximas'])
        ->assertSessionHasNoErrors();

    expect($this->serie->fresh())->toBeNull();
});

it('RN-086 blocks deleting an installment whose participant share is executed', function () {
    $terceira = parcela($this->serie, 3);
    Cota::factory()->for($terceira)->executado()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($this->user)
        ->delete(route('lancamentos.destroy', $terceira), ['escopo' => 'esta'])
        ->assertSessionHasErrors('lancamento');

    expect($terceira->fresh())->not->toBeNull();
});

it('CA-055.1 rejects ending an ended series or before its first installment', function () {
    $this->actingAs($this->user)->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, [
        'data' => '2026-12-05', 'repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null,
    ]));
    $recorrente = $this->user->parcelamentos()->whereNull('total_parcelas')->sole();

    $this->actingAs($this->user)
        ->post(route('parcelamentos.encerrar', $recorrente), ['data' => '2026-11-30'])
        ->assertSessionHasErrors('data');

    $this->actingAs($this->user)->post(route('parcelamentos.encerrar', $recorrente), ['data' => '2027-02-05'])->assertSessionHasNoErrors();

    $this->actingAs($this->user)
        ->post(route('parcelamentos.encerrar', $recorrente), ['data' => '2027-12-20'])
        ->assertSessionHasErrors('data');

    expect($recorrente->fresh()->encerrado_em->toDateString())->toBe('2027-02-05');
});
