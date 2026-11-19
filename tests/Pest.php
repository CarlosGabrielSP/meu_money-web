<?php

use App\Actions\Cartoes\AtribuirFaturas;
use App\Actions\Cartoes\PagarFatura;
use App\Enums\CompetenciaFatura;
use App\Enums\IconeCarteira;
use App\Enums\TipoCarteira;
use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A simple entry of the user with its single share in the given wallet and category (DEC-017).
 *
 * @param  array<string, mixed>  $lancamento
 * @param  array<string, mixed>  $cota
 */
function lancamentoComCota(User $user, Carteira $carteira, Categoria $categoria, array $lancamento = [], array $cota = []): Lancamento
{
    $criado = Lancamento::factory()->for($user)->create($lancamento);
    Cota::factory()->for($criado)->create([
        'carteira_id' => $carteira->id,
        'categoria_id' => $categoria->id,
        'executado_em' => ($cota['executado'] ?? false) ? now() : null,
        ...$cota,
    ]);

    return $criado;
}

/**
 * Form data of a new series (RF-050, RF-051).
 *
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosSerie(Carteira $carteira, Categoria $categoria, array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'TV',
        'valor' => '1.000,00',
        'data' => '2026-12-05',
        'categoria_id' => $categoria->id,
        'carteira_id' => $carteira->id,
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 10,
        'modo_valor' => 'total',
        ...$dados,
    ];
}

/**
 * @return list<array{numero: int, data: string, valor: int, executado: bool}>
 */
function parcelasDaSerie(Parcelamento $serie): array
{
    return $serie->lancamentos()->with('cotas')->orderBy('numero_parcela')->get()
        ->map(fn (Lancamento $parcela) => [
            'numero' => $parcela->numero_parcela,
            'data' => $parcela->data->toDateString(),
            'valor' => $parcela->valor,
            'executado' => $parcela->cotas->sole()->executado,
        ])->all();
}

/**
 * @param  list<array<string, mixed>>  $participantes
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosRateio(Carteira $carteira, Categoria $categoria, string $modo, array $participantes, array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'Jantar',
        'valor' => '100,00',
        'data' => '2026-11-20',
        'categoria_id' => $categoria->id,
        'carteira_id' => $carteira->id,
        'rateio' => ['modo' => $modo, 'participantes' => $participantes],
        ...$dados,
    ];
}

/**
 * @return array<int, int> share amount by user id
 */
function valoresDasCotas(Lancamento $lancamento): array
{
    return $lancamento->cotas()->orderBy('id')->pluck('valor', 'user_id')->all();
}

/**
 * Sample data of RF-081 in wallet X (also used by RF-130, RF-131 and RF-136).
 */
function exemploSaldos(User $user, Carteira $carteira, Categoria $categoria): void
{
    foreach ([
        ['2026-10-05', 'receita', 500000, true],
        ['2026-10-20', 'despesa', 100000, true],
        ['2026-11-05', 'receita', 500000, true],
        ['2026-11-10', 'despesa', 200000, true],
        ['2026-11-25', 'despesa', 150000, false],
        ['2026-12-05', 'receita', 500000, false],
    ] as [$data, $tipo, $valor, $executado]) {
        lancamentoComCota($user, $carteira, $categoria, ['data' => $data, 'tipo' => $tipo, 'valor' => $valor], ['executado' => $executado]);
    }
}

/**
 * Base scenario of Módulo 16 (RF-160 to RF-176), with today = 2026-11-14: wallet X with income of
 * 5000,00 on 2026-11-05; card Nubank (due day 10, closing 10 days before, paid by X) with purchases
 * on 2026-10-20 (Mercado 300,00), 2026-11-05 (Lazer 200,00), 2026-11-30 (Mercado 100,00) and
 * 2026-12-01 (Lazer 50,00); a payment of 300,00 on 2026-11-10 in the November invoice, from X.
 */
function cenarioCartao(bool $comPagamento = true): void
{
    $teste = test();
    $teste->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $teste->user = User::factory()->create();
    $teste->principal = Carteira::factory()->for($teste->user)->padrao()->create(['nome' => 'Principal']);
    $teste->x = Carteira::factory()->for($teste->user)->create(['nome' => 'X']);
    $teste->mercado = Categoria::factory()->for($teste->user)->create(['nome' => 'Mercado']);
    $teste->lazer = Categoria::factory()->for($teste->user)->create(['nome' => 'Lazer']);
    $teste->entrada = Categoria::factory()->for($teste->user)->create(['nome' => 'Entrada', 'tipo' => TipoCategoria::Ambos]);

    lancamentoComCota($teste->user, $teste->x, $teste->entrada, ['data' => '2026-11-05', 'tipo' => 'receita', 'valor' => 500000], ['executado' => true]);

    $teste->nubank = cartaoDe($teste->user, 'Nubank', $teste->x);
    $teste->compraOutubro = compraNoCartao('2026-10-20', 30000, $teste->mercado, descricao: 'Mercado outubro');
    $teste->compraNovembro = compraNoCartao('2026-11-05', 20000, $teste->lazer, descricao: 'Cinema');
    $teste->compraFimNovembro = compraNoCartao('2026-11-30', 10000, $teste->mercado, descricao: 'Mercado');
    $teste->compraDezembro = compraNoCartao('2026-12-01', 5000, $teste->lazer, descricao: 'Bar');

    if ($comPagamento) {
        $teste->pagamento = app(PagarFatura::class)->handle($teste->nubank, CarbonImmutable::parse('2026-11-01'), 30000, '2026-11-10');
    }
}

/**
 * A card of the user (RN-190, RN-191). Módulo 16 names invoices by the due month (RN-253), so that is
 * the default here.
 */
function cartaoDe(User $user, string $nome, ?Carteira $vinculada = null, int $diaVencimento = 10, int $diasFechamento = 10, CompetenciaFatura $competencia = CompetenciaFatura::Vencimento): Carteira
{
    $cartao = Carteira::factory()->for($user)->create(['nome' => $nome, 'tipo' => TipoCarteira::Cartao, 'icone' => IconeCarteira::CreditCard]);
    $cartao->cartao()->create(['dia_vencimento' => $diaVencimento, 'dias_fechamento' => $diasFechamento, 'competencia' => $competencia, 'carteira_vinculada_id' => $vinculada?->id]);

    return $cartao;
}

/**
 * A purchase (or income) of the scenario user in the card, already in the invoice of its date, even
 * a closed one (RN-192).
 */
function compraNoCartao(string $data, int $valor, ?Categoria $categoria = null, string $tipo = 'despesa', ?Carteira $cartao = null, string $descricao = 'Compra'): Lancamento
{
    $teste = test();
    $lancamento = lancamentoComCota(
        $teste->user,
        $cartao ?? $teste->nubank,
        $categoria ?? $teste->mercado,
        ['data' => $data, 'tipo' => $tipo, 'valor' => $valor, 'descricao' => $descricao],
    );
    app(AtribuirFaturas::class)->porLancamentos([$lancamento->id], permitirFechadas: true);

    return $lancamento;
}

/**
 * The stored invoice of the card due in the month ("Y-m").
 */
function faturaDe(Carteira $cartao, string $mes): Fatura
{
    return Fatura::query()->where('carteira_id', $cartao->id)->whereDate('referencia', "{$mes}-01")->sole();
}

/**
 * Form data of a purchase in the Nubank card of the card scenario (RF-167).
 *
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosCompra(array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'Cinema',
        'valor' => '50,00',
        'data' => '2026-11-20',
        'categoria_id' => test()->mercado->id,
        'carteira_id' => test()->nubank->id,
        ...$dados,
    ];
}
