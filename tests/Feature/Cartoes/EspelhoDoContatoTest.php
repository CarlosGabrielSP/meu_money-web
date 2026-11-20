<?php

use App\Enums\OrigemQuitacao;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\Lancamento;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\RecebimentoContato;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Stage 15.3 of Módulo 17 (nota 06): the contact side of the mirror and the deletions, on the base
 * scenario.
 */
beforeEach(fn () => cenarioEspelho());

function saldoAtualDe(Carteira $carteira, string $mes = '2026-11'): int
{
    $saldo = 0;
    test()->actingAs($carteira->user)->get(route('home', ['carteira' => $carteira->id, 'mes' => $mes]))
        ->assertInertia(function (Assert $page) use (&$saldo) {
            $saldo = $page->toArray()['props']['saldos']['acumulado']['atual'];
        });

    return $saldo;
}

function excluirCarteira(Carteira $carteira, ?Carteira $destino = null): void
{
    test()->actingAs($carteira->user)
        ->delete(route('carteiras.destroy', $carteira), $destino === null ? ['modo' => 'excluir'] : ['modo' => 'mover', 'destino_id' => $destino->id])
        ->assertSessionHasNoErrors();
}

function excluirConta(User $user): void
{
    test()->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/');

    expect($user->fresh())->toBeNull();
}

it('CA-188.1 shows the mirror to the contact as a card of the author, without parts and date edits', function () {
    $espelho = espelhoDe($this->nubank);

    $this->actingAs($this->b)->get(route('home', ['carteira' => $espelho->id, 'mes' => '2026-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteira.nome', 'Nubank · A')
            ->where('carteira.autorDoEspelho', 'A')
            ->where('fatura.aPagar', 10000)
            ->where('fatura.fechamento', '2026-11-30')
            ->where('fatura.vencimento', '2026-12-10')
            ->where('fatura.editavel', false)
            ->where('fatura.partes', [])
            ->where('lancamentos.despesa.avista.0.descricao', 'Restaurante')
            ->where('lancamentos.despesa.avista.0.valor', 10000));
});

it('CA-188.2 shows the mirror invoice line on the linked wallet of the contact', function () {
    $this->actingAs($this->b)->get(route('home', ['carteira' => $this->z->id, 'mes' => '2026-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('faturasVinculadas.0.nome', 'Nubank · A')
            ->where('faturasVinculadas.0.valor', 10000)
            ->where('faturasVinculadas.0.situacao', 'a_pagar')
            ->where('saldos.despesas', 10000)
            ->where('saldos.acumulado.estimado', 290000));
});

it('CA-188.3 lets the contact change only the category of the mirror share', function () {
    $cota = cotaDeB($this->restaurante);
    $outra = Categoria::query()->whereNull('user_id')->whereKeyNot($this->lazer->id)->where('tipo', '!=', 'receita')->firstOrFail();

    $this->actingAs($this->b)
        ->put(route('cotas.update', $cota), ['carteira_id' => $cota->carteira_id, 'categoria_id' => $outra->id, 'executado' => false])
        ->assertSessionHasNoErrors();

    expect($cota->fresh()->categoria_id)->toBe($outra->id);

    $this->actingAs($this->b)
        ->put(route('lancamentos.update', $this->restaurante), dadosDeA(['descricao' => 'Restaurante', 'valor' => '300,00', 'data' => '2026-11-06'], comB: true))
        ->assertForbidden();

    expect($this->restaurante->fresh()->valor)->toBe(20000);
});

it('CA-182.10 refuses moving the share out of the mirror', function () {
    $cota = cotaDeB($this->restaurante);

    $this->actingAs($this->b)
        ->put(route('cotas.update', $cota), ['carteira_id' => $this->z->id, 'categoria_id' => $cota->categoria_id, 'executado' => true])
        ->assertSessionHasErrors(['carteira_id' => 'Este cartão tem compras divididas com A.']);

    expect($cota->fresh()->carteira_id)->toBe(espelhoDe($this->nubank)->id);
});

it('CA-188.4 CA-188.6 refuses leaving the split of a mirror share', function () {
    $cota = cotaDeB($this->restaurante);

    $this->actingAs($this->b)
        ->delete(route('cotas.destroy', $cota))
        ->assertSessionHasErrors(['cota' => 'Este cartão tem compras divididas com A.']);

    expect($cota->fresh())->not->toBeNull()
        ->and($this->restaurante->fresh()->modo_rateio)->not->toBeNull();
});

it('CA-188.5 lists the mirror as a card in the wallet selector of the simple mode', function () {
    $this->b->forceFill(['modo_simples' => true])->save();

    $this->actingAs($this->b)->get(route('home', ['carteira' => $this->z->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteiras', fn ($carteiras) => collect($carteiras)->contains(fn ($carteira) => $carteira['nome'] === 'Nubank · A' && $carteira['tipo'] === 'cartao')));
});

it('CA-188.7 leaves only the installments outside the mirror and keeps the contact in a card series model', function () {
    $this->actingAs($this->user)->post(route('lancamentos.store'), [
        ...dadosDeA(['descricao' => 'Curso', 'valor' => '90,00', 'data' => '2026-11-05'], comB: true),
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 3,
        'modo_valor' => 'parcela',
    ])->assertSessionHasNoErrors();
    $parcelas = Lancamento::query()->where('descricao', 'Curso')->orderBy('numero_parcela')->get();
    $serie = $parcelas[0]->parcelamento;

    $this->actingAs($this->user)->put(route('lancamentos.update', $parcelas[0]), [
        ...dadosDeA(['descricao' => 'Curso', 'valor' => '90,00', 'data' => '2026-11-05', 'carteira_id' => $this->x->id], comB: true),
        'escopo' => 'esta',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->b)
        ->delete(route('cotas.destroy', cotaDeB($parcelas[1])), ['escopo' => 'todas'])
        ->assertSessionHasErrors(['cota' => 'Este cartão tem compras divididas com A.']);

    $this->actingAs($this->b)
        ->delete(route('cotas.destroy', cotaDeB($parcelas[0])), ['escopo' => 'todas'])
        ->assertSessionHasNoErrors();

    expect($parcelas[0]->cotas()->where('user_id', $this->b->id)->exists())->toBeFalse()
        ->and(cotaDeB($parcelas[1])->carteira_id)->toBe(espelhoDe($this->nubank)->id)
        ->and(cotaDeB($parcelas[2])->carteira_id)->toBe(espelhoDe($this->nubank)->id)
        ->and($serie->cotas()->where('user_id', $this->b->id)->exists())->toBeTrue();
});

it('CA-182.7 deletes a mirror without parts together with its payment', function () {
    $espelho = espelhoDe($this->nubank);
    pagarNoEspelho('50,00', mes: '2026-11');
    $this->actingAs($this->user)->delete(route('lancamentos.destroy', $this->restaurante))->assertSessionHasNoErrors();

    excluirCarteira($espelho->fresh());

    expect(espelhoDe($this->nubank))->toBeNull()
        ->and(PagamentoFatura::query()->count())->toBe(0)
        ->and(saldoAtualDe($this->z))->toBe(300000);
});

it('RN-261 d rebuilds the distribution when the contact deletes a mirror with payments', function () {
    $espelho = espelhoDe($this->nubank);
    pagarNoEspelho('50,00', mes: '2026-11');
    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-05'], lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();
    expect(espelhoDe($this->nubank))->not->toBeNull();

    $this->actingAs($this->b)
        ->delete(route('carteiras.destroy', $espelho), ['modo' => 'mover', 'destino_id' => $this->z->id])
        ->assertSessionHasNoErrors();

    expect(espelhoDe($this->nubank))->toBeNull()
        ->and(PagamentoFatura::query()->count())->toBe(0)
        ->and(QuitacaoContato::query()->count())->toBe(0);
});

describe('author deletes the card (RF-190)', function () {
    beforeEach(function () {
        $this->itau = cartaoDe($this->user, 'Itaú', $this->x);
    });

    it('CA-190.1 moves the contact share, payment and settlement to the mirror of the destination card', function () {
        pagarNoEspelho('100,00');
        $espelhoAntigo = espelhoDe($this->nubank);

        excluirCarteira($this->nubank, $this->itau);

        $espelho = espelhoDe($this->itau);
        $cota = cotaDeB($this->restaurante);
        $pagamento = PagamentoFatura::query()->sole();
        $quitacao = QuitacaoContato::query()->sole();

        expect(Carteira::query()->find($espelhoAntigo->id))->toBeNull()
            ->and($espelho->nome)->toBe('Itaú · A')
            ->and($espelho->cartao->carteira_vinculada_id)->toBe($this->z->id)
            ->and($cota->carteira_id)->toBe($espelho->id)
            ->and($cota->fatura->referencia->toDateString())->toBe('2026-12-01')
            ->and($pagamento->fatura->carteira_id)->toBe($espelho->id)
            ->and($pagamento->fatura->referencia->toDateString())->toBe('2026-12-01')
            ->and($quitacao->origem)->toBe(OrigemQuitacao::Contato)
            ->and($quitacao->valor)->toBe(10000)
            ->and($quitacao->fatura->carteira_id)->toBe($this->itau->id)
            ->and($quitacao->fatura->referencia->toDateString())->toBe('2026-12-01')
            ->and($this->restaurante->fresh()->carteira_id)->toBe($this->itau->id);
    });

    it('CA-190.2 moves everything to the existing mirror of the destination card', function () {
        compraRateada($this->itau, 'Bar', '60,00', '2026-11-10');
        $existente = espelhoDe($this->itau);
        $vinculada = Carteira::factory()->for($this->b)->create(['nome' => 'W']);
        $existente->cartao->update(['carteira_vinculada_id' => $vinculada->id]);
        pagarNoEspelho('100,00');

        excluirCarteira($this->nubank, $this->itau);

        expect(Cartao::query()->whereNotNull('cartao_origem_id')->count())->toBe(1)
            ->and(cotaDeB($this->restaurante)->carteira_id)->toBe($existente->id)
            ->and($existente->fresh()->cartao->carteira_vinculada_id)->toBe($vinculada->id)
            ->and(PagamentoFatura::query()->sole()->fatura->carteira_id)->toBe($existente->id);
    });

    it('CA-190.3 turns the contact share into a common split when the card moves to a common wallet', function () {
        pagarNoEspelho('100,00');

        excluirCarteira($this->nubank, $this->x);

        $cota = cotaDeB($this->restaurante);

        expect($cota->carteira_id)->toBe($this->z->id)
            ->and($cota->executado)->toBeTrue()
            ->and($cota->fatura_id)->toBeNull()
            ->and($this->restaurante->cotas()->where('user_id', $this->user->id)->sole()->executado)->toBeTrue()
            ->and(espelhoDe($this->nubank))->toBeNull()
            ->and(PagamentoFatura::query()->count())->toBe(0)
            ->and(QuitacaoContato::query()->count())->toBe(0)
            ->and(saldoAtualDe($this->z))->toBe(290000);
    });

    it('CA-190.4 deletes the mirror with its payment in the cascade', function () {
        pagarNoEspelho('100,00');

        excluirCarteira($this->nubank);

        expect(Lancamento::query()->whereKey($this->restaurante->id)->exists())->toBeFalse()
            ->and(Cota::query()->where('user_id', $this->b->id)->count())->toBe(1)
            ->and(espelhoDe($this->nubank))->toBeNull()
            ->and(PagamentoFatura::query()->count())->toBe(0)
            ->and(QuitacaoContato::query()->count())->toBe(0)
            ->and(saldoAtualDe($this->z))->toBe(300000);
    });

    it('CA-190.4 deletes in the cascade also purchases of closed invoices and settled parts (RN-233, RN-236)', function () {
        pagarComB();
        $this->travelTo('2026-12-02 10:00');

        excluirCarteira($this->nubank);

        expect(Carteira::query()->find($this->nubank->id))->toBeNull()
            ->and(RecebimentoContato::query()->count() + QuitacaoContato::query()->count())->toBe(0);
    });

    it('CA-190.5 deletes the author payment, settlement and "a receber" when the card moves to another card', function () {
        pagarComB('200,00');

        excluirCarteira($this->nubank, $this->itau);

        expect(PagamentoFatura::query()->count())->toBe(0)
            ->and(QuitacaoContato::query()->count())->toBe(0)
            ->and(cotaDeB($this->restaurante)->carteira_id)->toBe(espelhoDe($this->itau)->id);
    });

    it('moves also purchases of closed invoices with settled parts (RN-233, RN-234)', function () {
        pagarNoEspelho('100,00');
        $this->travelTo('2026-12-02 10:00');

        excluirCarteira($this->nubank, $this->itau);

        expect(cotaDeB($this->restaurante)->carteira_id)->toBe(espelhoDe($this->itau)->id)
            ->and(QuitacaoContato::query()->sole()->fatura->carteira_id)->toBe($this->itau->id);
    });

    it('lists the contacts with a mirror for the delete dialog (nota 07)', function () {
        $c = User::factory()->create(['name' => 'C']);
        Contato::factory()->aceito()->create(['solicitante_id' => $this->user->id, 'convidado_id' => $c->id]);
        Carteira::factory()->for($c)->padrao()->create();
        salvarLancamento([
            'descricao' => 'Show',
            'rateio' => ['modo' => 'iguais', 'participantes' => [['user_id' => $this->user->id], ['user_id' => $c->id]]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get(route('carteiras.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('carteiras', fn ($carteiras) => collect($carteiras)->firstWhere('nome', 'Nubank')['cartao']['contatosDosEspelhos'] === ['B', 'C']));
    });
});

describe('contact deletes the mirror linked wallet (RF-191)', function () {
    beforeEach(function () {
        $this->w = Carteira::factory()->for($this->b)->create(['nome' => 'W']);
        $this->actingAs($this->b)
            ->put(route('carteiras.update', espelhoDe($this->nubank)), ['nome' => 'Nubank · A', 'cor' => 'blue', 'icone' => 'credit-card', 'carteira_vinculada_id' => $this->w->id])
            ->assertSessionHasNoErrors();
        pagarNoEspelho('100,00');
    });

    it('CA-191.1 links the mirror to the default wallet and undoes the settlement in the cascade', function () {
        excluirCarteira($this->w);

        $espelho = espelhoDe($this->nubank);

        expect($espelho->cartao->carteira_vinculada_id)->toBe($this->z->id)
            ->and(Fatura::query()->where('carteira_id', $espelho->id)->pluck('carteira_vinculada_id')->unique()->all())->toBe([$this->z->id])
            ->and(PagamentoFatura::query()->count())->toBe(0)
            ->and(QuitacaoContato::query()->count())->toBe(0);
    });

    it('CA-191.2 moves the mirror and its payment to the destination wallet', function () {
        excluirCarteira($this->w, $this->z);

        expect(espelhoDe($this->nubank)->cartao->carteira_vinculada_id)->toBe($this->z->id)
            ->and(PagamentoFatura::query()->sole()->carteira_id)->toBe($this->z->id)
            ->and(QuitacaoContato::query()->sole()->valor)->toBe(10000);
    });
});

it('rebuilds the contact distribution when the author payments leave with a deleted wallet (RN-241)', function () {
    $y = Carteira::factory()->for($this->user)->create(['nome' => 'Y']);
    $this->nubank->cartao->update(['carteira_vinculada_id' => $y->id]);
    Fatura::query()->where('carteira_id', $this->nubank->id)->update(['carteira_vinculada_id' => $y->id]);
    pagarComB('200,00');
    pagarNoEspelho('100,00');

    excluirCarteira($y);

    expect(QuitacaoContato::query()->get()->map(fn (QuitacaoContato $quitacao) => [$quitacao->origem->value, $quitacao->valor])->all())
        ->toBe([['contato', 10000]])
        ->and(RecebimentoContato::query()->count())->toBe(0);
});

describe('account deletion (RF-192)', function () {
    it('CA-192.1 turns the mirror into an own card of the contact when the author deletes the account', function () {
        $espelho = espelhoDe($this->nubank);
        pagarNoEspelho('100,00');

        excluirConta($this->user);

        $cartao = $espelho->fresh()->cartao;
        $cota = Cota::query()->where('user_id', $this->b->id)->where('carteira_id', $espelho->id)->sole();

        expect($cartao->cartao_origem_id)->toBeNull()
            ->and($cartao->dia_vencimento)->toBe(10)
            ->and($cartao->dias_fechamento)->toBe(10)
            ->and($cota->lancamento->user_id)->toBe($this->b->id)
            ->and($cota->lancamento->modo_rateio)->toBeNull()
            ->and($cota->fatura->carteira_id)->toBe($espelho->id)
            ->and(PagamentoFatura::query()->sole()->fatura->carteira_id)->toBe($espelho->id)
            ->and(QuitacaoContato::query()->count())->toBe(0);

        $this->actingAs($this->b)->get(route('home', ['carteira' => $espelho->id, 'mes' => '2026-12']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('carteira.autorDoEspelho', null)
                ->where('fatura.editavel', true));
    });

    it('CA-192.2 gives the contact share back to the author when the contact deletes the account', function () {
        pagarNoEspelho('100,00');

        excluirConta($this->b);

        expect($this->restaurante->cotas()->sole()->valor)->toBe(20000)
            ->and($this->restaurante->fresh()->modo_rateio)->toBeNull()
            ->and(QuitacaoContato::query()->count())->toBe(0)
            ->and(aPagarDoNubank('2026-12'))->toBe(30000);
    });

    it('CA-192.2 deletes the author settlements and "a receber" of the contact', function () {
        pagarComB('200,00');

        excluirConta($this->b);

        expect(QuitacaoContato::query()->count())->toBe(0)
            ->and(RecebimentoContato::query()->count())->toBe(0)
            ->and(PagamentoFatura::query()->sole()->valor)->toBe(30000)
            ->and(aPagarDoNubank('2026-12'))->toBe(0);
    });

    it('CA-192.3 keeps the mirror and the split when the author removes the contact', function () {
        $contato = Contato::query()->sole();

        $this->actingAs($this->user)->delete(route('contatos.destroy', $contato))->assertSessionHasNoErrors();

        expect(espelhoDe($this->nubank))->not->toBeNull()
            ->and(cotaDeB($this->restaurante)->carteira_id)->toBe(espelhoDe($this->nubank)->id);

        salvarLancamento(['descricao' => 'Novo'], comB: true)->assertSessionHasErrors();
    });

    it('CA-192.3 keeps the mirror and the split when the contact is deactivated', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.usuarios.desativar', $this->b))->assertSessionHasNoErrors();

        expect($this->b->fresh()->desativado_em)->not->toBeNull()
            ->and(espelhoDe($this->nubank))->not->toBeNull()
            ->and(cotaDeB($this->restaurante)->carteira_id)->toBe(espelhoDe($this->nubank)->id);

        salvarLancamento(['descricao' => 'Novo'], comB: true)->assertSessionHasErrors();
    });

    it('CA-192.4 creates the author share in the card when the contact of a purchase only for them deletes the account', function () {
        salvarLancamento([
            'descricao' => 'Presente',
            'valor' => '60,00',
            'data' => '2026-11-05',
            'rateio' => ['modo' => 'fixo', 'participantes' => [['user_id' => $this->b->id, 'valor' => '60,00']]],
        ])->assertSessionHasNoErrors();
        $presente = Lancamento::query()->where('descricao', 'Presente')->sole();

        excluirConta($this->b);

        $cota = $presente->cotas()->with('fatura', 'categoria')->sole();

        expect($cota->user_id)->toBe($this->user->id)
            ->and($cota->carteira_id)->toBe($this->nubank->id)
            ->and($cota->valor)->toBe(6000)
            ->and($cota->categoria->nome)->toBe('Outros')
            ->and($cota->executado)->toBeFalse()
            ->and($cota->fatura->referencia->toDateString())->toBe('2026-12-01')
            ->and($presente->fresh()->modo_rateio)->toBeNull();
    });
});
