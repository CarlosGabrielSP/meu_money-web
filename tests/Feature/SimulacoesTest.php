<?php

use App\Enums\StatusMembro;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Lancamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

it('CA-218.1 opens the last common wallet or falls back to the default from a card', function (bool $ultimaEhCartao) {
    $user = User::factory()->create();
    $padrao = Carteira::factory()->for($user)->padrao()->create();
    $ultima = $ultimaEhCartao
        ? cartaoDe($user, 'Cartão', $padrao)
        : Carteira::factory()->for($user)->create();
    $user->forceFill(['ultima_carteira_id' => $ultima->id])->save();

    $this->actingAs($user)->get(route('simulacoes'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Simulacoes')
            ->where('carteiraId', $ultimaEhCartao ? $padrao->id : $ultima->id)
            ->has('carteiras', $ultimaEhCartao ? 1 : 2));
})->with([false, true]);

it('CA-218.2 CA-218.3 opens a delegated common wallet with its owner balances for the current month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00'));
    $membro = User::factory()->create();
    $dono = User::factory()->create(['name' => 'Carlos']);
    $propria = Carteira::factory()->for($membro)->padrao()->create(['nome' => 'Minha carteira']);
    $compartilhada = Carteira::factory()->for($dono)->create(['nome' => 'Casa']);
    $pendente = Carteira::factory()->for($dono)->create();
    $cartao = cartaoDe($dono, 'Cartão', $compartilhada);
    delegarCarteira($compartilhada, $membro);
    delegarCarteira($pendente, $membro, StatusMembro::Pendente);
    delegarCarteira($cartao, $membro);
    $membro->forceFill(['ultima_carteira_id' => $propria->id])->save();
    $categoria = Categoria::factory()->for($dono)->create();

    lancamentoComCota($dono, $compartilhada, $categoria, ['tipo' => 'receita', 'data' => '2026-10-20', 'valor' => 10000], ['executado' => true]);
    lancamentoComCota($dono, $compartilhada, $categoria, ['tipo' => 'despesa', 'data' => '2026-11-24', 'valor' => 3000], ['executado' => false]);
    lancamentoComCota($dono, $compartilhada, $categoria, ['tipo' => 'despesa', 'data' => '2026-12-01', 'valor' => 2000], ['executado' => false]);

    $this->actingAs($membro)->get(route('simulacoes', ['carteira' => $compartilhada->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Simulacoes')
            ->where('carteiraId', $compartilhada->id)
            ->where('carteiras', [
                ['id' => $compartilhada->id, 'nome' => 'Casa · de Carlos'],
                ['id' => $propria->id, 'nome' => 'Minha carteira'],
            ])
            ->where('saldos', ['atual' => 10000, 'estimado' => 7000]));

    expect($membro->fresh()->ultima_carteira_id)->toBe($propria->id);
    $this->assertDatabaseCount(Lancamento::class, 3);
});

it('CA-218.2 gives no access to another wallet, a pending delegation or a card', function (string $tipo) {
    $membro = User::factory()->create();
    $dono = User::factory()->create();
    $carteira = $tipo === 'cartão'
        ? cartaoDe($membro, 'Cartão')
        : Carteira::factory()->for($dono)->create();

    if ($tipo === 'pendente') {
        delegarCarteira($carteira, $membro, StatusMembro::Pendente);
    }

    $this->actingAs($membro)->get(route('simulacoes', ['carteira' => $carteira->id]))
        ->assertNotFound();
})->with(['alheia', 'pendente', 'cartão']);

it('CA-218.2 shows an empty state when no common wallet is available', function () {
    $user = User::factory()->create();
    cartaoDe($user, 'Cartão');

    $this->actingAs($user)->get(route('simulacoes'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteiraId', null)
            ->has('carteiras', 0)
            ->where('saldos', null));
});

it('requires authentication to open simulations', function () {
    $this->get(route('simulacoes'))->assertRedirect(route('login'));
});
