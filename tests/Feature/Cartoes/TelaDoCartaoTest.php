<?php

use App\Actions\Cartoes\AtribuirFaturas;
use App\Actions\Cartoes\PagarFatura;
use App\Enums\CompetenciaFatura;
use App\Enums\ModoRateio;
use App\Models\Carteira;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    cenarioCartao();
});

/**
 * @param  array<string, mixed>  $parametros
 */
function inicioDe(Carteira $carteira, array $parametros = []): TestResponse
{
    return test()->actingAs(test()->user)->get(route('home', ['carteira' => $carteira->id, ...$parametros]));
}

function pagarNovembroCom(int $valor): void
{
    test()->pagamento->update(['valor' => $valor]);
}

it('CA-164.1 sums previous balance, purchases, payments and to pay of each invoice', function (string $mes, int $saldoAnterior, int $compras, int $pagamentos, int $aPagar) {
    inicioDe($this->nubank, ['mes' => $mes])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.saldoAnterior', $saldoAnterior)
        ->where('fatura.compras', $compras)
        ->where('fatura.pagamentos', $pagamentos)
        ->where('fatura.aPagar', $aPagar));
})->with([
    'November' => ['2026-11', 0, 30000, 30000, 0],
    'December' => ['2026-12', 0, 30000, 0, 30000],
    'January' => ['2027-01', 30000, 5000, 0, 35000],
]);

it('CA-164.2 CA-164.3 carries what is left, or the credit, to the next invoice', function (int $pago, int $novembro, int $anterior, int $dezembro) {
    pagarNovembroCom($pago);

    inicioDe($this->nubank, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page->where('fatura.aPagar', $novembro));
    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.saldoAnterior', $anterior)
        ->where('fatura.aPagar', $dezembro));
})->with([
    'partial payment' => [20000, 10000, 10000, 40000],
    'payment above' => [35000, -5000, -5000, 25000],
]);

it('CA-164.4 subtracts income in the card from to pay', function () {
    compraNoCartao('2026-11-08', 5000, $this->entrada, 'receita');

    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.receitas', 5000)
        ->where('fatura.aPagar', 25000));
});

it('CA-164.5 CA-167.6 sums only the user share moved by the user to the card, without status', function () {
    $autor = User::factory()->create();
    Carteira::factory()->for($autor)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $autor->id, 'convidado_id' => $this->user->id]);
    $lancamento = Lancamento::factory()->for($autor)->create(['data' => '2026-11-20', 'valor' => 16000, 'modo_rateio' => ModoRateio::Iguais]);
    Cota::factory()->for($lancamento)->create(['user_id' => $autor->id, 'valor' => 8000]);
    $minha = Cota::factory()->for($lancamento)->create(['user_id' => $this->user->id, 'carteira_id' => $this->principal->id, 'categoria_id' => $this->mercado->id, 'valor' => 8000, 'executado' => true]);

    $this->actingAs($this->user)
        ->put(route('cotas.update', $minha), ['carteira_id' => $this->nubank->id, 'categoria_id' => $this->mercado->id, 'executado' => true])
        ->assertSessionHasNoErrors();

    expect($minha->fresh())
        ->fatura_id->toBe(faturaDe($this->nubank, '2026-12')->id)
        ->executado->toBeFalse();

    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page->where('fatura.compras', 38000));
});

it('CA-164.6 CA-170.7 keeps the number of queries constant as invoices and cards grow', function () {
    $consultas = function (Carteira $carteira): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        inicioDe($carteira, ['mes' => '2026-12'])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $doCartao = $consultas($this->nubank);
    $daCarteira = $consultas($this->x);

    foreach (range(1, 27) as $meses) {
        compraNoCartao(CarbonImmutable::parse('2027-01-15')->addMonthsNoOverflow($meses)->toDateString(), 1000);
    }

    foreach (['Inter', 'C6'] as $nome) {
        $outro = cartaoDe($this->user, $nome, $this->x);
        compraNoCartao('2026-11-20', 1000, cartao: $outro);
    }

    expect($consultas($this->nubank))->toBe($doCartao)
        ->and($consultas($this->x))->toBe($daCarteira);
});

it('CA-167.1 has no status for a card share', function () {
    $cota = $this->compraNovembro->cotas()->sole();

    $this->actingAs($this->user)->patch(route('cotas.executado', $cota), ['executado' => true])->assertSessionHasErrors('executado');

    expect($cota->fresh()->executado)->toBeFalse();

    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('lancamentos.despesa.avista.0.cartao', true)
        ->where('lancamentos.despesa.avista.0.executado', false));
});

it('CA-167.2 keeps amount, date and wallet of a purchase in a closed invoice', function (Closure $mudanca) {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->compraOutubro), dadosCompra(['descricao' => 'Mercado outubro', 'valor' => '300,00', 'data' => '2026-10-20', ...$mudanca()]))
        ->assertSessionHasErrors();

    expect($this->compraOutubro->fresh()->only(['valor', 'data']))->toEqual(['valor' => 30000, 'data' => CarbonImmutable::parse('2026-10-20')])
        ->and($this->compraOutubro->cotas()->sole()->carteira_id)->toBe($this->nubank->id);
})->with([
    'amount' => [fn () => ['valor' => '310,00']],
    'date' => [fn () => ['data' => '2026-10-21']],
    'wallet' => [fn () => ['carteira_id' => test()->x->id]],
]);

it('CA-167.2 changes description and category and refuses deleting a purchase in a closed invoice', function () {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->compraOutubro), dadosCompra(['descricao' => 'Feira', 'valor' => '300,00', 'data' => '2026-10-20', 'categoria_id' => $this->lazer->id]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)->delete(route('lancamentos.destroy', $this->compraOutubro))->assertSessionHasErrors('lancamento');

    expect($this->compraOutubro->fresh()->descricao)->toBe('Feira')
        ->and($this->compraOutubro->cotas()->sole()->categoria_id)->toBe($this->lazer->id);
});

it('CA-167.3 changes only the installments of open and future invoices in a mass edit', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->nubank, $this->mercado, ['data' => '2026-10-10', 'valor' => '100,00', 'modo_valor' => 'parcela', 'total_parcelas' => 3]))
        ->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $primeira = Lancamento::query()->where('numero_parcela', 1)->sole();

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $primeira), dadosCompra(['descricao' => 'TV', 'valor' => '150,00', 'data' => '2026-10-10', 'escopo' => 'todas']))
        ->assertSessionHasNoErrors();

    expect(Lancamento::query()->where('parcelamento_id', $primeira->parcelamento_id)->orderBy('numero_parcela')->pluck('valor')->all())
        ->toBe([10000, 15000, 15000]);
});

it('CA-180.3 accepts sharing a card purchase with a contact in every mode, replacing CA-167.4 and CA-167.5 (RN-251)', function (string $modo, array $divisao) {
    $contato = User::factory()->create();
    Carteira::factory()->for($contato)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->user->id, 'convidado_id' => $contato->id]);
    $this->seed(CategoriasGlobaisSeeder::class);
    $participantes = [['user_id' => $this->user->id, ...$divisao[0]], ['user_id' => $contato->id, ...$divisao[1]]];

    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosRateio($this->nubank, $this->mercado, $modo, $participantes))
        ->assertSessionHasNoErrors();

    $cotaDoContato = $this->user->lancamentos()->where('descricao', 'Jantar')->sole()->cotas()->where('user_id', $contato->id)->sole();
    expect($cotaDoContato->carteira->cartao->cartao_origem_id)->toBe($this->nubank->id);
})->with([
    'iguais' => ['iguais', [[], []]],
    'percentual' => ['percentual', [['percentual' => '70'], ['percentual' => '30']]],
    'fixo' => ['fixo', [['valor' => 6000], ['valor' => 4000]]],
]);

it('CA-168.1 CA-168.2 moves a purchase between the card and a common wallet', function () {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->compraNovembro), dadosCompra(['descricao' => 'Cinema', 'valor' => '200,00', 'data' => '2026-11-05', 'carteira_id' => $this->x->id]))
        ->assertSessionHasNoErrors();

    expect($this->compraNovembro->cotas()->sole())->fatura_id->toBeNull()->executado->toBeTrue();

    $pendente = lancamentoComCota($this->user, $this->x, $this->mercado, ['data' => '2026-11-20', 'valor' => 4000, 'descricao' => 'Farmácia']);
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $pendente), dadosCompra(['descricao' => 'Farmácia', 'valor' => '40,00', 'data' => '2026-11-20']))
        ->assertSessionHasNoErrors();

    expect($pendente->cotas()->sole())->fatura_id->toBe(faturaDe($this->nubank, '2026-12')->id)->executado->toBeFalse();
});

it('CA-168.3 refuses moving a purchase of a closed invoice', function () {
    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $this->compraOutubro), dadosCompra(['descricao' => 'Mercado outubro', 'valor' => '300,00', 'data' => '2026-10-20', 'carteira_id' => $this->x->id]))
        ->assertSessionHasErrors('valor');

    expect($this->compraOutubro->cotas()->sole()->carteira_id)->toBe($this->nubank->id);
});

it('CA-168.4 puts each moved installment in the invoice of its own date', function () {
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->x, $this->mercado, ['data' => '2026-11-20', 'valor' => '100,00', 'modo_valor' => 'parcela', 'total_parcelas' => 3]))
        ->assertSessionHasNoErrors();
    $primeira = Lancamento::query()->where('numero_parcela', 1)->sole();

    $this->actingAs($this->user)
        ->put(route('lancamentos.update', $primeira), dadosCompra(['descricao' => 'TV', 'valor' => '100,00', 'data' => '2026-11-20', 'escopo' => 'proximas']))
        ->assertSessionHasNoErrors();

    $faturas = Cota::query()->whereIn('lancamento_id', Lancamento::query()->where('parcelamento_id', $primeira->parcelamento_id)->select('id'))
        ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')->orderBy('lancamentos.numero_parcela')->pluck('cotas.fatura_id')->all();

    expect($faturas)->toBe([faturaDe($this->nubank, '2026-12')->id, faturaDe($this->nubank, '2027-01')->id, faturaDe($this->nubank, '2027-02')->id]);
});

it('CA-169.1 sends the invoice card of the month', function () {
    pagarNovembroCom(30000);

    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos', null)
        ->where('fatura.aPagar', 30000)
        ->where('fatura.estado', 'aberta')
        ->where('fatura.inicio', '2026-11-01')
        ->where('fatura.fechamento', '2026-11-30')
        ->where('fatura.vencimento', '2026-12-10')
        ->where('fatura.saldoAnterior', 0)
        ->where('fatura.pagamentosLista', []));
});

it('CA-169.2 opens the card on the due month of the open invoice', function () {
    inicioDe($this->nubank)->assertInertia(fn (Assert $page) => $page->where('mes', '2026-12')->where('mesHoje', '2026-12'));
});

it('CA-169.3 lists the invoice purchases by purchase date, without the status filter', function () {
    inicioDe($this->nubank, ['mes' => '2026-12', 'status' => 'executado'])->assertInertia(fn (Assert $page) => $page
        ->where('filtros.status', null)
        ->where('lancamentos.despesa.avista', fn ($itens) => collect($itens)->pluck('data')->all() === ['2026-11-05', '2026-11-30']));
});

it('CA-169.4 hides overdue and upcoming and shows the invoice categories', function () {
    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('destaques.vencidas', null)
        ->where('destaques.proximas', null)
        ->where('destaques.gastosDoMes', fn ($gastos) => collect($gastos)->map(fn ($gasto) => [$gasto['nome'], $gasto['valor']])->all() === [['Lazer', 20000], ['Mercado', 10000]]));
});

it('RN-193 sends the date picker limits of the invoice', function () {
    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.limites', [
            'fechamentoMin' => '2026-11-14',
            'fechamentoMax' => '2026-12-30',
            'vencimentoMin' => '2026-11-11',
            'vencimentoMax' => '2026-12-31',
        ]));
});

it('RN-224 shows the purchases of the invoice on screen when its due date moved to the next month', function () {
    $inter = cartaoDe($this->user, 'Inter', $this->x, diaVencimento: 15, competencia: CompetenciaFatura::Fechamento);
    compraNoCartao('2026-11-20', 20000, $this->lazer, cartao: $inter);
    compraNoCartao('2026-12-10', 5000, $this->mercado, cartao: $inter);
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$inter, '2026-12']), ['fechamento' => '2026-12-05', 'vencimento' => '2027-01-05'])
        ->assertSessionHasNoErrors();

    $gastos = fn ($esperado) => fn ($lista) => collect($lista)->map(fn ($gasto) => [$gasto['nome'], $gasto['valor']])->all() === $esperado;

    inicioDe($inter, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('destaques.gastosDoMes', $gastos([['Lazer', 20000]])));
    inicioDe($inter, ['mes' => '2027-01'])->assertInertia(fn (Assert $page) => $page
        ->where('destaques.gastosDoMes', $gastos([['Mercado', 5000]])));
});

it('CA-169.5 shows the to pay of the month invoice in the card wallet tile', function (string $mes, int $valor) {
    inicioDe($this->nubank, ['mes' => $mes])->assertInertia(fn (Assert $page) => $page->where("destaques.saldosCarteiras.{$this->nubank->id}", $valor));
})->with([['2026-12', 30000], ['2026-11', 0]]);

it('CA-169.6 lists the payments of the invoice with their source', function () {
    inicioDe($this->nubank, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.pagamentosLista', [['id' => $this->pagamento->id, 'data' => '2026-11-10', 'valor' => 30000, 'origem' => 'X']])
        ->where('fatura.estado', 'fechada')
        ->where('fatura.editavel', false));
});

it('CA-169.7 shows default dates and the carried balance for a month without invoice', function () {
    inicioDe($this->nubank, ['mes' => '2027-03'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.id', null)
        ->where('fatura.fechamento', '2027-02-28')
        ->where('fatura.vencimento', '2027-03-10')
        ->where('fatura.aPagar', 35000)
        ->where('fatura.saldoAnterior', 35000)
        ->where('fatura.estado', 'futura')
        ->where('lancamentos.despesa.avista', []));
});

it('CA-170.1 CA-170.2 shows the linked wallet on a cash basis', function (string $mes, int $atual, int $despesas, int $aPagar, int $previsto) {
    inicioDe($this->x, ['mes' => $mes])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.acumulado.atual', $atual)
        ->where('saldos.despesas', $despesas)
        ->where('saldos.aPagar', $aPagar)
        ->where('saldos.acumulado.estimado', $previsto));
})->with([
    'November' => ['2026-11', 470000, 30000, 0, 470000],
    'December' => ['2026-12', 470000, 30000, 30000, 440000],
]);

it('CA-170.3 counts an early payment once', function () {
    app(PagarFatura::class)->handle($this->nubank, CarbonImmutable::parse('2026-12-01'), 10000, '2026-11-14');

    inicioDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.acumulado.atual', 460000)
        ->where('saldos.acumulado.estimado', 460000));
    inicioDe($this->x, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.aPagar', 20000)
        ->where('saldos.acumulado.estimado', 440000));
});

it('CA-170.4 adds card income to the linked wallet on the due date', function () {
    compraNoCartao('2026-11-08', 5000, $this->entrada, 'receita');

    inicioDe($this->x, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.receitas', 5000)
        ->where('saldos.mes.estimado', -25000)
        ->where('saldos.acumulado.estimado', 445000));
});

it('CA-170.5 counts the invoice purchases in the month expenses of the linked wallet', function (string $mes, array $esperado) {
    inicioDe($this->x, ['mes' => $mes])->assertInertia(fn (Assert $page) => $page
        ->where('destaques.gastosDoMes', fn ($gastos) => collect($gastos)->map(fn ($gasto) => [$gasto['nome'], $gasto['valor']])->all() === $esperado));
})->with([
    'November' => ['2026-11', [['Mercado', 30000]]],
    'December' => ['2026-12', [['Lazer', 20000], ['Mercado', 10000]]],
]);

it('CA-170.6 changes no wallet with an independent card', function () {
    $independente = cartaoDe($this->user, 'Independente');
    compraNoCartao('2026-11-20', 9900, cartao: $independente);
    app(PagarFatura::class)->handle($independente, CarbonImmutable::parse('2026-12-01'), 9900, '2026-11-14');

    inicioDe($this->principal, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.acumulado.atual', 0)
        ->where('saldos.acumulado.estimado', 0)
        ->where('faturasVinculadas', []));
    inicioDe($this->x, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page->where('saldos.acumulado.estimado', 440000));
});

it('CA-171.1 CA-171.3 shows one row per invoice in the linked wallet, with its items', function () {
    inicioDe($this->x, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->has('faturasVinculadas', 1)
        ->where('faturasVinculadas.0.nome', 'Nubank')
        ->where('faturasVinculadas.0.vencimento', '2026-12-10')
        ->where('faturasVinculadas.0.valor', 30000)
        ->where('faturasVinculadas.0.situacao', 'a_pagar')
        ->where('faturasVinculadas.0.mes', '2026-12')
        ->where('faturasVinculadas.0.itens', fn ($itens) => collect($itens)->pluck('descricao')->all() === ['Cinema', 'Mercado'])
        ->where('lancamentos.despesa.avista', []));
});

it('CA-171.2 shows the invoice situation', function (?int $pago, string $situacao) {
    if ($pago !== null) {
        pagarNovembroCom($pago);
    }

    inicioDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('faturasVinculadas.0.valor', 30000)
        ->where('faturasVinculadas.0.situacao', $situacao));
})->with([
    'settled' => [null, 'quitada'],
    'partial' => [20000, 'parcial'],
]);

it('CA-171.4 has no row without purchases and hides rows with a filter other than expense type', function () {
    inicioDe($this->x, ['mes' => '2027-03'])->assertInertia(fn (Assert $page) => $page->where('faturasVinculadas', []));
    inicioDe($this->x, ['mes' => '2026-12', 'categoria' => (string) $this->mercado->id])->assertInertia(fn (Assert $page) => $page->where('faturasVinculadas', []));
    inicioDe($this->x, ['mes' => '2026-12', 'tipo' => 'despesa'])->assertInertia(fn (Assert $page) => $page->has('faturasVinculadas', 1));
});

it('CA-174.1 shows a group in the card without aggregated status', function () {
    $grupo = Grupo::factory()->for($this->user)->create(['carteira_id' => $this->nubank->id, 'nome' => 'Viagem']);
    Cota::query()->whereIn('lancamento_id', [$this->compraNovembro->id, $this->compraFimNovembro->id])->update(['grupo_id' => $grupo->id]);

    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('lancamentos.despesa.grupos.0.nome', 'Viagem')
        ->where('lancamentos.despesa.grupos.0.quantidade', 2)
        ->where('lancamentos.despesa.grupos.0.valor', 30000)
        ->where('lancamentos.despesa.grupos.0.status', null));

    $this->actingAs($this->user)
        ->patch(route('grupos.executado', $grupo), ['executado' => true, 'cotas' => Cota::query()->where('grupo_id', $grupo->id)->pluck('id')->all()])
        ->assertSessionHasErrors('executado');
});

it('CA-176.1 creates a card in simple mode with the default closing and competence', function () {
    $this->user->update(['modo_simples' => true]);

    $this->actingAs($this->user)
        ->post(route('carteiras.store'), ['nome' => 'Inter', 'tipo' => 'cartao', 'dia_vencimento' => 5, 'carteira_vinculada_id' => $this->x->id])
        ->assertSessionHasNoErrors();

    expect($this->user->carteiras()->where('nome', 'Inter')->sole()->cartao)
        ->dias_fechamento->toBe(10)
        ->competencia->toBe(CompetenciaFatura::Fechamento);
});

it('CA-176.2 shows the card in the simple mode selector with its invoice and purchases', function () {
    $this->user->update(['modo_simples' => true]);

    inicioDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('carteiras', fn ($carteiras) => collect($carteiras)->contains(fn ($carteira) => $carteira['id'] === $this->nubank->id && $carteira['tipo'] === 'cartao'))
        ->where('fatura.aPagar', 30000)
        ->where('fatura.vencimento', '2026-12-10')
        ->where('lancamentos.despesa', fn ($itens) => collect($itens)->pluck('descricao')->all() === ['Cinema', 'Mercado']));
});

it('RN-200 keeps installments of a closed invoice when the total of installments goes down', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-05 10:00'));
    $this->actingAs($this->user)
        ->post(route('lancamentos.store'), dadosSerie($this->nubank, $this->mercado, ['data' => '2026-08-10', 'valor' => '100,00', 'modo_valor' => 'parcela', 'total_parcelas' => 4]))
        ->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $serie = $this->user->parcelamentos()->sole();

    $this->actingAs($this->user)
        ->patch(route('parcelamentos.total', $serie), ['total_parcelas' => 2])
        ->assertSessionHasErrors(['total_parcelas' => 'A parcela 3 já foi paga. O total não pode ficar abaixo dela.']);
    $this->actingAs($this->user)->patch(route('parcelamentos.total', $serie), ['total_parcelas' => 3])->assertSessionHasNoErrors();

    expect($serie->lancamentos()->orderBy('numero_parcela')->pluck('numero_parcela')->all())->toBe([1, 2, 3]);
});

it('RN-200 refuses leaving a split with the share in a closed invoice of the card', function () {
    $autor = User::factory()->create();
    Carteira::factory()->for($autor)->padrao()->create();
    $lancamento = Lancamento::factory()->for($autor)->create(['data' => '2026-10-20', 'valor' => 16000, 'modo_rateio' => ModoRateio::Iguais]);
    Cota::factory()->for($lancamento)->create(['user_id' => $autor->id, 'valor' => 8000]);
    $minha = Cota::factory()->for($lancamento)->create(['user_id' => $this->user->id, 'carteira_id' => $this->nubank->id, 'categoria_id' => $this->mercado->id, 'valor' => 8000]);
    app(AtribuirFaturas::class)->handle([$minha->id], permitirFechadas: true);

    $this->actingAs($this->user)
        ->delete(route('cotas.destroy', $minha))
        ->assertSessionHasErrors(['cota' => 'Fatura fechada: a cota não pode sair da divisão.']);

    expect($minha->fresh())->not->toBeNull();
});
