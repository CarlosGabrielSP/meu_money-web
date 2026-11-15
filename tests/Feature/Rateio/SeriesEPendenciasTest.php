<?php

use App\Enums\ModoRateio;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->a = User::factory()->create(['name' => 'Ana']);
    $this->b = User::factory()->create(['name' => 'Bruno']);
    $this->carteiraA = Carteira::factory()->for($this->a)->padrao()->create();
    $this->outraA = Carteira::factory()->for($this->a)->create();
    $this->carteiraB = Carteira::factory()->for($this->b)->padrao()->create();
    $this->outraB = Carteira::factory()->for($this->b)->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);
    $this->mercado = Categoria::query()->globais()->where('nome', 'Mercado')->sole();
    $this->iguaisAB = [['user_id' => $this->a->id], ['user_id' => $this->b->id]];
});

function serieRateada(User $autor, Carteira $carteira, Categoria $categoria, array $participantes, array $dados = []): Parcelamento
{
    test()->actingAs($autor)->post(route('lancamentos.store'), [
        ...dadosRateio($carteira, $categoria, 'iguais', $participantes, ['data' => '2026-12-05', 'valor' => '100,00']),
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 4,
        'modo_valor' => 'parcela',
        ...$dados,
    ])->assertSessionHasNoErrors();

    return $autor->parcelamentos()->latest('id')->firstOrFail();
}

it('CA-052.5 generates recurring installments with the series split', function () {
    $serie = serieRateada($this->a, $this->carteiraA, $this->mercado, $this->iguaisAB, [
        'repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null,
    ]);
    $this->travelTo(CarbonImmutable::parse('2027-01-06 03:00'));

    $this->artisan('series:estender-recorrentes')->assertSuccessful();

    $nova = $serie->lancamentos()->orderByDesc('numero_parcela')->first();

    expect($nova->data->toDateString())->toBe('2028-01-05')
        ->and($nova->modo_rateio)->toBe(ModoRateio::Iguais)
        ->and(valoresDasCotas($nova))->toBe([$this->a->id => 5000, $this->b->id => 5000])
        ->and($nova->cotas()->where('user_id', $this->b->id)->sole())->carteira_id->toBe($this->carteiraB->id)->vista_em->toBeNull();
});

it('CA-057.2 copies the split of a converted entry to the series model', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'fixo', [
        ['user_id' => $this->a->id, 'valor' => '30,00'], ['user_id' => $this->b->id, 'valor' => '70,00'],
    ]))->assertSessionHasNoErrors();
    $lancamento = $this->a->lancamentos()->sole();

    $this->actingAs($this->a)->put(route('lancamentos.update', $lancamento), [
        ...dadosRateio($this->carteiraA, $this->mercado, 'fixo', [
            ['user_id' => $this->a->id, 'valor' => '30,00'], ['user_id' => $this->b->id, 'valor' => '70,00'],
        ]),
        'repeticao' => 'parcelado', 'periodicidade' => 'mensal', 'total_parcelas' => 3, 'modo_valor' => 'parcela',
    ])->assertSessionHasNoErrors();

    $serie = $this->a->parcelamentos()->sole();

    expect($serie->modo_rateio)->toBe(ModoRateio::Fixo)
        ->and($serie->cotas()->pluck('valor', 'user_id')->all())->toBe([$this->a->id => 3000, $this->b->id => 7000])
        ->and($serie->lancamentos()->get()->map(fn (Lancamento $parcela) => valoresDasCotas($parcela))->unique()->values()->all())
        ->toBe([[$this->a->id => 3000, $this->b->id => 7000]]);
});

it('CA-057.5 refuses converting a shared entry when the split of an executed share would change', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', $this->iguaisAB, ['valor' => '300,00']))
        ->assertSessionHasNoErrors();
    $lancamento = $this->a->lancamentos()->sole();
    $lancamento->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);

    $this->actingAs($this->a)->put(route('lancamentos.update', $lancamento), [
        ...dadosRateio($this->carteiraA, $this->mercado, 'iguais', $this->iguaisAB, ['valor' => '300,00']),
        'repeticao' => 'parcelado', 'periodicidade' => 'mensal', 'total_parcelas' => 3, 'modo_valor' => 'total',
    ])->assertSessionHasErrors(['rateio' => 'Bruno já marcou a cota como executada. Valor e divisão não podem mudar.']);

    expect($lancamento->fresh()->parcelamento_id)->toBeNull()
        ->and(valoresDasCotas($lancamento))->toBe([$this->a->id => 15000, $this->b->id => 15000]);
});

it('CA-072.5 recalculates in mass only installments without an executed participant share', function () {
    $serie = serieRateada($this->a, $this->carteiraA, $this->mercado, $this->iguaisAB);
    $parcelas = $serie->lancamentos()->orderBy('numero_parcela')->get();
    $parcelas[2]->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);

    $this->actingAs($this->a)->put(route('lancamentos.update', $parcelas[0]), [
        ...dadosRateio($this->carteiraA, $this->mercado, 'iguais', $this->iguaisAB, ['data' => '2026-12-05', 'valor' => '120,00', 'descricao' => $parcelas[0]->descricao]),
        'escopo' => 'todas',
    ])->assertSessionHasNoErrors();

    expect($serie->lancamentos()->orderBy('numero_parcela')->pluck('valor')->all())->toBe([12000, 12000, 10000, 12000])
        ->and(valoresDasCotas($parcelas[1]))->toBe([$this->a->id => 6000, $this->b->id => 6000])
        ->and(valoresDasCotas($parcelas[2]))->toBe([$this->a->id => 5000, $this->b->id => 5000])
        ->and($serie->fresh()->valor_parcela)->toBe(12000);
});

it('CA-072.5 keeps an installment shared on its own when a mass edit of an unshared series has an executed participant share', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosSerie($this->carteiraA, $this->mercado, ['valor' => '100,00', 'total_parcelas' => 4, 'modo_valor' => 'parcela']))
        ->assertSessionHasNoErrors();
    $serie = $this->a->parcelamentos()->sole();
    $parcelas = $serie->lancamentos()->orderBy('numero_parcela')->get();

    $this->actingAs($this->a)->put(route('lancamentos.update', $parcelas[2]), [
        ...dadosRateio($this->carteiraA, $this->mercado, 'iguais', $this->iguaisAB, ['descricao' => 'TV', 'data' => $parcelas[2]->data->toDateString()]),
        'escopo' => 'esta',
    ])->assertSessionHasNoErrors();
    $parcelas[2]->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);

    $this->actingAs($this->a)->put(route('lancamentos.update', $parcelas[0]), [
        'tipo' => 'despesa', 'descricao' => 'TV nova', 'valor' => '120,00', 'data' => $parcelas[0]->data->toDateString(),
        'categoria_id' => $this->mercado->id, 'carteira_id' => $this->carteiraA->id, 'escopo' => 'todas',
    ])->assertSessionHasNoErrors();

    expect($serie->lancamentos()->orderBy('numero_parcela')->pluck('valor')->all())->toBe([12000, 12000, 10000, 12000])
        ->and(valoresDasCotas($parcelas[2]))->toBe([$this->a->id => 5000, $this->b->id => 5000])
        ->and(valoresDasCotas($parcelas[1]))->toBe([$this->a->id => 12000]);
});

it('RN-086 keeps past installments without an author share when deleting the whole series', function () {
    $serie = serieRateada($this->a, $this->carteiraA, $this->mercado, [['user_id' => $this->b->id]], ['data' => '2026-10-05']);

    $this->actingAs($this->a)->delete(route('lancamentos.destroy', $serie->lancamentos()->where('numero_parcela', 3)->sole()), ['escopo' => 'todas'])
        ->assertSessionHasNoErrors();

    expect($serie->lancamentos()->orderBy('numero_parcela')->pluck('numero_parcela')->all())->toBe([1, 2]);
});

it('CA-073.6 leaves only installments without an executed share of the participant or the author', function () {
    $serie = serieRateada($this->a, $this->carteiraA, $this->mercado, $this->iguaisAB);
    $parcelas = $serie->lancamentos()->orderBy('numero_parcela')->get();
    $parcelas[0]->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);
    $parcelas[1]->cotas()->where('user_id', $this->a->id)->update(['executado' => true, 'executado_em' => now()]);

    $this->actingAs($this->b)->delete(route('cotas.destroy', $parcelas[2]->cotas()->where('user_id', $this->b->id)->sole()), ['escopo' => 'todas'])
        ->assertSessionHasNoErrors();

    expect(valoresDasCotas($parcelas[0]))->toBe([$this->a->id => 5000, $this->b->id => 5000])
        ->and(valoresDasCotas($parcelas[1]))->toBe([$this->a->id => 5000, $this->b->id => 5000])
        ->and(valoresDasCotas($parcelas[2]))->toBe([$this->a->id => 10000])
        ->and(valoresDasCotas($parcelas[3]))->toBe([$this->a->id => 10000]);
});

it('CA-073.4 leaves later installments and the series model with scope "esta e próximas"', function () {
    $serie = serieRateada($this->a, $this->carteiraA, $this->mercado, $this->iguaisAB);
    $segunda = $serie->lancamentos()->where('numero_parcela', 2)->sole();
    $cotaB = $segunda->cotas()->where('user_id', $this->b->id)->sole();

    $this->actingAs($this->b)->delete(route('cotas.destroy', $cotaB))->assertSessionHasErrors('escopo');
    $this->actingAs($this->b)->delete(route('cotas.destroy', $cotaB), ['escopo' => 'proximas'])->assertSessionHasNoErrors();

    expect(Cota::query()->where('cotas.user_id', $this->b->id)->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
        ->pluck('lancamentos.numero_parcela')->all())->toBe([1])
        ->and($serie->fresh()->modo_rateio)->toBeNull()
        ->and($serie->cotas()->count())->toBe(0)
        ->and(valoresDasCotas($serie->lancamentos()->where('numero_parcela', 3)->sole()))->toBe([$this->a->id => 10000]);
});

it('CA-073.5 keeps the shares of who stayed in installments generated after a participant left an equal split', function () {
    $c = User::factory()->create(['name' => 'Carla']);
    Carteira::factory()->for($c)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $c->id]);
    $serie = serieRateada($this->a, $this->carteiraA, $this->mercado, [...$this->iguaisAB, ['user_id' => $c->id]], [
        'valor' => '90,00', 'repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null,
    ]);
    $primeira = $serie->lancamentos()->where('numero_parcela', 1)->sole();

    $this->actingAs($c)->delete(route('cotas.destroy', $primeira->cotas()->where('user_id', $c->id)->sole()), ['escopo' => 'todas'])
        ->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2027-01-06 03:00'));
    $this->artisan('series:estender-recorrentes')->assertSuccessful();

    $nova = $serie->lancamentos()->orderByDesc('numero_parcela')->first();

    expect($serie->fresh()->modo_rateio)->toBe(ModoRateio::Fixo)
        ->and($serie->cotas()->pluck('valor', 'user_id')->all())->toBe([$this->a->id => 6000, $this->b->id => 3000])
        ->and(valoresDasCotas($primeira))->toBe([$this->a->id => 6000, $this->b->id => 3000])
        ->and($nova->modo_rateio)->toBe(ModoRateio::Fixo)
        ->and(valoresDasCotas($nova))->toBe([$this->a->id => 6000, $this->b->id => 3000]);
});

it('CA-007.3 turns participant shares into their own entries when the author deletes the account', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', $this->iguaisAB, ['data' => '2026-11-10']));
    $cotaB = Cota::query()->where('user_id', $this->b->id)->sole();
    $cotaB->forceFill(['carteira_id' => $this->outraB->id])->save();

    $this->actingAs($this->a)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

    $proprio = $this->b->lancamentos()->sole();

    expect($proprio)->valor->toBe(5000)->modo_rateio->toBeNull()->descricao->toBe('Jantar')
        ->and($proprio->cotas()->sole())
        ->id->toBe($cotaB->id)
        ->carteira_id->toBe($this->outraB->id)
        ->categoria_id->toBe($this->mercado->id)
        ->executado->toBeTrue();
});

it('CA-007.4 gives the share back to the author when a participant deletes the account', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->carteiraA, $this->mercado, 'iguais', $this->iguaisAB));
    $serie = serieRateada($this->a, $this->carteiraA, $this->mercado, $this->iguaisAB);
    $lancamento = $this->a->lancamentos()->whereNull('parcelamento_id')->sole();

    $this->actingAs($this->b)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

    expect(valoresDasCotas($lancamento))->toBe([$this->a->id => 10000])
        ->and($lancamento->fresh())->valor->toBe(10000)->modo_rateio->toBeNull()
        ->and($serie->cotas()->count())->toBe(0)
        ->and($serie->fresh()->modo_rateio)->toBeNull();
});

it('CA-024.4 deletes own entries and gives received shares back in the cascade', function () {
    $proprio = ratearNaCarteira($this->a, $this->outraA, $this->mercado, $this->iguaisAB);
    $recebido = ratearNaCarteira($this->b, $this->carteiraB, $this->mercado, [['user_id' => $this->b->id], ['user_id' => $this->a->id]]);
    $recebido->cotas()->where('user_id', $this->a->id)->update(['carteira_id' => $this->outraA->id]);

    $this->actingAs($this->a)->delete(route('carteiras.destroy', $this->outraA), ['modo' => 'excluir'])->assertSessionHasNoErrors();

    expect($proprio->fresh())->toBeNull()
        ->and(valoresDasCotas($recebido))->toBe([$this->b->id => 10000])
        ->and($this->outraA->fresh())->toBeNull();
});

it('CA-024.5 deletes nothing and lists the blocking entries', function () {
    $proprio = ratearNaCarteira($this->a, $this->outraA, $this->mercado, $this->iguaisAB);
    $proprio->cotas()->where('user_id', $this->b->id)->update(['executado' => true, 'executado_em' => now()]);
    $simples = lancamentoComCota($this->a, $this->outraA, $this->mercado, ['data' => '2026-11-12']);

    $this->actingAs($this->a)->delete(route('carteiras.destroy', $this->outraA), ['modo' => 'excluir'])
        ->assertSessionHasErrors(['carteira' => 'Nada foi excluído. Estes lançamentos têm cota já executada por um participante: Jantar (20/11/2026).']);

    expect($this->outraA->fresh())->not->toBeNull()
        ->and($proprio->fresh())->not->toBeNull()
        ->and($simples->fresh())->not->toBeNull();
});

function ratearNaCarteira(User $autor, Carteira $carteira, Categoria $categoria, array $participantes): Lancamento
{
    test()->actingAs($autor)->post(route('lancamentos.store'), dadosRateio($carteira, $categoria, 'iguais', $participantes))->assertSessionHasNoErrors();

    return $autor->lancamentos()->latest('id')->firstOrFail();
}
