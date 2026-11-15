<?php

use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Grupo;
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
    $this->x = Carteira::factory()->for($this->a)->padrao()->create(['nome' => 'X']);
    $this->z = Carteira::factory()->for($this->a)->create(['nome' => 'Z']);
    $this->carteiraB = Carteira::factory()->for($this->b)->padrao()->create();
    $this->outraB = Carteira::factory()->for($this->b)->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);
    $this->mercado = Categoria::query()->globais()->where('nome', 'Mercado')->sole();
    $this->salario = Categoria::query()->globais()->where('nome', 'Salário')->sole();
});

/**
 * A simple expense of Ana in wallet X, returning its single share.
 *
 * @param  array<string, mixed>  $lancamento
 * @param  array<string, mixed>  $cota
 */
function despesaEmX(array $lancamento = [], array $cota = []): Cota
{
    return lancamentoComCota(test()->a, test()->x, test()->mercado, ['tipo' => 'despesa', 'data' => '2026-11-10', ...$lancamento], $cota)
        ->cotas()->sole();
}

/**
 * Series of 10 installments of Ana in wallet X, December 2026 to September 2027.
 *
 * @param  array<string, mixed>  $dados
 */
function serieEmX(array $dados = []): Parcelamento
{
    test()->actingAs(test()->a)->post(route('lancamentos.store'), dadosSerie(test()->x, test()->mercado, $dados))->assertSessionHasNoErrors();

    return test()->a->parcelamentos()->latest('id')->firstOrFail();
}

function cotaDaParcela(Parcelamento $serie, int $numero, User $user): Cota
{
    return Cota::query()
        ->where('user_id', $user->id)
        ->whereHas('lancamento', fn ($query) => $query->where('parcelamento_id', $serie->id)->where('numero_parcela', $numero))
        ->sole();
}

/**
 * @return list<int> installment numbers whose share of the user is in the group
 */
function parcelasNoGrupo(Parcelamento $serie, User $user, Grupo $grupo): array
{
    return Cota::query()
        ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
        ->where('lancamentos.parcelamento_id', $serie->id)
        ->where('cotas.user_id', $user->id)
        ->where('cotas.grupo_id', $grupo->id)
        ->orderBy('lancamentos.numero_parcela')
        ->pluck('lancamentos.numero_parcela')
        ->all();
}

it('CA-090.1 creates a group with the type and wallet of the selected expenses', function () {
    $cotas = [despesaEmX(), despesaEmX(), despesaEmX()];

    $this->actingAs($this->a)->post(route('grupos.agrupar'), [
        'cotas' => array_map(fn (Cota $cota) => $cota->id, $cotas),
        'nome' => 'Fatura Nubank',
    ])->assertSessionHasNoErrors();

    $grupo = Grupo::query()->sole();

    expect($grupo)->nome->toBe('Fatura Nubank')->tipo->value->toBe('despesa')->carteira_id->toBe($this->x->id)->user_id->toBe($this->a->id)
        ->and(Cota::query()->where('grupo_id', $grupo->id)->count())->toBe(3);
});

it('CA-090.2 refuses a selection with income and expense', function () {
    $despesa = despesaEmX();
    $receita = lancamentoComCota($this->a, $this->x, $this->salario, ['tipo' => 'receita'])->cotas()->sole();

    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [$despesa->id, $receita->id], 'nome' => 'Misto'])
        ->assertSessionHasErrors(['cotas' => 'Selecione itens do mesmo tipo: receitas e despesas não ficam no mesmo grupo.']);

    expect(Grupo::query()->count())->toBe(0);
});

it('RN-121 refuses a selection from two wallets', function () {
    $emZ = lancamentoComCota($this->a, $this->z, $this->mercado, ['tipo' => 'despesa'])->cotas()->sole();

    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [despesaEmX()->id, $emZ->id], 'nome' => 'Fatura'])
        ->assertSessionHasErrors(['cotas' => 'Selecione itens da mesma carteira.']);
});

it('CA-090.3 adds expenses to an existing expense group and refuses an income group', function () {
    $despesas = Grupo::factory()->for($this->a)->for($this->x)->create();
    $receitas = Grupo::factory()->for($this->a)->for($this->x)->create(['tipo' => 'receita']);
    $cota = despesaEmX();

    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [$cota->id], 'grupo_id' => $receitas->id])
        ->assertSessionHasErrors(['grupo_id' => 'Este grupo não aceita os itens selecionados.']);

    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [$cota->id], 'grupo_id' => $despesas->id])
        ->assertSessionHasNoErrors();

    expect($cota->fresh()->grupo_id)->toBe($despesas->id);
});

it('RN-121 refuses an existing group of another wallet', function () {
    $grupoEmZ = Grupo::factory()->for($this->a)->for($this->z)->create();

    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [despesaEmX()->id], 'grupo_id' => $grupoEmZ->id])
        ->assertSessionHasErrors('grupo_id');
});

it('RN-150 requires a group name up to 100 characters', function () {
    $cota = despesaEmX();

    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [$cota->id]])
        ->assertSessionHasErrors(['nome' => 'Informe o nome do grupo ou escolha um grupo existente.']);
    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [$cota->id], 'nome' => str_repeat('a', 101)])
        ->assertSessionHasErrors('nome');

    Grupo::factory()->for($this->a)->for($this->x)->create(['nome' => 'Fatura']);

    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [$cota->id], 'nome' => 'Fatura'])
        ->assertSessionHasNoErrors();
    expect(Grupo::query()->where('nome', 'Fatura')->count())->toBe(2);
});

it('CA-090.4 groups installments 4 to 10 with "esta e próximas" and records the group on the series model', function () {
    $serie = serieEmX();

    $this->actingAs($this->a)->post(route('grupos.agrupar'), [
        'cotas' => [cotaDaParcela($serie, 4, $this->a)->id], 'nome' => 'Fatura', 'escopo' => 'proximas',
    ])->assertSessionHasNoErrors();

    $grupo = Grupo::query()->sole();

    expect(parcelasNoGrupo($serie, $this->a, $grupo))->toBe([4, 5, 6, 7, 8, 9, 10])
        ->and($serie->fresh()->grupo_id)->toBe($grupo->id);
});

it('CA-090.4 records the group of a participant on the model share and not on the author series', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), [
        ...dadosRateio($this->x, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['data' => '2026-12-05']),
        'repeticao' => 'parcelado', 'periodicidade' => 'mensal', 'total_parcelas' => 4, 'modo_valor' => 'parcela',
    ])->assertSessionHasNoErrors();
    $serie = $this->a->parcelamentos()->sole();

    $this->actingAs($this->b)->post(route('grupos.agrupar'), [
        'cotas' => [cotaDaParcela($serie, 2, $this->b)->id], 'nome' => 'Contas da Ana', 'escopo' => 'proximas',
    ])->assertSessionHasNoErrors();

    $grupo = Grupo::query()->sole();

    expect(parcelasNoGrupo($serie, $this->b, $grupo))->toBe([2, 3, 4])
        ->and($serie->cotas()->where('user_id', $this->b->id)->sole()->grupo_id)->toBe($grupo->id)
        ->and($serie->fresh()->grupo_id)->toBeNull()
        ->and($grupo->carteira_id)->toBe($this->carteiraB->id);
});

it('CA-090.5 generates a recurring installment in the group of the model', function () {
    $serie = serieEmX(['repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null, 'valor' => '50,00']);

    $this->actingAs($this->a)->post(route('grupos.agrupar'), [
        'cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'Assinaturas', 'escopo' => 'todas',
    ])->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2027-01-06 03:00'));

    $this->artisan('series:estender-recorrentes')->assertSuccessful();

    $nova = $serie->lancamentos()->orderByDesc('numero_parcela')->first();

    expect($nova->data->toDateString())->toBe('2028-01-05')
        ->and($nova->cotas()->sole()->grupo_id)->toBe(Grupo::query()->sole()->id);
});

it('CA-090.6 a participant groups their own share without changing the author share', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->x, $this->mercado, 'iguais', [
        ['user_id' => $this->a->id], ['user_id' => $this->b->id],
    ]))->assertSessionHasNoErrors();
    $lancamento = Lancamento::query()->sole();
    $cotaB = $lancamento->cotas()->where('user_id', $this->b->id)->sole();

    $this->actingAs($this->b)->post(route('grupos.agrupar'), ['cotas' => [$cotaB->id], 'nome' => 'Da Ana'])
        ->assertSessionHasNoErrors();

    expect($cotaB->fresh()->grupo_id)->not->toBeNull()
        ->and($lancamento->cotas()->where('user_id', $this->a->id)->sole()->grupo_id)->toBeNull();
});

it('RN-127 refuses grouping a share of another user', function () {
    $this->actingAs($this->b)->post(route('grupos.agrupar'), ['cotas' => [despesaEmX()->id], 'nome' => 'Alheio'])
        ->assertSessionHasErrors(['cotas.0' => 'Selecione itens seus.']);
});

it('CA-090.7 keeps the balances after grouping', function () {
    $cotas = [despesaEmX(['valor' => 12000]), despesaEmX(['valor' => 8000], ['executado' => true])];
    $saldos = fn () => $this->actingAs($this->a)->get(route('home'))->inertiaProps('saldos');
    $antes = $saldos();

    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => array_map(fn (Cota $cota) => $cota->id, $cotas), 'nome' => 'Fatura']);

    expect($saldos())->toBe($antes);
});

it('CA-090.8 a new participant installment joins the model group only when the group is in the wallet it is born in', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), [
        ...dadosRateio($this->x, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['data' => '2026-12-05']),
        'repeticao' => 'recorrente', 'periodicidade' => 'mensal',
    ])->assertSessionHasNoErrors();
    $serie = $this->a->parcelamentos()->sole();
    $modeloB = $serie->cotas()->where('user_id', $this->b->id)->sole();
    $grupoY = Grupo::factory()->for($this->b)->for($this->outraB)->create();
    $grupoX = Grupo::factory()->for($this->b)->for($this->carteiraB)->create();

    $modeloB->update(['grupo_id' => $grupoY->id]);
    $this->travelTo(CarbonImmutable::parse('2027-01-06 03:00'));
    $this->artisan('series:estender-recorrentes')->assertSuccessful();

    $janeiro = $serie->lancamentos()->orderByDesc('numero_parcela')->first();
    expect($janeiro->cotas()->where('user_id', $this->b->id)->sole())
        ->carteira_id->toBe($this->carteiraB->id)
        ->grupo_id->toBeNull();

    $modeloB->update(['grupo_id' => $grupoX->id]);
    $this->travelTo(CarbonImmutable::parse('2027-02-06 03:00'));
    $this->artisan('series:estender-recorrentes')->assertSuccessful();

    $fevereiro = $serie->lancamentos()->orderByDesc('numero_parcela')->first();
    expect($fevereiro->id)->not->toBe($janeiro->id)
        ->and($fevereiro->cotas()->where('user_id', $this->b->id)->sole()->grupo_id)->toBe($grupoX->id);
});

it('CA-090.9 groups every installment of the scope in the group wallet, executed or not', function () {
    $serie = serieEmX();
    cotaDaParcela($serie, 2, $this->a)->update(['executado' => true, 'executado_em' => now()]);
    cotaDaParcela($serie, 6, $this->a)->update(['carteira_id' => $this->z->id]);

    $this->actingAs($this->a)->post(route('grupos.agrupar'), [
        'cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'Fatura', 'escopo' => 'todas',
    ])->assertSessionHasNoErrors();

    expect(parcelasNoGrupo($serie, $this->a, Grupo::query()->sole()))->toBe([1, 2, 3, 4, 5, 7, 8, 9, 10]);
});

it('CA-091.1 shows the group row in the Grupos subsection and not the items in other subsections', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create(['nome' => 'Fatura Nubank']);
    despesaEmX(['valor' => 12000], ['grupo_id' => $grupo->id]);
    despesaEmX(['valor' => 8000], ['grupo_id' => $grupo->id]);
    despesaEmX(['valor' => 5000]);

    $despesas = $this->actingAs($this->a)->get(route('home'))->inertiaProps('lancamentos.despesa');

    expect($despesas['avista'])->toHaveCount(1)
        ->and($despesas['grupos'])->toHaveCount(1)
        ->and($despesas['grupos'][0])->toMatchArray([
            'id' => $grupo->id, 'nome' => 'Fatura Nubank', 'quantidade' => 2, 'valor' => 20000, 'filtrado' => false,
        ])
        ->and($despesas['grupos'][0]['itens'])->toHaveCount(2);
});

it('CA-091.2 hides the group in a month without its shares', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    despesaEmX([], ['grupo_id' => $grupo->id]);

    $this->actingAs($this->a)->get(route('home', ['mes' => '2026-12']))
        ->assertInertia(fn ($page) => $page->where('lancamentos.despesa.grupos', []));
});

it('CA-091.3 lists the group items with their indicators', function () {
    $serie = serieEmX(['data' => '2026-11-05']);
    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'Fatura']);

    $item = $this->actingAs($this->a)->get(route('home'))->inertiaProps('lancamentos.despesa.grupos.0.itens.0');

    expect($item)->toMatchArray(['parcela' => '1/10', 'recorrente' => false, 'compartilhado' => false, 'grupoId' => Grupo::query()->sole()->id]);
});

it('CA-091.4 aggregates the status of the group', function (array $executados, string $status) {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();

    foreach ($executados as $executado) {
        despesaEmX([], ['grupo_id' => $grupo->id, 'executado' => $executado]);
    }

    expect($this->actingAs($this->a)->get(route('home'))->inertiaProps('lancamentos.despesa.grupos.0.status'))->toBe($status);
})->with([
    'parcial' => [[true, false], 'parcial'],
    'executado' => [[true, true], 'executado'],
    'pendente' => [[false, false], 'pendente'],
]);

it('CA-091.5 sums only the visible items and marks the group as filtered', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    despesaEmX(['valor' => 12000], ['grupo_id' => $grupo->id, 'executado' => true]);
    despesaEmX(['valor' => 8000], ['grupo_id' => $grupo->id]);

    $linha = $this->actingAs($this->a)->get(route('home', ['status' => 'pendente']))->inertiaProps('lancamentos.despesa.grupos.0');

    expect($linha)->toMatchArray(['quantidade' => 1, 'valor' => 8000, 'filtrado' => true]);

    $this->actingAs($this->a)->get(route('home', ['categoria' => $this->salario->id]))
        ->assertInertia(fn ($page) => $page->where('lancamentos.despesa.grupos', []));
});

it('CA-092.1 marks every visible share of the group as executed', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    $cotas = [despesaEmX([], ['grupo_id' => $grupo->id]), despesaEmX([], ['grupo_id' => $grupo->id])];

    $this->actingAs($this->a)->patch(route('grupos.executado', $grupo), [
        'executado' => true, 'cotas' => array_map(fn (Cota $cota) => $cota->id, $cotas),
    ])->assertSessionHasNoErrors();

    foreach ($cotas as $cota) {
        expect($cota->fresh())->executado->toBeTrue()->executado_em->not->toBeNull();
    }
});

it('CA-092.1 changes only the shares sent as visible', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    $visivel = despesaEmX([], ['grupo_id' => $grupo->id]);
    $oculta = despesaEmX([], ['grupo_id' => $grupo->id]);

    $this->actingAs($this->a)->patch(route('grupos.executado', $grupo), ['executado' => true, 'cotas' => [$visivel->id]]);

    expect($visivel->fresh()->executado)->toBeTrue()
        ->and($oculta->fresh()->executado)->toBeFalse();
});

it('CA-092.2 unmarks every visible share of the group', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    $cotas = [despesaEmX([], ['grupo_id' => $grupo->id, 'executado' => true]), despesaEmX([], ['grupo_id' => $grupo->id, 'executado' => true])];

    $this->actingAs($this->a)->patch(route('grupos.executado', $grupo), [
        'executado' => false, 'cotas' => array_map(fn (Cota $cota) => $cota->id, $cotas),
    ])->assertSessionHasNoErrors();

    foreach ($cotas as $cota) {
        expect($cota->fresh())->executado->toBeFalse()->executado_em->toBeNull();
    }
});

it('RN-128 refuses executing a share outside the group', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    $fora = despesaEmX();

    $this->actingAs($this->a)->patch(route('grupos.executado', $grupo), ['executado' => true, 'cotas' => [$fora->id]])
        ->assertSessionHasErrors('cotas.0');

    expect($fora->fresh()->executado)->toBeFalse();
});

it('CA-093.1 ungroups a simple item back to its subsection', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    $cota = despesaEmX([], ['grupo_id' => $grupo->id]);

    $this->actingAs($this->a)->post(route('grupos.desagrupar'), ['cotas' => [$cota->id]])->assertSessionHasNoErrors();

    expect($cota->fresh()->grupo_id)->toBeNull();
    $this->actingAs($this->a)->get(route('home'))
        ->assertInertia(fn ($page) => $page->has('lancamentos.despesa.avista', 1)->where('lancamentos.despesa.grupos', []));
});

it('CA-093.1 ungrouping one installment keeps the others and the series model', function () {
    $serie = serieEmX();
    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'Fatura', 'escopo' => 'todas']);
    $grupo = Grupo::query()->sole();

    $this->actingAs($this->a)->post(route('grupos.desagrupar'), ['cotas' => [cotaDaParcela($serie, 3, $this->a)->id], 'escopo' => 'esta']);

    expect(parcelasNoGrupo($serie, $this->a, $grupo))->toBe([1, 2, 4, 5, 6, 7, 8, 9, 10])
        ->and($serie->fresh()->grupo_id)->toBe($grupo->id);
});

it('CA-093.1 ungrouping a participant installment for all clears the model share group', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), [
        ...dadosRateio($this->x, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['data' => '2026-12-05']),
        'repeticao' => 'parcelado', 'periodicidade' => 'mensal', 'total_parcelas' => 4, 'modo_valor' => 'parcela',
    ]);
    $serie = $this->a->parcelamentos()->sole();
    $this->actingAs($this->b)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 1, $this->b)->id], 'nome' => 'Da Ana', 'escopo' => 'todas']);

    $this->actingAs($this->b)->post(route('grupos.desagrupar'), ['cotas' => [cotaDaParcela($serie, 2, $this->b)->id], 'escopo' => 'todas'])
        ->assertSessionHasNoErrors();

    expect(parcelasNoGrupo($serie, $this->b, Grupo::query()->sole()))->toBe([])
        ->and($serie->cotas()->where('user_id', $this->b->id)->sole()->grupo_id)->toBeNull();
});

it('CA-093.2 renames the group in every month', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create(['nome' => 'Fatura']);
    despesaEmX(['data' => '2026-11-10'], ['grupo_id' => $grupo->id]);
    despesaEmX(['data' => '2026-12-10'], ['grupo_id' => $grupo->id]);

    $this->actingAs($this->a)->patch(route('grupos.update', $grupo), ['nome' => 'Cartão'])->assertSessionHasNoErrors();

    foreach (['2026-11', '2026-12'] as $mes) {
        expect($this->actingAs($this->a)->get(route('home', ['mes' => $mes]))->inertiaProps('lancamentos.despesa.grupos.0.nome'))->toBe('Cartão');
    }
});

it('CA-093.3 deleting a group keeps the shares without group and the balances', function () {
    $serie = serieEmX();
    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'Fatura', 'escopo' => 'todas']);
    $grupo = Grupo::query()->sole();
    $saldos = fn () => $this->actingAs($this->a)->get(route('home', ['mes' => '2026-12']))->inertiaProps('saldos');
    $antes = $saldos();

    $this->actingAs($this->a)->delete(route('grupos.destroy', $grupo))->assertSessionHasNoErrors();

    expect(Grupo::query()->count())->toBe(0)
        ->and($serie->lancamentos()->count())->toBe(10)
        ->and(Cota::query()->whereNotNull('grupo_id')->count())->toBe(0)
        ->and($serie->fresh()->grupo_id)->toBeNull()
        ->and($saldos())->toBe($antes);
});

it('CA-093.4 a share moved to another wallet leaves its group', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    $cota = despesaEmX(['descricao' => 'Mercado'], ['grupo_id' => $grupo->id]);

    $this->actingAs($this->a)->put(route('lancamentos.update', $cota->lancamento_id), [
        'tipo' => 'despesa', 'descricao' => 'Mercado', 'valor' => '10,00', 'data' => '2026-11-10',
        'categoria_id' => $this->mercado->id, 'carteira_id' => $this->x->id,
    ])->assertSessionHasNoErrors();
    expect($cota->fresh()->grupo_id)->toBe($grupo->id);

    $this->actingAs($this->a)->put(route('lancamentos.update', $cota->lancamento_id), [
        'tipo' => 'despesa', 'descricao' => 'Mercado', 'valor' => '10,00', 'data' => '2026-11-10',
        'categoria_id' => $this->mercado->id, 'carteira_id' => $this->z->id,
    ])->assertSessionHasNoErrors();
    expect($cota->fresh()->grupo_id)->toBeNull();
});

it('CA-093.4 a participant share moved to another wallet leaves its group', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), dadosRateio($this->x, $this->mercado, 'iguais', [
        ['user_id' => $this->a->id], ['user_id' => $this->b->id],
    ]));
    $cotaB = Cota::query()->where('user_id', $this->b->id)->sole();
    $grupo = Grupo::factory()->for($this->b)->for($this->carteiraB)->create();
    $cotaB->update(['grupo_id' => $grupo->id]);

    $this->actingAs($this->b)->put(route('cotas.update', $cotaB), [
        'carteira_id' => $this->outraB->id, 'categoria_id' => $this->mercado->id, 'executado' => false,
    ])->assertSessionHasNoErrors();

    expect($cotaB->fresh()->grupo_id)->toBeNull();
});

it('CA-093.4 a mass edit moving the series to another wallet takes the pending installments and the model out of the group', function () {
    $serie = serieEmX();
    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'Fatura', 'escopo' => 'todas']);
    $grupo = Grupo::query()->sole();

    $this->actingAs($this->a)->put(route('lancamentos.update', cotaDaParcela($serie, 5, $this->a)->lancamento_id), [
        'tipo' => 'despesa', 'descricao' => 'TV', 'valor' => '100,00', 'data' => '2027-04-05',
        'categoria_id' => $this->mercado->id, 'carteira_id' => $this->z->id, 'escopo' => 'proximas',
    ])->assertSessionHasNoErrors();

    expect(parcelasNoGrupo($serie, $this->a, $grupo))->toBe([1, 2, 3, 4])
        ->and($serie->fresh())->carteira_id->toBe($this->z->id)->grupo_id->toBeNull();
});

it('RN-127 a mass edit by the author keeps the participant model group', function () {
    $this->actingAs($this->a)->post(route('lancamentos.store'), [
        ...dadosRateio($this->x, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]], ['data' => '2026-12-05']),
        'repeticao' => 'parcelado', 'periodicidade' => 'mensal', 'total_parcelas' => 4, 'modo_valor' => 'parcela',
    ]);
    $serie = $this->a->parcelamentos()->sole();
    $this->actingAs($this->b)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 1, $this->b)->id], 'nome' => 'Da Ana', 'escopo' => 'todas']);

    $this->actingAs($this->a)->put(route('lancamentos.update', cotaDaParcela($serie, 2, $this->a)->lancamento_id), [
        ...dadosRateio($this->x, $this->mercado, 'iguais', [['user_id' => $this->a->id], ['user_id' => $this->b->id]], [
            'descricao' => 'Internet', 'valor' => '120,00', 'data' => '2027-01-05',
        ]),
        'escopo' => 'todas',
    ])->assertSessionHasNoErrors();

    expect($serie->cotas()->where('user_id', $this->b->id)->sole()->grupo_id)->toBe(Grupo::query()->sole()->id)
        ->and(parcelasNoGrupo($serie, $this->b, Grupo::query()->sole()))->toBe([1, 2, 3, 4]);
});

it('CA-093.5 refuses every action on a group of another user', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->x)->create();
    $cotaA = despesaEmX([], ['grupo_id' => $grupo->id]);
    $cotaB = lancamentoComCota($this->b, $this->carteiraB, $this->mercado, ['tipo' => 'despesa'])->cotas()->sole();
    $this->actingAs($this->b);

    $this->post(route('grupos.agrupar'), ['cotas' => [$cotaB->id], 'grupo_id' => $grupo->id])->assertForbidden();
    $this->patch(route('grupos.executado', $grupo), ['executado' => true, 'cotas' => [$cotaA->id]])->assertForbidden();
    $this->patch(route('grupos.update', $grupo), ['nome' => 'Meu'])->assertForbidden();
    $this->delete(route('grupos.destroy', $grupo))->assertForbidden();
    $this->post(route('grupos.desagrupar'), ['cotas' => [$cotaA->id]])->assertSessionHasErrors('cotas.0');

    expect($grupo->fresh()->nome)->not->toBe('Meu')
        ->and($cotaA->fresh())->executado->toBeFalse()->grupo_id->toBe($grupo->id);
});

it('CA-093.6 ungrouping with "esta e próximas" takes out only the shares of the same group', function () {
    $serie = serieEmX();
    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'G', 'escopo' => 'todas']);
    $g = Grupo::query()->where('nome', 'G')->sole();
    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 8, $this->a)->id], 'nome' => 'H']);
    $h = Grupo::query()->where('nome', 'H')->sole();

    $this->actingAs($this->a)->post(route('grupos.desagrupar'), ['cotas' => [cotaDaParcela($serie, 4, $this->a)->id], 'escopo' => 'proximas'])
        ->assertSessionHasNoErrors();

    expect(parcelasNoGrupo($serie, $this->a, $g))->toBe([1, 2, 3])
        ->and(parcelasNoGrupo($serie, $this->a, $h))->toBe([8])
        ->and($serie->fresh()->grupo_id)->toBeNull();
});

it('RN-132 deleting a wallet in cascade deletes its groups', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->z)->create();
    lancamentoComCota($this->a, $this->z, $this->mercado, ['tipo' => 'despesa'], ['grupo_id' => $grupo->id]);

    $this->actingAs($this->a)->delete(route('carteiras.destroy', $this->z), ['modo' => 'excluir'])->assertSessionHasNoErrors();

    expect(Grupo::query()->count())->toBe(0);
});

it('RN-132 moving the wallet contents keeps the shares in their group', function () {
    $grupo = Grupo::factory()->for($this->a)->for($this->z)->create();
    $cota = lancamentoComCota($this->a, $this->z, $this->mercado, ['tipo' => 'despesa'], ['grupo_id' => $grupo->id])->cotas()->sole();

    $this->actingAs($this->a)->delete(route('carteiras.destroy', $this->z), ['modo' => 'mover', 'destino_id' => $this->x->id])->assertSessionHasNoErrors();

    expect($grupo->fresh()->carteira_id)->toBe($this->x->id)
        ->and($cota->fresh())->carteira_id->toBe($this->x->id)->grupo_id->toBe($grupo->id);
});

it('RN-149 a mass grouping keeps the author series model out of a group of another wallet', function () {
    $serie = serieEmX();
    $serie->update(['carteira_id' => $this->z->id]);

    $this->actingAs($this->a)->post(route('grupos.agrupar'), [
        'cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'Fatura', 'escopo' => 'todas',
    ])->assertSessionHasNoErrors();

    expect(parcelasNoGrupo($serie, $this->a, Grupo::query()->sole()))->toHaveCount(10)
        ->and($serie->fresh()->grupo_id)->toBeNull();
});

it('RN-130 a mass edit in the same wallet keeps the group of the pending installments and the model', function () {
    $serie = serieEmX();
    $this->actingAs($this->a)->post(route('grupos.agrupar'), ['cotas' => [cotaDaParcela($serie, 1, $this->a)->id], 'nome' => 'Fatura', 'escopo' => 'todas']);
    $grupo = Grupo::query()->sole();

    $this->actingAs($this->a)->put(route('lancamentos.update', cotaDaParcela($serie, 5, $this->a)->lancamento_id), [
        'tipo' => 'despesa', 'descricao' => 'TV nova', 'valor' => '110,00', 'data' => '2027-04-05',
        'categoria_id' => $this->mercado->id, 'carteira_id' => $this->x->id, 'escopo' => 'proximas',
    ])->assertSessionHasNoErrors();

    expect(parcelasNoGrupo($serie, $this->a, $grupo))->toHaveCount(10)
        ->and($serie->fresh()->grupo_id)->toBe($grupo->id);
});

it('RN-149 several installments of one series selected with "esta e próximas" start from the lowest', function () {
    $serie = serieEmX();

    $this->actingAs($this->a)->post(route('grupos.agrupar'), [
        'cotas' => [cotaDaParcela($serie, 6, $this->a)->id, cotaDaParcela($serie, 3, $this->a)->id], 'nome' => 'Fatura', 'escopo' => 'proximas',
    ])->assertSessionHasNoErrors();

    $grupo = Grupo::query()->sole();
    expect(parcelasNoGrupo($serie, $this->a, $grupo))->toBe([3, 4, 5, 6, 7, 8, 9, 10]);

    $this->actingAs($this->a)->post(route('grupos.desagrupar'), [
        'cotas' => [cotaDaParcela($serie, 8, $this->a)->id, cotaDaParcela($serie, 5, $this->a)->id], 'escopo' => 'proximas',
    ])->assertSessionHasNoErrors();

    expect(parcelasNoGrupo($serie, $this->a, $grupo))->toBe([3, 4]);
});
