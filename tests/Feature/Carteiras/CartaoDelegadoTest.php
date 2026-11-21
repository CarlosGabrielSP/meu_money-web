<?php

use App\Actions\Cartoes\AtribuirFaturas;
use App\Enums\StatusMembro;
use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Módulo 19, stage 17.2 (RF-212, RF-213, RF-217): Carlos delegates his Nubank card (due day 10,
 * closing 10 days before, paid by Principal) to Ana. Principal itself is not delegated (RN-314).
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->dono = User::factory()->create(['name' => 'Carlos']);
    $this->membro = User::factory()->create(['name' => 'Ana']);
    $this->principal = Carteira::factory()->for($this->dono)->padrao()->create(['nome' => 'Principal']);
    $this->daAna = Carteira::factory()->for($this->membro)->padrao()->create(['nome' => 'Da Ana']);
    $this->categoriaDoDono = Categoria::factory()->for($this->dono)->create(['nome' => 'Feira', 'tipo' => TipoCategoria::Despesa]);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->dono->id, 'convidado_id' => $this->membro->id]);
    $this->nubank = cartaoDe($this->dono, 'Nubank', $this->principal);
    $this->delegacao = delegarCarteira($this->nubank, $this->membro);
});

/**
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosNoCartaoDelegado(array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'Cinema',
        'valor' => '80,00',
        'data' => '2026-11-20',
        'categoria_id' => test()->categoriaDoDono->id,
        'carteira_id' => test()->nubank->id,
        ...$dados,
    ];
}

/**
 * Props of the card screen as the user sees it.
 *
 * @param  array<string, mixed>  $parametros
 * @return array<string, mixed>
 */
function telaDoCartaoDelegado(User $user, array $parametros = []): array
{
    $props = [];
    test()->actingAs($user)->get(route('home', ['carteira' => test()->nubank->id, ...$parametros]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

it('CA-212.2 shows the owner card screen to the member under the owner name', function () {
    $this->actingAs($this->dono)->post(route('lancamentos.store'), dadosNoCartaoDelegado())->assertSessionHasNoErrors();

    $doMembro = telaDoCartaoDelegado($this->membro);
    $doDono = telaDoCartaoDelegado($this->dono);

    expect($doMembro['delegacao'])->toBe(['donoId' => $this->dono->id, 'donoNome' => 'Carlos'])
        ->and($doMembro['carteira']['id'])->toBe($this->nubank->id)
        ->and($doMembro['saldos'])->toBeNull()
        ->and(collect($doMembro['fatura'])->except('editavel')->all())->toBe(collect($doDono['fatura'])->except('editavel')->all())
        ->and($doMembro['lancamentos'])->toBe($doDono['lancamentos'])
        ->and($doMembro['destaques']['gastosDoMes'])->toBe($doDono['destaques']['gastosDoMes']);
});

it('CA-212.3 keeps the card out of the member own screen, report and last wallet', function () {
    telaDoCartaoDelegado($this->membro);

    expect($this->membro->refresh()->ultima_carteira_id)->toBeNull();

    $this->actingAs($this->membro)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteira.id', $this->daAna->id)
            ->where('carteiras', fn ($carteiras) => collect($carteiras)->pluck('id')->all() === [$this->daAna->id])
            ->where('destaques.saldosCarteiras', fn ($saldos) => ! collect($saldos)->has($this->nubank->id)));
    $this->actingAs($this->membro)->get(route('relatorio', ['carteira' => $this->nubank->id]))->assertForbidden();
});

it('CA-212.4 hides the invoice dates edit, the tour and the wallet balances and refuses the dates route', function () {
    $props = telaDoCartaoDelegado($this->membro, ['tutorial' => '1']);

    expect($props['fatura']['editavel'])->toBeFalse()
        ->and($props['mostrarTutorial'])->toBeFalse()
        ->and($props['destaques']['saldosCarteiras'])->toBeNull()
        ->and(telaDoCartaoDelegado($this->dono)['fatura']['editavel'])->toBeTrue();

    $this->actingAs($this->membro)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-29', 'vencimento' => '2026-12-09'])
        ->assertForbidden();
});

it('CA-212.5 forbids the member from changing, deleting or sharing the card', function (string $acao) {
    $resposta = match ($acao) {
        'editar' => $this->actingAs($this->membro)->put(route('carteiras.update', $this->nubank), [
            'nome' => 'Meu', 'tipo' => 'cartao', 'dia_vencimento' => 15, 'dias_fechamento' => 5,
        ]),
        'excluir' => $this->actingAs($this->membro)->delete(route('carteiras.destroy', $this->nubank)),
        'membros' => $this->actingAs($this->membro)->post(route('carteiras.membros.store', $this->nubank), ['user_id' => $this->dono->id]),
    };

    $resposta->assertForbidden();
    expect($this->nubank->refresh()->nome)->toBe('Nubank');
})->with(['editar', 'excluir', 'membros']);

it('CA-212.6 forbids the card without an accepted delegation of an active owner', function (string $caso) {
    match ($caso) {
        'pendente' => $this->delegacao->update(['status' => StatusMembro::Pendente]),
        'recusado' => $this->delegacao->update(['status' => StatusMembro::Recusado]),
        'ex-membro' => $this->delegacao->delete(),
        'dono desativado' => $this->dono->forceFill(['desativado_em' => now()])->save(),
    };

    $this->actingAs($this->membro)->get(route('home', ['carteira' => $this->nubank->id]))->assertForbidden();
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosNoCartaoDelegado())->assertSessionHasErrors('carteira_id');
})->with(['pendente', 'recusado', 'ex-membro', 'dono desativado']);

it('CA-213.1 creates the purchase for the owner in the invoice of its date', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosNoCartaoDelegado())->assertSessionHasNoErrors();

    $lancamento = Lancamento::query()->sole();
    $cota = $lancamento->cotas()->with('fatura')->sole();

    expect($lancamento->user_id)->toBe($this->dono->id)
        ->and($cota->user_id)->toBe($this->dono->id)
        ->and($cota->carteira_id)->toBe($this->nubank->id)
        ->and($cota->fatura->referencia->format('Y-m'))->toBe('2026-12');
});

it('CA-213.1 applies the owner validations of closed invoices to the member', function () {
    $fechada = lancamentoComCota($this->dono, $this->nubank, $this->categoriaDoDono, ['data' => '2026-10-20', 'valor' => 5000]);
    app(AtribuirFaturas::class)->porLancamentos([$fechada->id], permitirFechadas: true);

    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosNoCartaoDelegado(['data' => '2026-10-25']))
        ->assertSessionHasErrors(['data' => 'Essa data cai numa fatura fechada. Use uma data a partir de 01/11/2026.']);
    $this->actingAs($this->membro)->delete(route('lancamentos.destroy', $fechada))->assertSessionHasErrors('lancamento');

    expect(Lancamento::query()->pluck('id')->all())->toBe([$fechada->id]);
});

it('CA-213.2 edits, groups and deletes purchases of the card as the owner', function () {
    $compra = lancamentoComCota($this->dono, $this->nubank, $this->categoriaDoDono, ['data' => '2026-11-20', 'valor' => 5000]);
    $outra = lancamentoComCota($this->dono, $this->nubank, $this->categoriaDoDono, ['data' => '2026-11-21', 'valor' => 3000]);

    $this->actingAs($this->membro)->put(route('lancamentos.update', $compra), dadosNoCartaoDelegado(['descricao' => 'Teatro']))->assertSessionHasNoErrors();
    $this->actingAs($this->membro)->post(route('grupos.agrupar'), [
        'cotas' => [$compra->cotas()->value('id'), $outra->cotas()->value('id')], 'nome' => 'Lazer',
    ])->assertSessionHasNoErrors();

    expect($compra->refresh()->descricao)->toBe('Teatro')
        ->and($compra->cotas()->sole()->grupo->user_id)->toBe($this->dono->id);

    $this->actingAs($this->membro)->delete(route('lancamentos.destroy', $outra))->assertSessionHasNoErrors();

    expect(Lancamento::query()->whereKey($outra->id)->exists())->toBeFalse();
});

it('CA-213.3 offers the owner wallets and cards delegated to the member, with their card data', function () {
    delegarCarteira($this->principal, $this->membro);
    $outro = cartaoDe($this->dono, 'Inter', $this->principal);

    $props = telaDoCartaoDelegado($this->membro);

    expect(collect($props['carteiras'])->pluck('id')->all())->toBe([$this->principal->id, $this->nubank->id])
        ->and(array_keys((array) $props['cartoes']))->toBe([$this->nubank->id])
        ->and(collect(telaDoCartaoDelegado($this->dono)['carteiras'])->pluck('id')->all())->toContain($outro->id);
});

it('CA-213.3 refuses a card of the owner not delegated to the member', function () {
    $outro = cartaoDe($this->dono, 'Inter', $this->principal);

    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosNoCartaoDelegado(['carteira_id' => $outro->id]))
        ->assertSessionHasErrors('carteira_id');

    expect(Lancamento::query()->exists())->toBeFalse();
});

it('CA-213.3 moves an entry between a delegated wallet and the delegated card', function () {
    delegarCarteira($this->principal, $this->membro);
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosNoCartaoDelegado(['carteira_id' => $this->principal->id]))->assertSessionHasNoErrors();
    $lancamento = Lancamento::query()->sole();

    $this->actingAs($this->membro)->put(route('lancamentos.update', $lancamento), dadosNoCartaoDelegado())->assertSessionHasNoErrors();

    $cota = $lancamento->cotas()->sole();
    expect($cota->carteira_id)->toBe($this->nubank->id)
        ->and($cota->fatura_id)->not->toBeNull();
});

it('CA-213.4 CA-213.5 splits with an owner contact and with the member, each part in their own mirror', function () {
    $contato = User::factory()->create(['name' => 'Bia']);
    $daBia = Carteira::factory()->for($contato)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->dono->id, 'convidado_id' => $contato->id]);

    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosRateio($this->nubank, $this->categoriaDoDono, 'iguais', [
        ['user_id' => $this->dono->id], ['user_id' => $contato->id], ['user_id' => $this->membro->id],
    ], ['valor' => '90,00']))->assertSessionHasNoErrors();

    $lancamento = Lancamento::query()->sole();
    $espelhoDaBia = Carteira::query()->find($lancamento->cotas()->where('user_id', $contato->id)->value('carteira_id'));
    $espelhoDaAna = Carteira::query()->find($lancamento->cotas()->where('user_id', $this->membro->id)->value('carteira_id'));

    expect($lancamento->user_id)->toBe($this->dono->id)
        ->and($espelhoDaBia->user_id)->toBe($contato->id)
        ->and($espelhoDaBia->nome)->toBe('Nubank · Carlos')
        ->and($espelhoDaBia->cartao->cartao_origem_id)->toBe($this->nubank->id)
        ->and($espelhoDaAna->user_id)->toBe($this->membro->id)
        ->and($espelhoDaAna->cartao->cartao_origem_id)->toBe($this->nubank->id)
        ->and($daBia->id)->not->toBe($espelhoDaBia->id);

    $this->actingAs($contato)->get(route('home', ['carteira' => $espelhoDaBia->id]))
        ->assertInertia(fn (Assert $page) => $page->where('lancamentos.despesa.avista.0.registro', null));
});

it('CA-213.6 changes only installments in delegated wallets and cards and hides the whole series actions', function () {
    $outro = cartaoDe($this->dono, 'Inter', $this->principal);
    $this->actingAs($this->dono)->post(route('lancamentos.store'), dadosSerie($this->nubank, $this->categoriaDoDono, ['total_parcelas' => 3]))->assertSessionHasNoErrors();
    $serie = Parcelamento::query()->sole();
    $parcelas = $serie->lancamentos()->orderBy('numero_parcela')->get();
    Cota::query()->where('lancamento_id', $parcelas[2]->id)->update(['carteira_id' => $outro->id]);

    $this->actingAs($this->membro)->put(route('lancamentos.update', $parcelas[0]), [
        ...dadosNoCartaoDelegado(['descricao' => 'TV nova', 'valor' => '100,00', 'data' => $parcelas[0]->data->toDateString()]),
        'escopo' => 'todas',
    ])->assertSessionHasNoErrors();

    expect(Lancamento::query()->whereKey($parcelas[1]->id)->value('descricao'))->toBe('TV nova')
        ->and(Lancamento::query()->whereKey($parcelas[2]->id)->value('descricao'))->toBe('TV')
        ->and($serie->refresh()->descricao)->toBe('TV nova');

    $this->actingAs($this->membro)->post(route('parcelamentos.encerrar', $serie), ['data' => '2026-12-31'])->assertForbidden();
    $this->actingAs($this->membro)->patch(route('parcelamentos.total', $serie), ['total_parcelas' => 5])->assertForbidden();
});

it('CA-213.7 records the member on the card purchase and shows the initial on the owner card list', function () {
    $this->actingAs($this->membro)->post(route('lancamentos.store'), dadosNoCartaoDelegado())->assertSessionHasNoErrors();

    expect(Lancamento::query()->sole())
        ->criado_por_id->toBe($this->membro->id)
        ->criado_por_nome->toBe('Ana');

    $this->actingAs($this->dono)->get(route('home', ['carteira' => $this->nubank->id]))
        ->assertInertia(fn (Assert $page) => $page->where('lancamentos.despesa.avista.0.registro.lancadoPor', 'Ana'));
});

it('CA-217.1 opens the delegated card in the member simple mode', function () {
    $this->membro->forceFill(['modo_simples' => true])->save();

    $props = telaDoCartaoDelegado($this->membro);

    expect($props['fatura'])->not->toBeNull()
        ->and($props['delegacao']['donoNome'])->toBe('Carlos');
});
