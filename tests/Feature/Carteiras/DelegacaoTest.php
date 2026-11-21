<?php

use App\Actions\Usuarios\DesativarUsuario;
use App\Actions\Usuarios\ExcluirConta;
use App\Enums\StatusMembro;
use App\Models\Carteira;
use App\Models\CarteiraMembro;
use App\Models\Contato;
use App\Models\User;
use App\Notifications\ConviteMembroNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->dono = User::factory()->create(['name' => 'Carlos']);
    $this->membro = User::factory()->create(['name' => 'Ana']);
    $this->principal = Carteira::factory()->for($this->dono)->padrao()->create(['nome' => 'Principal']);
    Carteira::factory()->for($this->membro)->padrao()->create();
    $this->contato = Contato::factory()->aceito()->create(['solicitante_id' => $this->dono->id, 'convidado_id' => $this->membro->id]);
});

function convidarMembro(User $dono, Carteira $carteira, User $convidado)
{
    return test()->actingAs($dono)->post(route('carteiras.membros.store', $carteira), ['user_id' => $convidado->id]);
}

function membroDe(Carteira $carteira, User $user, string $estado = 'aceito'): CarteiraMembro
{
    return CarteiraMembro::factory()
        ->state(['status' => StatusMembro::from($estado), 'respondido_em' => $estado === 'pendente' ? null : now()])
        ->create(['carteira_id' => $carteira->id, 'user_id' => $user->id]);
}

it('CA-200.1 invites an accepted contact as a pending member and emails the invitation', function () {
    convidarMembro($this->dono, $this->principal, $this->membro)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Convite enviado.');

    expect(CarteiraMembro::query()->sole())
        ->carteira_id->toBe($this->principal->id)
        ->user_id->toBe($this->membro->id)
        ->status->toBe(StatusMembro::Pendente);
    Notification::assertSentTo($this->membro, ConviteMembroNotification::class, function (ConviteMembroNotification $notificacao) {
        $email = $notificacao->toMail($this->membro);

        return $email->subject === 'Convite para a carteira Principal no '.config('app.name')
            && $email->actionUrl === route('carteiras.index')
            && in_array('Carlos convidou você para lançar na carteira Principal em nome dele. O saldo continua só de Carlos.', $email->introLines, true);
    });
});

it('CA-200.2 rejects a user who is not an active accepted contact, or the owner', function (string $caso) {
    $convidado = match ($caso) {
        'sem contato' => User::factory()->create(),
        'contato pendente' => tap(User::factory()->create(), fn (User $user) => Contato::factory()->create(['solicitante_id' => $this->dono->id, 'convidado_id' => $user->id])),
        'desativado' => tap($this->membro, fn (User $user) => $user->forceFill(['desativado_em' => now()])->save()),
        'o próprio dono' => $this->dono,
    };

    convidarMembro($this->dono, $this->principal, $convidado)->assertSessionHasErrors(['user_id' => 'Escolha um dos seus contatos.']);

    expect(CarteiraMembro::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['sem contato', 'contato pendente', 'desativado', 'o próprio dono']);

it('CA-211.2 rejects delegating a mirror', function () {
    $nubank = cartaoDe($this->dono, 'Nubank', $this->principal);
    $espelho = cartaoDe($this->membro, 'Nubank · Carlos');
    $espelho->cartao->forceFill(['cartao_origem_id' => $nubank->id])->save();

    convidarMembro($this->membro, $espelho, $this->dono)->assertSessionHasErrors(['carteira' => 'Espelho não pode ser delegado.']);

    expect(CarteiraMembro::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('CA-200.4 RN-287 creates nothing for a member or a pending invitation', function (string $estado) {
    membroDe($this->principal, $this->membro, $estado);

    convidarMembro($this->dono, $this->principal, $this->membro)
        ->assertInertiaFlash('toast.message', 'Ana já é membro ou tem convite pendente.');

    expect(CarteiraMembro::query()->sole()->status->value)->toBe($estado);
    Notification::assertNothingSent();
})->with(['aceito', 'pendente']);

it('CA-200.2 "Convidar de novo" for a refused contact who was deactivated shows the contact error', function () {
    membroDe($this->principal, $this->membro, 'recusado');
    app(DesativarUsuario::class)->handle($this->membro);

    convidarMembro($this->dono, $this->principal, $this->membro)->assertSessionHasErrors(['user_id' => 'Escolha um dos seus contatos.']);

    expect(CarteiraMembro::query()->sole()->status)->toBe(StatusMembro::Recusado);
});

it('CA-200.5 reopens a refused invitation and emails again', function () {
    $registro = membroDe($this->principal, $this->membro, 'recusado');

    convidarMembro($this->dono, $this->principal, $this->membro)->assertInertiaFlash('toast.message', 'Convite enviado.');

    expect(CarteiraMembro::query()->sole())
        ->id->toBe($registro->id)
        ->status->toBe(StatusMembro::Pendente)
        ->respondido_em->toBeNull();
    Notification::assertSentTo($this->membro, ConviteMembroNotification::class);
});

it('CA-200.6 forbids inviting to a wallet the user does not own, even as a member', function () {
    membroDe($this->principal, $this->membro);
    $outro = User::factory()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->membro->id, 'convidado_id' => $outro->id]);

    convidarMembro($this->membro, $this->principal, $outro)->assertForbidden();

    expect(CarteiraMembro::query()->count())->toBe(1);
});

it('CA-201.1 CA-201.3 accepts an invitation, which leaves the counter and joins "Delegadas a mim"', function () {
    $convite = membroDe($this->principal, $this->membro, 'pendente');

    $this->actingAs($this->membro)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contadores.membros', 1)
            ->where('delegadas.convites.0', ['id' => $convite->id, 'carteiraId' => $this->principal->id, 'nome' => 'Principal', 'cor' => 'blue', 'icone' => 'wallet', 'donoId' => $this->dono->id, 'dono' => 'Carlos'])
            ->has('delegadas.carteiras', 0));

    $this->actingAs($this->membro)->post(route('membros.aceitar', $convite))->assertInertiaFlash('toast.message', 'Convite aceito.');

    expect($convite->fresh())->status->toBe(StatusMembro::Aceito)->respondido_em->not->toBeNull();
    $this->actingAs($this->membro)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contadores.membros', 0)
            ->has('delegadas.convites', 0)
            ->where('delegadas.carteiras.0.carteiraId', $this->principal->id));
});

it('CA-201.2 refuses an invitation, which hides the wallet', function () {
    $convite = membroDe($this->principal, $this->membro, 'pendente');

    $this->actingAs($this->membro)->post(route('membros.recusar', $convite))->assertInertiaFlash('toast.message', 'Convite recusado.');

    expect($convite->fresh()->status)->toBe(StatusMembro::Recusado);
    $this->actingAs($this->membro)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page->has('delegadas.convites', 0)->has('delegadas.carteiras', 0));
});

it('CA-201.4 only the invited user answers a pending invitation', function () {
    $convite = membroDe($this->principal, $this->membro, 'pendente');
    $aceito = membroDe(Carteira::factory()->for($this->dono)->create(), $this->membro);

    $this->actingAs($this->dono)->post(route('membros.aceitar', $convite))->assertForbidden();
    $this->actingAs($this->dono)->post(route('membros.recusar', $convite))->assertForbidden();
    $this->actingAs($this->membro)->post(route('membros.recusar', $aceito))->assertForbidden();

    expect($convite->fresh()->status)->toBe(StatusMembro::Pendente);
});

it('RN-288 hides the invitations of a deactivated owner and brings them back on reactivation', function () {
    $convite = membroDe($this->principal, $this->membro, 'pendente');
    membroDe(Carteira::factory()->for($this->dono)->create(), $this->membro);
    $this->dono->forceFill(['desativado_em' => now()])->save();

    $this->actingAs($this->membro)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page->where('contadores.membros', 0)->has('delegadas.convites', 0)->has('delegadas.carteiras', 0));
    $this->actingAs($this->membro)->post(route('membros.aceitar', $convite))->assertForbidden();

    $this->dono->forceFill(['desativado_em' => null])->save();

    $this->actingAs($this->membro)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page->where('contadores.membros', 1)->has('delegadas.carteiras', 1));
});

it('CA-202.1 the owner cancels a pending invitation but not a refused one', function () {
    $pendente = membroDe($this->principal, $this->membro, 'pendente');
    $recusado = membroDe(Carteira::factory()->for($this->dono)->create(), $this->membro, 'recusado');

    $this->actingAs($this->dono)->delete(route('membros.destroy', $pendente))->assertInertiaFlash('toast.message', 'Convite cancelado.');
    $this->actingAs($this->dono)->delete(route('membros.destroy', $recusado))->assertForbidden();
    $this->actingAs($this->membro)->delete(route('membros.destroy', $recusado))->assertForbidden();

    expect(CarteiraMembro::query()->pluck('id')->all())->toBe([$recusado->id]);
});

it('CA-202.2 the owner removes a member', function () {
    $registro = membroDe($this->principal, $this->membro);

    $this->actingAs($this->dono)->delete(route('membros.destroy', $registro))->assertInertiaFlash('toast.message', 'Membro removido.');

    expect(CarteiraMembro::query()->count())->toBe(0);
});

it('CA-202.3 the member leaves; nobody else removes or leaves for them', function () {
    $registro = membroDe($this->principal, $this->membro);
    $pendente = membroDe(Carteira::factory()->for($this->dono)->create(), $this->membro, 'pendente');

    $this->actingAs(User::factory()->create())->delete(route('membros.destroy', $registro))->assertForbidden();
    $this->actingAs($this->membro)->delete(route('membros.destroy', $pendente))->assertForbidden();

    $this->actingAs($this->membro)->delete(route('membros.destroy', $registro))->assertInertiaFlash('toast.message', 'Você saiu de Principal.');

    expect(CarteiraMembro::query()->pluck('id')->all())->toBe([$pendente->id]);
});

it('lists members accepted, pending and refused, by name, and the owner contacts for the invitation', function () {
    $bruno = User::factory()->create(['name' => 'Bruno']);
    $dani = User::factory()->create(['name' => 'Dani']);
    foreach ([$bruno, $dani] as $user) {
        Contato::factory()->aceito()->create(['solicitante_id' => $this->dono->id, 'convidado_id' => $user->id]);
    }
    membroDe($this->principal, $dani, 'pendente');
    membroDe($this->principal, $bruno, 'recusado');
    membroDe($this->principal, $this->membro);
    $this->membro->forceFill(['desativado_em' => now()])->save();

    $this->actingAs($this->dono)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteiras.0.membros', fn ($membros) => collect($membros)->map(fn ($membro) => [$membro['nome'], $membro['status']])->all() === [
                ['Ana', 'aceito'], ['Dani', 'pendente'], ['Bruno', 'recusado'],
            ])
            ->where('contatos', [['id' => $bruno->id, 'nome' => 'Bruno'], ['id' => $dani->id, 'nome' => 'Dani']]));
});

it('CA-209.1 deleting the wallet, moving or in cascade, or the owner account deletes its members', function () {
    membroDe($this->principal, $this->membro);
    $movida = Carteira::factory()->for($this->dono)->create();
    $excluida = Carteira::factory()->for($this->dono)->create();
    membroDe($movida, $this->membro);
    membroDe($excluida, $this->membro);

    $this->actingAs($this->dono)->delete(route('carteiras.destroy', $movida), ['modo' => 'mover', 'destino_id' => $this->principal->id])->assertSessionHasNoErrors();
    $this->actingAs($this->dono)->delete(route('carteiras.destroy', $excluida), ['modo' => 'excluir'])->assertSessionHasNoErrors();

    expect(CarteiraMembro::query()->pluck('carteira_id')->all())->toBe([$this->principal->id]);

    app(ExcluirConta::class)->handle($this->dono);

    expect(CarteiraMembro::query()->count())->toBe(0);
});

it('CA-209.2 deleting the member account deletes the delegations', function () {
    membroDe($this->principal, $this->membro);

    app(ExcluirConta::class)->handle($this->membro);

    expect(CarteiraMembro::query()->count())->toBe(0);
});

it('CA-209.3 removing the contact deletes delegations and invitations between the two, both ways', function () {
    membroDe($this->principal, $this->membro);
    $carteiraDaAna = Carteira::factory()->for($this->membro)->create();
    membroDe($carteiraDaAna, $this->dono, 'pendente');
    $terceiro = User::factory()->create();
    $outra = membroDe($this->principal, $terceiro);

    $this->actingAs($this->membro)->delete(route('contatos.destroy', $this->contato))->assertSessionHasNoErrors();

    expect(CarteiraMembro::query()->pluck('id')->all())->toBe([$outra->id]);
});

it('CA-209.4 keeps the delegation of a deactivated member', function () {
    $registro = membroDe($this->principal, $this->membro);

    app(DesativarUsuario::class)->handle($this->membro);

    expect($registro->fresh())->not->toBeNull();
});

it('CA-211.1 invites a contact to an own card and emails a card invitation', function (bool $vinculado) {
    $nubank = cartaoDe($this->dono, 'Nubank', $vinculado ? $this->principal : null);

    convidarMembro($this->dono, $nubank, $this->membro)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Convite enviado.');

    expect(CarteiraMembro::query()->sole())
        ->carteira_id->toBe($nubank->id)
        ->status->toBe(StatusMembro::Pendente);
    Notification::assertSentTo($this->membro, ConviteMembroNotification::class, function (ConviteMembroNotification $notificacao) {
        $email = $notificacao->toMail($this->membro);

        return $email->subject === 'Convite para o cartão Nubank no '.config('app.name')
            && $email->actionUrl === route('carteiras.index')
            && in_array('Carlos convidou você para lançar no cartão Nubank em nome dele. A fatura continua só de Carlos.', $email->introLines, true);
    });
})->with(['vinculado' => true, 'independente' => false]);

it('CA-211.3 delegating the card and its linked wallet are independent', function () {
    $nubank = cartaoDe($this->dono, 'Nubank', $this->principal);
    membroDe($this->principal, $this->membro);

    convidarMembro($this->dono, $nubank, $this->membro)->assertSessionHasNoErrors();

    expect(CarteiraMembro::query()->where('carteira_id', $nubank->id)->sole()->status)->toBe(StatusMembro::Pendente)
        ->and(CarteiraMembro::query()->where('carteira_id', $this->principal->id)->sole()->status)->toBe(StatusMembro::Aceito);
});

it('CA-211.5 CA-212.1 the member accepts a card invitation and the card joins "Delegadas a mim" by name', function () {
    $nubank = cartaoDe($this->dono, 'Nubank', $this->principal);
    $convite = membroDe($nubank, $this->membro, 'pendente');
    membroDe($this->principal, $this->membro);

    $this->actingAs($this->membro)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contadores.membros', 1)
            ->where('delegadas.convites.0', ['id' => $convite->id, 'carteiraId' => $nubank->id, 'nome' => 'Nubank', 'cor' => $nubank->cor->value, 'icone' => 'credit-card', 'donoId' => $this->dono->id, 'dono' => 'Carlos']));

    $this->actingAs($this->membro)->post(route('membros.aceitar', $convite))->assertInertiaFlash('toast.message', 'Convite aceito.');

    $this->actingAs($this->membro)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('delegadas.carteiras', fn ($carteiras) => collect($carteiras)->pluck('nome')->all() === ['Nubank', 'Principal']));
});

it('CA-211.5 the owner lists, cancels and removes card members; a mirror lists none', function () {
    $nubank = cartaoDe($this->dono, 'Nubank', $this->principal);
    $membro = membroDe($nubank, $this->membro);
    $espelho = cartaoDe($this->dono, 'Itaú · Ana');
    $espelho->cartao->forceFill(['cartao_origem_id' => cartaoDe($this->membro, 'Itaú')->id])->save();
    membroDe($espelho, $this->membro);

    $this->actingAs($this->dono)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteiras', fn ($carteiras) => collect($carteiras)->firstWhere('nome', 'Nubank')['membros'][0]['nome'] === 'Ana'
                && collect($carteiras)->firstWhere('nome', 'Itaú · Ana')['membros'] === []));

    $this->actingAs($this->dono)->delete(route('membros.destroy', $membro))->assertInertiaFlash('toast.message', 'Membro removido.');

    expect($membro->fresh())->toBeNull();
});

it('CA-216.1 deleting the card, moving or in cascade, deletes its members', function (string $modo) {
    $nubank = cartaoDe($this->dono, 'Nubank', $this->principal);
    membroDe($nubank, $this->membro);
    membroDe($this->principal, $this->membro);

    $this->actingAs($this->dono)->delete(route('carteiras.destroy', $nubank), $modo === 'mover' ? ['modo' => 'mover', 'destino_id' => $this->principal->id] : ['modo' => 'excluir'])
        ->assertSessionHasNoErrors();

    expect(CarteiraMembro::query()->pluck('carteira_id')->all())->toBe([$this->principal->id]);
})->with(['mover', 'excluir']);

it('CA-216.2 changing the linked wallet of the card keeps its delegation', function () {
    $nubank = cartaoDe($this->dono, 'Nubank', $this->principal);
    $outra = Carteira::factory()->for($this->dono)->create(['nome' => 'Reserva']);
    $registro = membroDe($nubank, $this->membro);

    $this->actingAs($this->dono)->put(route('carteiras.update', $nubank), [
        'nome' => 'Nubank', 'tipo' => 'cartao', 'cor' => $nubank->cor->value, 'icone' => 'credit-card',
        'dia_vencimento' => 10, 'dias_fechamento' => 10, 'competencia' => 'vencimento', 'carteira_vinculada_id' => $outra->id,
    ])->assertSessionHasNoErrors();

    expect($nubank->cartao->fresh()->carteira_vinculada_id)->toBe($outra->id)
        ->and($registro->fresh()?->status)->toBe(StatusMembro::Aceito);
});

it('CA-216.3 removing the contact or deleting an account ends card delegations', function (string $caso) {
    $nubank = cartaoDe($this->dono, 'Nubank', $this->principal);
    membroDe($nubank, $this->membro);

    match ($caso) {
        'contato removido' => $this->actingAs($this->dono)->delete(route('contatos.destroy', $this->contato))->assertSessionHasNoErrors(),
        'conta do membro' => app(ExcluirConta::class)->handle($this->membro),
        'conta do dono' => app(ExcluirConta::class)->handle($this->dono),
    };

    expect(CarteiraMembro::query()->count())->toBe(0);
})->with(['contato removido', 'conta do membro', 'conta do dono']);

it('RN-313 a stray record on a mirror stays out of "Delegadas a mim" and the mirror has no members', function () {
    $nubank = cartaoDe($this->membro, 'Nubank');
    $espelho = cartaoDe($this->dono, 'Nubank · Ana');
    $espelho->cartao->forceFill(['cartao_origem_id' => $nubank->id])->save();
    $registro = membroDe($espelho, $this->membro);

    $this->actingAs($this->membro)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page->has('delegadas.carteiras', 0));
    $this->actingAs($this->dono)->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteiras', fn ($carteiras) => collect($carteiras)->firstWhere('nome', 'Nubank · Ana')['espelho'] === true
                && collect($carteiras)->firstWhere('nome', 'Nubank · Ana')['membros'] === []));

    expect($registro->fresh())->not->toBeNull();
});
