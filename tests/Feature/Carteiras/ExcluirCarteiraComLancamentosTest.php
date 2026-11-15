<?php

use App\Enums\ModoRateio;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    Carteira::factory()->for($this->user)->padrao()->create();
    $this->carteira = Carteira::factory()->for($this->user)->create();
    $this->destino = Carteira::factory()->for($this->user)->create();
    $this->categoria = Categoria::factory()->for($this->user)->create();
});

function lancamentoNaCarteira(User $user, Carteira $carteira, Categoria $categoria, array $atributos = []): Lancamento
{
    $lancamento = Lancamento::factory()->for($user)->create($atributos);
    Cota::factory()->for($lancamento)->create(['carteira_id' => $carteira->id, 'categoria_id' => $categoria->id]);

    return $lancamento;
}

it('CA-024.3 moves shares, series and groups to the destination wallet', function () {
    $lancamento = lancamentoNaCarteira($this->user, $this->carteira, $this->categoria);
    $serie = Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    $grupo = Grupo::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id]);

    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->carteira), ['modo' => 'mover', 'destino_id' => $this->destino->id])
        ->assertSessionHasNoErrors();

    expect($this->carteira->fresh())->toBeNull()
        ->and($lancamento->cotas()->sole()->carteira_id)->toBe($this->destino->id)
        ->and($serie->fresh()->carteira_id)->toBe($this->destino->id)
        ->and($grupo->fresh()->carteira_id)->toBe($this->destino->id);
});

it('CA-024.4 deletes own entries and series of the wallet in cascade', function () {
    $lancamento = lancamentoNaCarteira($this->user, $this->carteira, $this->categoria);
    $emOutraCarteira = lancamentoNaCarteira($this->user, $this->destino, $this->categoria);
    $serie = Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    $grupo = Grupo::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id]);

    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->carteira), ['modo' => 'excluir'])
        ->assertSessionHasNoErrors();

    expect($this->carteira->fresh())->toBeNull()
        ->and($lancamento->fresh())->toBeNull()
        ->and($serie->fresh())->toBeNull()
        ->and($grupo->fresh())->toBeNull()
        ->and($emOutraCarteira->fresh())->not->toBeNull();
});

it('CA-024.6 keeps installments of a deleted series that live in another wallet as standalone entries', function () {
    $serie = Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    $parcelaNaCarteira = lancamentoNaCarteira($this->user, $this->carteira, $this->categoria);
    $parcelaNaCarteira->forceFill(['parcelamento_id' => $serie->id, 'numero_parcela' => 1])->save();
    $parcelaMovida = lancamentoNaCarteira($this->user, $this->destino, $this->categoria);
    $parcelaMovida->forceFill(['parcelamento_id' => $serie->id, 'numero_parcela' => 2])->save();

    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->carteira), ['modo' => 'excluir'])
        ->assertSessionHasNoErrors();

    expect($serie->fresh())->toBeNull()
        ->and($parcelaNaCarteira->fresh())->toBeNull()
        ->and($parcelaMovida->fresh())
        ->parcelamento_id->toBeNull()
        ->numero_parcela->toBeNull();
});

it('refuses cascade deletion of a wallet with shared entries until sharing rules exist', function () {
    $compartilhado = lancamentoNaCarteira($this->user, $this->carteira, $this->categoria);
    $compartilhado->forceFill(['modo_rateio' => ModoRateio::Iguais])->save();

    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->carteira), ['modo' => 'excluir'])
        ->assertSessionHasErrors('carteira');

    expect($this->carteira->fresh())->not->toBeNull()
        ->and($compartilhado->fresh())->not->toBeNull();
});

it('refuses cascade deletion of a wallet with a shared series even before it has entries', function () {
    $serie = Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    $serie->forceFill(['modo_rateio' => ModoRateio::Iguais])->save();

    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->carteira), ['modo' => 'excluir'])
        ->assertSessionHasErrors('carteira');

    expect($this->carteira->fresh())->not->toBeNull()
        ->and($serie->fresh())->not->toBeNull();
});

it('CA-024.3 flags a wallet holding only series or groups as having content', function () {
    Parcelamento::factory()->for($this->user)->create(['carteira_id' => $this->carteira->id, 'categoria_id' => $this->categoria->id]);
    Grupo::factory()->for($this->user)->create(['carteira_id' => $this->destino->id]);

    $this->actingAs($this->user)
        ->get(route('carteiras.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carteiras', fn ($carteiras) => collect($carteiras)
                ->whereIn('id', [$this->carteira->id, $this->destino->id])
                ->every(fn (array $carteira) => $carteira['temConteudo'] === true)),
        );
});

it('CA-024.1 ignores a leftover destination when deleting the contents', function () {
    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->carteira), ['modo' => 'excluir', 'destino_id' => $this->carteira->id])
        ->assertSessionHasNoErrors();

    expect($this->carteira->fresh())->toBeNull();
});
