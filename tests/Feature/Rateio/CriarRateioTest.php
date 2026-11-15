<?php

use App\Enums\ModoRateio;
use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Lancamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->a = User::factory()->create(['name' => 'Ana']);
    $this->b = User::factory()->create(['name' => 'Bruno']);
    $this->c = User::factory()->create(['name' => 'Carla']);
    $this->carteiraA = Carteira::factory()->for($this->a)->padrao()->create();
    $this->outraA = Carteira::factory()->for($this->a)->create();
    $this->carteiraB = Carteira::factory()->for($this->b)->padrao()->create();
    $this->carteiraC = Carteira::factory()->for($this->c)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->c->id, 'convidado_id' => $this->a->id]);
    $this->mercado = Categoria::query()->globais()->where('nome', 'Mercado')->sole();
});

it('CA-070.1 splits in equal parts with the participant share in their default wallet', function () {
    $this->actingAs($this->a)
        ->post(route('lancamentos.store'), dadosRateio($this->outraA, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]]))
        ->assertSessionHasNoErrors();

    $lancamento = $this->a->lancamentos()->sole();

    expect($lancamento->modo_rateio)->toBe(ModoRateio::Iguais)
        ->and(valoresDasCotas($lancamento))->toBe([$this->a->id => 5000, $this->b->id => 5000])
        ->and($lancamento->cotas()->where('user_id', $this->a->id)->sole()->carteira_id)->toBe($this->outraA->id)
        ->and($lancamento->cotas()->where('user_id', $this->b->id)->sole()->carteira_id)->toBe($this->carteiraB->id);
});

it('CA-070.2 gives the remaining cents to the author', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', [
        ['user_id' => $this->a->id], ['user_id' => $this->b->id], ['user_id' => $this->c->id],
    ]))->assertSessionHasNoErrors();

    expect(valoresDasCotas($this->a->lancamentos()->sole()))->toBe([$this->a->id => 3334, $this->b->id => 3333, $this->c->id => 3333]);
});

it('CA-070.3 CA-070.4 lets the author have no share and gives cents to the first participant', function (string $valor, array $esperado) {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', [
        ['user_id' => $this->b->id], ['user_id' => $this->c->id],
    ], ['valor' => $valor]))->assertSessionHasNoErrors();

    $lancamento = $this->a->lancamentos()->sole();

    expect(array_values(valoresDasCotas($lancamento)))->toBe($esperado)
        ->and($lancamento->cotas()->where('user_id', $this->a->id)->exists())->toBeFalse();
})->with([
    'exato' => ['100,00', [5000, 5000]],
    'sobra' => ['100,01', [5001, 5000]],
]);

it('CA-070.5 splits by percentage and rejects a sum other than 100%', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'percentual', [
        ['user_id' => $this->a->id, 'percentual' => '60'], ['user_id' => $this->b->id, 'percentual' => '40'],
    ]))->assertSessionHasNoErrors();

    expect(valoresDasCotas($this->a->lancamentos()->sole()))->toBe([$this->a->id => 6000, $this->b->id => 4000]);

    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'percentual', [
        ['user_id' => $this->a->id, 'percentual' => '60'], ['user_id' => $this->b->id, 'percentual' => '30'],
    ]))->assertSessionHasErrors('rateio');
});

it('CA-070.6 rounds percentages down and gives the cents to the author', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'percentual', [
        ['user_id' => $this->a->id, 'percentual' => '33.33'],
        ['user_id' => $this->b->id, 'percentual' => '33.33'],
        ['user_id' => $this->c->id, 'percentual' => '33.34'],
    ], ['valor' => '10,00']))->assertSessionHasNoErrors();

    expect(valoresDasCotas($this->a->lancamentos()->sole()))->toBe([$this->a->id => 334, $this->b->id => 333, $this->c->id => 333]);
});

it('CA-070.7 uses fixed amounts and rejects a sum other than the total', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'fixo', [
        ['user_id' => $this->a->id, 'valor' => '30,00'], ['user_id' => $this->b->id, 'valor' => '70,00'],
    ]))->assertSessionHasNoErrors();

    expect(valoresDasCotas($this->a->lancamentos()->sole()))->toBe([$this->a->id => 3000, $this->b->id => 7000]);

    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'fixo', [
        ['user_id' => $this->a->id, 'valor' => '30,00'], ['user_id' => $this->b->id, 'valor' => '60,00'],
    ]))->assertSessionHasErrors('rateio');
});

it('CA-070.8 rejects a split without any contact', function () {
    $this->actingAs($this->a)
        ->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', [['user_id' => $this->a->id]]))
        ->assertSessionHasErrors('rateio');

    expect(Lancamento::query()->count())->toBe(0);
});

it('CA-070.9 CA-063.3 rejects a user who is not an active contact', function (string $quem) {
    $estranho = User::factory()->create();
    Carteira::factory()->for($estranho)->padrao()->create();
    $participante = match ($quem) {
        'estranho' => $estranho,
        'desativado' => tap($this->b, fn (User $b) => $b->forceFill(['desativado_em' => now()])->save()),
        'removido' => tap($this->b, fn () => Contato::query()->delete()),
    };

    $this->actingAs($this->a)
        ->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $participante->id]]))
        ->assertSessionHasErrors('rateio');
})->with(['estranho', 'desativado', 'removido']);

it('CA-070.10 gives participants the global category or "Outros"', function () {
    $pessoal = Categoria::factory()->for($this->a)->create(['tipo' => TipoCategoria::Despesa]);

    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]]));
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $pessoal, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['descricao' => 'Pessoal']));

    $global = $this->a->lancamentos()->where('descricao', 'Jantar')->sole();
    $comPessoal = $this->a->lancamentos()->where('descricao', 'Pessoal')->sole();

    expect($global->cotas()->where('user_id', $this->b->id)->sole()->categoria_id)->toBe($this->mercado->id)
        ->and($comPessoal->cotas()->where('user_id', $this->b->id)->sole()->categoria_id)->toBe(Categoria::outros()->id)
        ->and($comPessoal->cotas()->where('user_id', $this->a->id)->sole()->categoria_id)->toBe($pessoal->id);
});

it('CA-070.11 marks only the author share as seen', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', [
        ['user_id' => $this->a->id], ['user_id' => $this->b->id], ['user_id' => $this->c->id],
    ]));

    $cotas = $this->a->lancamentos()->sole()->cotas()->get()->keyBy('user_id');

    expect($cotas[$this->a->id]->vista_em)->not->toBeNull()
        ->and($cotas[$this->b->id]->vista_em)->toBeNull()
        ->and($cotas[$this->c->id]->vista_em)->toBeNull();
});

it('CA-070.12 creates every share of a past entry as executed and each participant can unmark their own', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', [
        ['user_id' => $this->a->id], ['user_id' => $this->b->id],
    ], ['data' => '2026-11-10']));

    $lancamento = $this->a->lancamentos()->sole();
    $cotaB = $lancamento->cotas()->where('user_id', $this->b->id)->sole();

    expect($lancamento->cotas()->pluck('executado')->all())->toBe([true, true]);

    $this->actingAs($this->b)->patch(route('cotas.executado', $cotaB), ['executado' => false])->assertSessionHasNoErrors();

    expect($cotaB->fresh()->executado)->toBeFalse()
        ->and($lancamento->cotas()->where('user_id', $this->a->id)->sole()->executado)->toBeTrue();
});

it('CA-070.13 rejects a share of zero', function (string $modo, array $participantes, string $valor) {
    $this->actingAs($this->a)
        ->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, $modo, $participantes, ['valor' => $valor]))
        ->assertSessionHasErrors();

    expect(Lancamento::query()->count())->toBe(0);
})->with([
    'fixo zerado' => fn () => ['fixo', [['user_id' => $this->a->id, 'valor' => '100,00'], ['user_id' => $this->b->id, 'valor' => '0,00']], '100,00'],
    'iguais abaixo de um centavo' => fn () => ['iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id], ['user_id' => $this->c->id]], '0,02'],
]);

it('CA-070.14 applies fixed amounts per installment with the remaining cents on the author', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), [
        ...dadosRateio($this->carteiraA, $this->mercado, 'fixo', [
            ['user_id' => $this->a->id, 'valor' => '16,66'], ['user_id' => $this->b->id, 'valor' => '16,67'],
        ], ['data' => '2026-12-05']),
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 3,
        'modo_valor' => 'total',
    ])->assertSessionHasNoErrors();

    $serie = $this->a->parcelamentos()->sole();
    $parcelas = $serie->lancamentos()->orderBy('numero_parcela')->get();

    expect($serie->modo_rateio)->toBe(ModoRateio::Fixo)
        ->and($serie->cotas()->pluck('valor', 'user_id')->all())->toBe([$this->a->id => 1666, $this->b->id => 1667])
        ->and(valoresDasCotas($parcelas[0]))->toBe([$this->a->id => 1667, $this->b->id => 1667])
        ->and(valoresDasCotas($parcelas[1]))->toBe([$this->a->id => 1666, $this->b->id => 1667])
        ->and(valoresDasCotas($parcelas[2]))->toBe([$this->a->id => 1666, $this->b->id => 1667])
        ->and($parcelas[2]->modo_rateio)->toBe(ModoRateio::Fixo)
        ->and($parcelas[2]->cotas()->where('user_id', $this->b->id)->sole())
        ->carteira_id->toBe($this->carteiraB->id)
        ->vista_em->toBeNull();
});
