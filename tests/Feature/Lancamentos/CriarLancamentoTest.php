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
    $this->despesa = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Despesa]);
    $this->receita = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Receita]);
});

function dadosLancamento(Carteira $carteira, Categoria $categoria, array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'Mercado',
        'valor' => '150,00',
        'data' => '2026-11-20',
        'categoria_id' => $categoria->id,
        'carteira_id' => $carteira->id,
        ...$dados,
    ];
}

it('CA-040.1 creates the entry with one share of the author with the full amount', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa, ['observacao' => 'Semana']))
        ->assertSessionHasNoErrors();

    $lancamento = $this->user->lancamentos()->sole();

    expect($lancamento)
        ->descricao->toBe('Mercado')
        ->valor->toBe(15000)
        ->observacao->toBe('Semana')
        ->and($lancamento->cotas()->sole())
        ->user_id->toBe($this->user->id)
        ->carteira_id->toBe($this->carteira->id)
        ->categoria_id->toBe($this->despesa->id)
        ->valor->toBe(15000);
});

it('CA-040.2 sets the initial status by date and type when none is given', function (string $tipo, string $data, bool $executado) {
    $categoria = $tipo === 'receita' ? $this->receita : $this->despesa;

    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $categoria, ['tipo' => $tipo, 'data' => $data]))
        ->assertSessionHasNoErrors();

    $cota = $this->user->lancamentos()->sole()->cotas()->sole();

    expect($cota->executado)->toBe($executado)
        ->and($cota->executado_em !== null)->toBe($executado);
})->with([
    'despesa passada' => ['despesa', '2026-11-13', true],
    'despesa de hoje' => ['despesa', '2026-11-14', false],
    'receita de hoje' => ['receita', '2026-11-14', true],
    'receita futura' => ['receita', '2026-11-15', false],
]);

it('CA-040.3 uses the status chosen in the form', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa, ['data' => '2026-12-01', 'executado' => '1']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa, ['data' => '2026-10-01', 'executado' => '0']))
        ->assertSessionHasNoErrors();

    $futuro = $this->user->lancamentos()->whereDate('data', '2026-12-01')->sole()->cotas()->sole();
    $passado = $this->user->lancamentos()->whereDate('data', '2026-10-01')->sole()->cotas()->sole();

    expect($futuro)->executado->toBeTrue()->executado_em->not->toBeNull()
        ->and($passado)->executado->toBeFalse()->executado_em->toBeNull();
});

it('CA-040.4 CA-085.3 stores an amount typed in pt-BR format as cents', function (string $digitado, int $centavos) {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa, ['valor' => $digitado]))
        ->assertSessionHasNoErrors();

    expect($this->user->lancamentos()->sole()->valor)->toBe($centavos);
})->with([
    'milhar' => ['1.234,56', 123456],
    'um dígito de centavo' => ['10,5', 1050],
    'sem centavos' => ['999.999.999', 99999999900],
]);

it('CA-040.5 rejects an amount that is not positive or above the limit', function (string $valor) {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa, ['valor' => $valor]))
        ->assertSessionHasErrors('valor');
})->with(['zero' => '0,00', 'negativo' => '-5,00', 'acima do limite' => '1.000.000.000,00', 'texto' => 'abc']);

it('CA-040.6 rejects an empty or too long description', function (string $descricao) {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa, ['descricao' => $descricao]))
        ->assertSessionHasErrors('descricao');
})->with(['vazia' => '', '256 caracteres' => str_repeat('a', 256)]);

it('CA-040.7 rejects a category of an incompatible type or of another user', function () {
    $alheia = Categoria::factory()->create();

    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->receita))
        ->assertSessionHasErrors('categoria_id');
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $alheia))
        ->assertSessionHasErrors('categoria_id');

    expect($this->user->lancamentos()->count())->toBe(0);
});

it('CA-040.8 rejects a wallet of another user', function () {
    $alheia = Carteira::factory()->create();

    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($alheia, $this->despesa))
        ->assertSessionHasErrors('carteira_id');
});

it('CA-040.9 rejects a note above 1000 characters', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa, ['observacao' => str_repeat('a', 1001)]))
        ->assertSessionHasErrors('observacao');
});

it('CA-040.10 accepts any valid date', function (string $data) {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa, ['data' => $data]))
        ->assertSessionHasNoErrors();

    expect($this->user->lancamentos()->sole()->data->toDateString())->toBe($data);
})->with(['1990-01-01', '2090-12-31']);

it('CA-085.6 returns to the main screen it came from after saving', function () {
    $origem = route('home', ['carteira' => $this->carteira->id, 'mes' => '2026-12', 'tipo' => 'despesa']);

    $this->actingAs($this->user)
        ->from($origem)
        ->post(route('lancamentos.store'), dadosLancamento($this->carteira, $this->despesa))
        ->assertRedirect($origem);
});
