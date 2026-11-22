<?php

use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Models\Carteira;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->principal = Carteira::factory()->for($this->user)->padrao()->create(['nome' => 'Principal']);
});

it('CA-020.1 lists only the own wallets with name, color, icon and default flag', function () {
    Carteira::factory()->for($this->user)->create(['nome' => 'Nubank', 'cor' => CorCarteira::Violet, 'icone' => IconeCarteira::CreditCard]);
    Carteira::factory()->create(['nome' => 'De outro usuário']);

    $this->actingAs($this->user)
        ->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('carteiras/Index')
            ->has('carteiras', 2)
            ->where('carteiras.0.nome', 'Principal')
            ->where('carteiras.0.padrao', true)
            ->where('carteiras.1', fn ($carteira) => $carteira['nome'] === 'Nubank'
                && $carteira['cor'] === 'violet'
                && $carteira['icone'] === 'credit-card'
                && $carteira['padrao'] === false),
        );
});

it('CA-020.2 CA-022.2 forbids changing a wallet of another user', function () {
    $alheia = Carteira::factory()->create();

    $this->actingAs($this->user)->put(route('carteiras.update', $alheia), ['nome' => 'Minha'])->assertForbidden();
    $this->actingAs($this->user)->post(route('carteiras.padrao', $alheia))->assertForbidden();
    $this->actingAs($this->user)->delete(route('carteiras.destroy', $alheia), ['modo' => 'excluir'])->assertForbidden();

    expect($alheia->fresh()->nome)->not->toBe('Minha');
});

it('CA-021.1 creates a non-default wallet with default color and icon', function () {
    $this->actingAs($this->user)
        ->post(route('carteiras.store'), ['nome' => 'Dinheiro'])
        ->assertSessionHasNoErrors();

    expect($this->user->carteiras()->where('nome', 'Dinheiro')->sole())
        ->cor->toBe(CorCarteira::Blue)
        ->icone->toBe(IconeCarteira::Wallet)
        ->padrao->toBeFalse();
});

it('CA-021.2 rejects color or icon outside the fixed lists', function () {
    $this->actingAs($this->user)
        ->post(route('carteiras.store'), ['nome' => 'X', 'cor' => 'magenta', 'icone' => 'rocket'])
        ->assertSessionHasErrors(['cor', 'icone']);
});

it('CA-021.3 rejects an empty or too long name', function (string $nome) {
    $this->actingAs($this->user)
        ->post(route('carteiras.store'), ['nome' => $nome])
        ->assertSessionHasErrors('nome');
})->with(['vazio' => '', '21 caracteres' => str_repeat('a', 21)]);

it('accepts a wallet name with exactly 20 characters', function () {
    $nome = str_repeat('á', 20);

    $this->actingAs($this->user)
        ->post(route('carteiras.store'), ['nome' => $nome])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('carteiras', ['user_id' => $this->user->id, 'nome' => $nome]);
});

it('keeps a legacy wallet name when editing other options and rejects a new long name', function () {
    $nome = str_repeat('a', 40);
    $carteira = Carteira::factory()->for($this->user)->create(['nome' => $nome]);

    $this->actingAs($this->user)
        ->put(route('carteiras.update', $carteira), ['nome' => $nome, 'cor' => 'green'])
        ->assertSessionHasNoErrors();

    $this->put(route('carteiras.update', $carteira), ['nome' => str_repeat('b', 21)])
        ->assertSessionHasErrors('nome');

    expect($carteira->fresh())->nome->toBe($nome)->cor->toBe(CorCarteira::Green);
});

it('CA-022.1 updates name, color and icon', function () {
    $this->actingAs($this->user)
        ->put(route('carteiras.update', $this->principal), ['nome' => 'Conta corrente', 'cor' => 'green', 'icone' => 'landmark'])
        ->assertSessionHasNoErrors();

    expect($this->principal->fresh())
        ->nome->toBe('Conta corrente')
        ->cor->toBe(CorCarteira::Green)
        ->icone->toBe(IconeCarteira::Landmark)
        ->padrao->toBeTrue();
});

it('CA-022.1 keeps the current color and icon when they are not sent', function () {
    $carteira = Carteira::factory()->for($this->user)->create(['cor' => CorCarteira::Violet, 'icone' => IconeCarteira::CreditCard]);

    $this->actingAs($this->user)
        ->put(route('carteiras.update', $carteira), ['nome' => 'Cartão'])
        ->assertSessionHasNoErrors();

    expect($carteira->fresh())
        ->nome->toBe('Cartão')
        ->cor->toBe(CorCarteira::Violet)
        ->icone->toBe(IconeCarteira::CreditCard);
});

it('CA-023.1 CA-023.2 switches the default wallet keeping exactly one', function () {
    $outra = Carteira::factory()->for($this->user)->create();

    $this->actingAs($this->user)->post(route('carteiras.padrao', $outra))->assertSessionHasNoErrors();

    expect($outra->fresh()->padrao)->toBeTrue()
        ->and($this->principal->fresh()->padrao)->toBeFalse()
        ->and($this->user->carteiras()->where('padrao', true)->count())->toBe(1);
});

it('CA-024.1 deletes an empty non-default wallet', function () {
    $vazia = Carteira::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $vazia), ['modo' => 'excluir'])
        ->assertSessionHasNoErrors();

    expect($vazia->fresh())->toBeNull();
});

it('CA-024.2 blocks deleting the default wallet', function () {
    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->principal), ['modo' => 'excluir'])
        ->assertSessionHasErrors('carteira');

    expect($this->principal->fresh())->not->toBeNull();
});

it('CA-024.7 rejects moving to the same wallet or to a wallet of another user', function () {
    $carteira = Carteira::factory()->for($this->user)->create();
    $alheia = Carteira::factory()->create();

    foreach ([$carteira->id, $alheia->id, null] as $destinoId) {
        $this->actingAs($this->user)
            ->delete(route('carteiras.destroy', $carteira), ['modo' => 'mover', 'destino_id' => $destinoId])
            ->assertSessionHasErrors('destino_id');
    }

    expect($carteira->fresh())->not->toBeNull();
});
