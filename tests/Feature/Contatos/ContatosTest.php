<?php

use App\Enums\ModoRateio;
use App\Enums\StatusContato;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\User;
use App\Notifications\ConviteContatoNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->a = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
    $this->b = User::factory()->create(['name' => 'Bruno', 'email' => 'bruno@example.com']);
});

const MENSAGEM_GENERICA = 'Se o e-mail estiver cadastrado, o convite foi enviado.';

function convidar(User $de, string $email)
{
    return test()->actingAs($de)->post(route('contatos.store'), ['email' => $email]);
}

it('CA-060.1 creates a pending invitation, emails the invited user and shows the generic message', function () {
    convidar($this->a, 'BRUNO@example.com')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', MENSAGEM_GENERICA);

    expect(Contato::query()->sole())
        ->solicitante_id->toBe($this->a->id)
        ->convidado_id->toBe($this->b->id)
        ->status->toBe(StatusContato::Pendente);
    Notification::assertSentTo($this->b, ConviteContatoNotification::class);
});

it('CA-060.2 CA-060.3 answers the same for an unknown or deactivated email and creates nothing', function () {
    $desativado = User::factory()->create(['email' => 'off@example.com', 'desativado_em' => now()]);

    convidar($this->a, 'ninguem@example.com')->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', MENSAGEM_GENERICA);
    convidar($this->a, $desativado->email)->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', MENSAGEM_GENERICA);

    expect(Contato::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('CA-060.4 rejects inviting oneself', function () {
    convidar($this->a, 'Ana@example.com')->assertSessionHasErrors('email');

    expect(Contato::query()->count())->toBe(0);
});

it('CA-060.5 reopens a refused invitation and emails again', function () {
    Contato::factory()->create([
        'solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id,
        'status' => StatusContato::Recusado, 'respondido_em' => now(),
    ]);

    convidar($this->a, $this->b->email)->assertSessionHasNoErrors();

    expect(Contato::query()->sole())->status->toBe(StatusContato::Pendente)->respondido_em->toBeNull();
    Notification::assertSentTo($this->b, ConviteContatoNotification::class);
});

it('CA-060.6 accepts automatically when the other side already invited', function () {
    Contato::factory()->create(['solicitante_id' => $this->b->id, 'convidado_id' => $this->a->id]);

    convidar($this->a, $this->b->email)->assertSessionHasNoErrors();

    expect(Contato::query()->sole())->status->toBe(StatusContato::Aceito)->respondido_em->not->toBeNull()
        ->and($this->a->consultaDeContatos()->pluck('id')->all())->toBe([$this->b->id]);
});

it('CA-060.7 changes nothing for existing contacts or a pending invitation from the requester', function (StatusContato $status) {
    $contato = Contato::factory()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id, 'status' => $status]);

    convidar($this->a, $this->b->email)->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', MENSAGEM_GENERICA);

    expect(Contato::query()->sole())->id->toBe($contato->id)->status->toBe($status);
    Notification::assertNothingSent();
})->with([StatusContato::Aceito, StatusContato::Pendente]);

it('CA-060.8 reuses and inverts an invitation refused by the requester', function () {
    $contato = Contato::factory()->create([
        'solicitante_id' => $this->b->id, 'convidado_id' => $this->a->id,
        'status' => StatusContato::Recusado, 'respondido_em' => now(),
    ]);

    convidar($this->a, $this->b->email)->assertSessionHasNoErrors();

    expect(Contato::query()->sole())
        ->id->toBe($contato->id)
        ->solicitante_id->toBe($this->a->id)
        ->convidado_id->toBe($this->b->id)
        ->status->toBe(StatusContato::Pendente)
        ->respondido_em->toBeNull();
    Notification::assertSentTo($this->b, ConviteContatoNotification::class);
});

it('CA-060.9 limits invitations to 20 per hour', function () {
    foreach (range(1, 20) as $numero) {
        convidar($this->a, "pessoa{$numero}@example.com")->assertRedirect();
    }

    convidar($this->a, 'pessoa21@example.com')->assertTooManyRequests();
});

it('CA-061.1 accepts an invitation and both see each other as contacts', function () {
    $contato = Contato::factory()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);

    $this->actingAs($this->b)->post(route('contatos.aceitar', $contato))->assertSessionHasNoErrors();

    expect($contato->fresh())->status->toBe(StatusContato::Aceito)
        ->and($contato->fresh()->respondido_em->toDateTimeString())->toBe('2026-11-14 10:00:00')
        ->and($this->a->consultaDeContatos()->pluck('id')->all())->toBe([$this->b->id])
        ->and($this->b->consultaDeContatos()->pluck('id')->all())->toBe([$this->a->id]);
});

it('CA-061.2 refuses an invitation without telling the requester', function () {
    $contato = Contato::factory()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);

    $this->actingAs($this->b)->post(route('contatos.recusar', $contato))->assertSessionHasNoErrors();

    expect($contato->fresh())->status->toBe(StatusContato::Recusado)->respondido_em->not->toBeNull()
        ->and($this->a->consultaDeContatos()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('CA-061.3 forbids another user or the requester to answer', function () {
    $contato = Contato::factory()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);
    $c = User::factory()->create();

    $this->actingAs($c)->post(route('contatos.aceitar', $contato))->assertForbidden();
    $this->actingAs($this->a)->post(route('contatos.aceitar', $contato))->assertForbidden();
    $this->actingAs($c)->post(route('contatos.recusar', $contato))->assertForbidden();

    expect($contato->fresh()->status)->toBe(StatusContato::Pendente);
});

it('CA-062.1 shares the number of pending received invitations', function () {
    Contato::factory()->count(2)->create(['convidado_id' => $this->b->id]);
    Contato::factory()->aceito()->create(['convidado_id' => $this->b->id]);

    $this->actingAs($this->b)->get(route('contatos.index'))
        ->assertInertia(fn (Assert $page) => $page->where('contadores.convites', 2));
});

it('CA-062.2 CA-063.1 lists contacts, received and sent pending invitations', function () {
    $carla = User::factory()->create(['name' => 'Carla', 'email' => 'carla@example.com']);
    $davi = User::factory()->create(['name' => 'Davi', 'email' => 'davi@example.com']);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->b->id, 'convidado_id' => $this->a->id]);
    Contato::factory()->create(['solicitante_id' => $carla->id, 'convidado_id' => $this->a->id]);
    Contato::factory()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $davi->id]);
    Contato::factory()->create(['solicitante_id' => $this->a->id, 'convidado_id' => User::factory()->create()->id, 'status' => StatusContato::Recusado]);

    $this->actingAs($this->a)->get(route('contatos.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('contatos/Index')
            ->where('contatos', fn ($contatos) => collect($contatos)->map(fn ($c) => [$c['nome'], $c['email']])->all() === [['Bruno', 'bruno@example.com']])
            ->where('recebidos.0.nome', 'Carla')
            ->has('recebidos', 1)
            ->where('enviados.0.nome', 'Davi')
            ->has('enviados', 1));
});

it('CA-062.3 lets the requester cancel a pending invitation', function () {
    $contato = Contato::factory()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);

    $this->actingAs($this->b)->delete(route('contatos.destroy', $contato))->assertForbidden();
    $this->actingAs($this->a)->delete(route('contatos.destroy', $contato))->assertSessionHasNoErrors();

    expect($contato->fresh())->toBeNull();
});

it('CA-063.2 lets either side remove a contact and keeps existing shares', function (string $quem) {
    $contato = Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);
    $compartilhado = Lancamento::factory()->for($this->a)->create(['valor' => 10000, 'modo_rateio' => ModoRateio::Iguais]);
    Cota::factory()->for($compartilhado)->create(['valor' => 5000]);
    Cota::factory()->for($compartilhado)->create(['user_id' => $this->b->id, 'valor' => 5000]);

    $this->actingAs($this->{$quem})->delete(route('contatos.destroy', $contato))->assertSessionHasNoErrors();

    expect($contato->fresh())->toBeNull()
        ->and($this->a->consultaDeContatos()->count())->toBe(0)
        ->and($compartilhado->fresh())->not->toBeNull()
        ->and($compartilhado->cotas()->pluck('user_id')->sort()->values()->all())->toBe([$this->a->id, $this->b->id]);
})->with(['solicitante' => 'a', 'convidado' => 'b']);

it('forbids a third user to remove a contact', function () {
    $contato = Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);

    $this->actingAs(User::factory()->create())->delete(route('contatos.destroy', $contato))->assertForbidden();
});

it('CA-060.9 does not count rejected invitations toward the limit', function () {
    foreach (range(1, 5) as $tentativa) {
        convidar($this->a, $this->a->email)->assertSessionHasErrors('email');
    }

    foreach (range(1, 20) as $numero) {
        convidar($this->a, "pessoa{$numero}@example.com")->assertSessionHasNoErrors();
    }

    convidar($this->a, 'pessoa21@example.com')->assertTooManyRequests();
});

it('RN-109 hides deactivated users from contacts, invitations and the counter', function () {
    $carla = User::factory()->create(['name' => 'Carla']);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);
    $convite = Contato::factory()->create(['solicitante_id' => $carla->id, 'convidado_id' => $this->a->id]);
    $this->b->forceFill(['desativado_em' => now()])->save();
    $carla->forceFill(['desativado_em' => now()])->save();

    $this->actingAs($this->a)->get(route('contatos.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contatos', 0)
            ->has('recebidos', 0)
            ->where('contadores.convites', 0));

    expect($this->a->consultaDeContatos()->count())->toBe(0);

    $this->actingAs($this->a)->post(route('contatos.aceitar', $convite))->assertForbidden();
});
