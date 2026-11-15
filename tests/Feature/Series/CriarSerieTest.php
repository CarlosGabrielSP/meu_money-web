<?php

use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->user = User::factory()->create();
    $this->carteira = Carteira::factory()->for($this->user)->padrao()->create();
    $this->categoria = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Ambos]);
});

it('CA-050.1 creates an installment series and generates every installment', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria))
        ->assertSessionHasNoErrors();

    $serie = $this->user->parcelamentos()->sole();
    $parcelas = parcelasDaSerie($serie);

    expect($serie)->total_parcelas->toBe(10)->valor_parcela->toBe(10000)->dia_vencimento->toBe(5)
        ->and($parcelas)->toHaveCount(10)
        ->and(array_column($parcelas, 'numero'))->toBe(range(1, 10))
        ->and(array_unique(array_column($parcelas, 'valor')))->toBe([10000])
        ->and($parcelas[0]['data'])->toBe('2026-12-05')
        ->and($parcelas[9]['data'])->toBe('2027-09-05')
        ->and($serie->gerado_ate->toDateString())->toBe('2027-09-05');
});

it('CA-050.2 puts the remaining cents on the first installment', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, ['valor' => '100,00', 'total_parcelas' => 3]))
        ->assertSessionHasNoErrors();

    expect(array_column(parcelasDaSerie($this->user->parcelamentos()->sole()), 'valor'))->toBe([3334, 3333, 3333]);
});

it('CA-050.3 multiplies the installment amount', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, ['valor' => '50,00', 'total_parcelas' => 4, 'modo_valor' => 'parcela']))
        ->assertSessionHasNoErrors();

    $parcelas = parcelasDaSerie($this->user->parcelamentos()->sole());

    expect(array_column($parcelas, 'valor'))->toBe([5000, 5000, 5000, 5000])
        ->and(array_sum(array_column($parcelas, 'valor')))->toBe(20000);
});

it('CA-050.4 moves a missing day to the last day of the month and back', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, ['data' => '2027-01-31', 'total_parcelas' => 4]))
        ->assertSessionHasNoErrors();

    expect(array_column(parcelasDaSerie($this->user->parcelamentos()->sole()), 'data'))
        ->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30']);
});

it('CA-050.5 spaces installments by the periodicity', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, ['periodicidade' => 'trimestral', 'total_parcelas' => 3]))
        ->assertSessionHasNoErrors();

    expect(array_column(parcelasDaSerie($this->user->parcelamentos()->sole()), 'data'))
        ->toBe(['2026-12-05', '2027-03-05', '2027-06-05']);
});

it('CA-050.6 sets the initial status of each installment by its date', function () {

    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, ['data' => '2026-08-10', 'executado' => '0']))
        ->assertSessionHasNoErrors();

    expect(array_column(parcelasDaSerie($this->user->parcelamentos()->sole()), 'executado'))
        ->toBe([true, true, true, true, false, false, false, false, false, false]);
});

it('CA-050.7 rejects fewer than 2 or more than 360 installments', function (int $total) {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, ['total_parcelas' => $total]))
        ->assertSessionHasErrors('total_parcelas');

    expect($this->user->lancamentos()->count())->toBe(0);
})->with([1, 361]);

it('CA-051.1 CA-051.3 creates a recurring series 12 months ahead', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, [
            'tipo' => 'receita',
            'valor' => '3.000,00',
            'data' => '2026-11-05',
            'repeticao' => 'recorrente',
            'total_parcelas' => null,
            'modo_valor' => null,
        ]))
        ->assertSessionHasNoErrors();

    $serie = $this->user->parcelamentos()->sole();
    $parcelas = parcelasDaSerie($serie);

    expect($serie)->total_parcelas->toBeNull()
        ->and($serie->gerado_ate->toDateString())->toBe('2027-11-05')
        ->and($parcelas)->toHaveCount(13)
        ->and($parcelas[12]['data'])->toBe('2027-11-05')
        ->and(array_column($parcelas, 'executado'))->toBe([true, ...array_fill(0, 12, false)]);
});

it('CA-051.2 rejects a total amount for a recurring series', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, [
            'repeticao' => 'recorrente',
            'total_parcelas' => null,
            'modo_valor' => 'total',
        ]))
        ->assertSessionHasErrors('modo_valor');
});

it('keeps the note and attachment of a new series on the first installment only (RN-019)', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, ['total_parcelas' => 3, 'observacao' => 'Loja X']))
        ->assertSessionHasNoErrors();

    expect($this->user->lancamentos()->orderBy('numero_parcela')->pluck('observacao')->all())->toBe(['Loja X', null, null]);
});

it('CA-050.2 rejects a total smaller than one cent per installment', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->carteira, $this->categoria, ['valor' => '0,05', 'total_parcelas' => 10]))
        ->assertSessionHasErrors('valor');

    expect($this->user->lancamentos()->count())->toBe(0);
});
