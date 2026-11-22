<?php

use App\Enums\ModoRateio;
use App\Enums\TipoCategoria;
use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->user = User::factory()->create();
    $this->principal = Carteira::factory()->for($this->user)->padrao()->create();
    $this->carteira = Carteira::factory()->for($this->user)->create(['nome' => 'X']);
    $this->categoria = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Ambos]);
});

it('CA-080.1 opens the default wallet and the current month', function () {
    $this->actingAs($this->user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inicio')
            ->where('carteira.id', $this->principal->id)
            ->where('mes', '2026-11')
            ->where('hoje', '2026-11-14'));
});

it('CA-080.2 opens the last used wallet', function () {
    $this->user->forceFill(['ultima_carteira_id' => $this->carteira->id])->save();

    $this->actingAs($this->user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('carteira.id', $this->carteira->id));
});

it('CA-080.3 opens wallet and month from the URL and remembers the wallet', function () {
    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id, 'mes' => '2027-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteira.id', $this->carteira->id)
            ->where('mes', '2027-03'));

    expect($this->user->fresh()->ultima_carteira_id)->toBe($this->carteira->id);
});

it('CA-080.4 forbids a wallet of another user in the URL', function () {
    $alheia = Carteira::factory()->create();

    $this->actingAs($this->user)->get(route('home', ['carteira' => $alheia->id]))->assertForbidden();

    expect($this->user->fresh()->ultima_carteira_id)->toBeNull();
});

it('CA-080.5 falls back to the current month for an invalid month', function (string $mes) {
    $this->actingAs($this->user)->get(route('home', ['mes' => $mes]))
        ->assertInertia(fn (Assert $page) => $page->where('mes', '2026-11'));
})->with(['2026-13', 'novembro', '2026-1']);

it('CA-081.1 CA-081.2 CA-081.3 computes the November balances', function () {
    exemploSaldos($this->user, $this->carteira, $this->categoria);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id, 'mes' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('saldos.mes.estimado', 150000)
            ->where('saldos.mes.atual', 300000)
            ->where('saldos.acumulado.estimado', 550000)
            ->where('saldos.acumulado.atual', 700000)
            ->where('saldos.receitas', 500000)
            ->where('saldos.despesas', 350000));
});

it('CA-081.4 computes the December balances', function () {
    exemploSaldos($this->user, $this->carteira, $this->categoria);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id, 'mes' => '2026-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('saldos.acumulado.estimado', 1050000)
            ->where('saldos.mes.estimado', 500000)
            ->where('saldos.mes.atual', 0));
});

it('CA-081.5 counts only the share of the user in a shared entry', function () {
    $autor = User::factory()->create();
    $lancamento = Lancamento::factory()->for($autor)->create(['valor' => 10000, 'data' => '2026-11-10', 'modo_rateio' => ModoRateio::Iguais]);
    Cota::factory()->for($lancamento)->create(['valor' => 5000]);
    Cota::factory()->for($lancamento)->create([
        'user_id' => $this->user->id,
        'carteira_id' => $this->carteira->id,
        'categoria_id' => $this->categoria->id,
        'valor' => 5000,
    ]);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('saldos.mes.estimado', -5000)
            ->where('lancamentos.despesa.avista.0.valor', 5000)
            ->where('lancamentos.despesa.avista.0.compartilhado', true)
            ->where('lancamentos.despesa.avista.0.edicao', null));
});

it('CA-081.6 ignores shares of the user in another wallet', function () {
    lancamentoComCota($this->user, $this->principal, $this->categoria, ['data' => '2026-11-10', 'valor' => 9900]);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('saldos.mes.estimado', 0)
            ->where('lancamentos.despesa.avista', []));
});

it('CA-081.7 CA-084.1 keeps balances when filtering and shows only the filtered type', function () {
    exemploSaldos($this->user, $this->carteira, $this->categoria);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id, 'tipo' => 'despesa']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('saldos.mes.estimado', 150000)
            ->where('saldos.receitas', 500000)
            ->where('lancamentos.receita.avista', [])
            ->has('lancamentos.despesa.avista', 2));
});

it('CA-083.1 splits the month in sections and subsections ordered by date and creation', function () {
    $parcelada = Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    $recorrente = Parcelamento::factory()->for($this->user)->recorrente()->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    $segundo = lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-12', 'descricao' => 'Segundo']);
    $this->travel(1)->minutes();
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-12', 'descricao' => 'Terceiro']);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-03', 'descricao' => 'Primeiro']);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-10', 'descricao' => 'TV'])
        ->forceFill(['parcelamento_id' => $parcelada->id, 'numero_parcela' => 3])->save();
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-10', 'descricao' => 'Aluguel'])
        ->forceFill(['parcelamento_id' => $recorrente->id, 'numero_parcela' => 1])->save();
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-05', 'tipo' => TipoLancamento::Receita, 'descricao' => 'Salário']);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-12-01', 'descricao' => 'Fora do mês']);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.receita.avista.0.descricao', 'Salário')
            ->where('lancamentos.despesa.avista', fn ($itens) => collect($itens)->pluck('descricao')->all() === ['Primeiro', 'Segundo', 'Terceiro'])
            ->where('lancamentos.despesa.parceladas.0.descricao', 'TV')
            ->where('lancamentos.despesa.recorrentes.0.descricao', 'Aluguel'));
});

it('CA-083.2 CA-083.3 shows indicators and highlights overdue pending shares', function () {
    $serie = Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id, 'total_parcelas' => 10]);
    $recorrente = Parcelamento::factory()->for($this->user)->recorrente()->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-10'])
        ->forceFill(['parcelamento_id' => $serie->id, 'numero_parcela' => 3, 'anexo_caminho' => 'anexos/x.pdf', 'anexo_nome' => 'x.pdf'])->save();
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-25'])
        ->forceFill(['parcelamento_id' => $recorrente->id, 'numero_parcela' => 1])->save();

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.despesa.parceladas.0.parcela', '3/10')
            ->where('lancamentos.despesa.parceladas.0.temAnexo', true)
            ->where('lancamentos.despesa.parceladas.0.vencido', true)
            ->where('lancamentos.despesa.recorrentes.0.recorrente', true)
            ->where('lancamentos.despesa.recorrentes.0.vencido', false));
});

it('CA-083.5 returns empty sections for a month without entries', function () {
    $this->actingAs($this->user)->get(route('home', ['mes' => '2030-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.receita', ['avista' => [], 'parceladas' => [], 'recorrentes' => [], 'grupos' => []])
            ->where('lancamentos.despesa', ['avista' => [], 'parceladas' => [], 'recorrentes' => [], 'grupos' => []]));
});

it('CA-084.2 CA-084.3 combines category, status and shared filters and echoes them', function () {
    $outraCategoria = Categoria::factory()->for($this->user)->create();
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-10', 'descricao' => 'Alvo'], ['executado' => true]);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-10', 'descricao' => 'Pendente']);
    lancamentoComCota($this->user, $this->carteira, $outraCategoria, ['data' => '2026-11-10', 'descricao' => 'Outra categoria'], ['executado' => true]);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-10', 'descricao' => 'Compartilhado', 'modo_rateio' => ModoRateio::Iguais], ['executado' => true]);

    $filtros = ['categoria' => $this->categoria->id, 'status' => 'executado', 'compartilhado' => 'nao'];

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id, ...$filtros]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.despesa.avista', fn ($itens) => collect($itens)->pluck('descricao')->all() === ['Alvo'])
            ->where('filtros', ['tipo' => null, ...$filtros]));
});

it('CA-082.1 CA-082.2 CA-082.4 shows the data of the month in the URL', function (string $mes, string $data) {
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => $data, 'descricao' => 'Do mês']);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-14', 'descricao' => 'Novembro']);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id, 'mes' => $mes]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mes', $mes)
            ->where('lancamentos.despesa.avista', fn ($itens) => collect($itens)->pluck('descricao')->contains('Do mês')));
})->with([
    'seguinte' => ['2026-12', '2026-12-03'],
    'anterior' => ['2026-10', '2026-10-03'],
    'virada de ano' => ['2027-01', '2027-01-03'],
    'mês atual selecionado' => ['2026-11', '2026-11-03'],
    'seletor' => ['2030-03', '2030-03-03'],
]);

it('RN-340 opens the current month without sending a Hoje navigation target', function () {
    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mes', '2026-11')
            ->missing('mesHoje'));
});

it('CA-085.2 sends what the form needs for its defaults', function () {
    $receita = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Receita]);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id, 'mes' => '2026-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteira.id', $this->carteira->id)
            ->where('mes', '2026-12')
            ->where('hoje', '2026-11-14')
            ->has('carteiras', 2)
            ->where('categorias', fn ($categorias) => collect($categorias)->firstWhere('id', $receita->id)['tipo'] === 'receita'));
});

it('CA-081.1 CA-083.1 includes entries dated on the last day of the month', function () {
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-30', 'tipo' => TipoLancamento::Receita, 'valor' => 10000, 'descricao' => 'Último dia']);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id, 'mes' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.receita.avista.0.descricao', 'Último dia')
            ->where('saldos.mes.estimado', 10000)
            ->where('saldos.acumulado.estimado', 10000)
            ->where('saldos.receitas', 10000));
});

it('CA-080.1 falls back to the default wallet when the wallet in the URL no longer exists', function () {
    $this->actingAs($this->user)->get(route('home', ['carteira' => 999999]))
        ->assertInertia(fn (Assert $page) => $page->where('carteira.id', $this->principal->id));

    expect($this->user->fresh()->ultima_carteira_id)->toBeNull();
});

it('CA-083.2 sends series data for installments and the split for shared entries', function () {
    $serie = Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-10'])
        ->forceFill(['parcelamento_id' => $serie->id, 'numero_parcela' => 1])->save();
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-11', 'modo_rateio' => ModoRateio::Iguais]);
    lancamentoComCota($this->user, $this->carteira, $this->categoria, ['data' => '2026-11-12']);

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.despesa.parceladas.0.serie.numero', 1)
            ->where('lancamentos.despesa.parceladas.0.serie.total', 10)
            ->where('lancamentos.despesa.avista.0.edicao.rateio.modo', 'iguais')
            ->whereNot('lancamentos.despesa.avista.1.edicao', null));
});

it('RNF-020 sends guests to the login page', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

it('CA-135.5 sends the icon of the share category in plain and grouped items', function () {
    $this->seed(CategoriasGlobaisSeeder::class);
    $mercado = Categoria::query()->globais()->where('nome', 'Mercado')->sole();
    lancamentoComCota($this->user, $this->carteira, $mercado, ['data' => '2026-11-10', 'descricao' => 'Feira']);
    $agrupada = lancamentoComCota($this->user, $this->carteira, $mercado, ['data' => '2026-11-11'])->cotas()->sole();
    $this->actingAs($this->user)->post(route('grupos.agrupar'), ['cotas' => [$agrupada->id], 'nome' => 'Fatura'])->assertSessionHasNoErrors();

    $this->actingAs($this->user)->get(route('home', ['carteira' => $this->carteira->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.despesa.avista.0.categoriaIcone', 'shopping-cart')
            ->where('lancamentos.despesa.grupos.0.itens.0.categoriaIcone', 'shopping-cart'));
});
