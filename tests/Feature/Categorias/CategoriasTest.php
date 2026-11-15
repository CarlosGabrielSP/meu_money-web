<?php

use App\Enums\TipoCategoria;
use App\Enums\TipoLancamento;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Database\Seeders\CategoriasGlobaisSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->user = User::factory()->create();
    $this->outros = Categoria::query()->globais()->where('nome', 'Outros')->sole();
});

it('CA-030.1 lists system and own categories, never those of other users', function () {
    Categoria::factory()->for($this->user)->create(['nome' => 'Pet']);
    Categoria::factory()->create(['nome' => 'Alheia']);

    $this->actingAs($this->user)
        ->get(route('categorias.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('categorias/Index')
            ->has('categorias', 18)
            ->where('categorias', fn ($categorias) => $categorias->contains(fn ($c) => $c['nome'] === 'Pet' && $c['doSistema'] === false)
                && $categorias->contains(fn ($c) => $c['nome'] === 'Outros' && $c['doSistema'] === true && $c['tipo'] === 'ambos')
                && ! $categorias->contains(fn ($c) => $c['nome'] === 'Alheia')),
        );
});

it('CA-031.1 creates a category owned by the user', function () {
    $this->actingAs($this->user)
        ->post(route('categorias.store'), ['nome' => 'Pet', 'tipo' => 'despesa'])
        ->assertSessionHasNoErrors();

    expect($this->user->categorias()->sole())
        ->nome->toBe('Pet')
        ->tipo->toBe(TipoCategoria::Despesa);
});

it('CA-031.2 rejects a name used by a system category or another own category, ignoring case', function (string $nome) {
    Categoria::factory()->for($this->user)->create(['nome' => 'Pet']);

    $this->actingAs($this->user)
        ->post(route('categorias.store'), ['nome' => $nome, 'tipo' => 'despesa'])
        ->assertSessionHasErrors('nome');
})->with(['global' => 'mercado', 'própria' => 'PET']);

it('CA-031.2 rejects an own accented name typed in another case', function () {
    Categoria::factory()->for($this->user)->create(['nome' => 'ÇARTÃO']);

    $this->actingAs($this->user)
        ->post(route('categorias.store'), ['nome' => 'çartão', 'tipo' => 'despesa'])
        ->assertSessionHasErrors('nome');
});

it('CA-031.3 rejects an unknown type', function () {
    $this->actingAs($this->user)
        ->post(route('categorias.store'), ['nome' => 'Pet', 'tipo' => 'transferencia'])
        ->assertSessionHasErrors('tipo');
});

it('CA-031.4 allows a name used only by another user', function () {
    Categoria::factory()->create(['nome' => 'Pet']);

    $this->actingAs($this->user)
        ->post(route('categorias.store'), ['nome' => 'Pet', 'tipo' => 'despesa'])
        ->assertSessionHasNoErrors();
});

it('CA-032.1 renames an own category, keeping its own name valid', function () {
    $categoria = Categoria::factory()->for($this->user)->create(['nome' => 'Pet']);

    $this->actingAs($this->user)
        ->put(route('categorias.update', $categoria), ['nome' => 'Pet', 'tipo' => 'despesa'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)
        ->put(route('categorias.update', $categoria), ['nome' => 'Animais', 'tipo' => 'despesa'])
        ->assertSessionHasNoErrors();

    expect($categoria->fresh()->nome)->toBe('Animais');
});

it('CA-032.2 CA-033.3 forbids changing or deleting a system category', function () {
    $this->actingAs($this->user)
        ->put(route('categorias.update', $this->outros), ['nome' => 'Diversos', 'tipo' => 'ambos'])
        ->assertForbidden();
    $this->actingAs($this->user)->delete(route('categorias.destroy', $this->outros))->assertForbidden();

    expect($this->outros->fresh()->nome)->toBe('Outros');
});

it('CA-032.3 forbids changing a category of another user', function () {
    $alheia = Categoria::factory()->create();

    $this->actingAs($this->user)
        ->put(route('categorias.update', $alheia), ['nome' => 'Minha', 'tipo' => 'despesa'])
        ->assertForbidden();
    $this->actingAs($this->user)->delete(route('categorias.destroy', $alheia))->assertForbidden();
});

it('CA-032.4 blocks a type change that conflicts with entries or series using the category', function () {
    $emLancamento = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Despesa]);
    $lancamento = Lancamento::factory()->for($this->user)->create(['tipo' => TipoLancamento::Despesa]);
    Cota::factory()->for($lancamento)->create(['categoria_id' => $emLancamento->id]);

    $emSerie = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Despesa]);
    Parcelamento::factory()->for($this->user)->create(['categoria_id' => $emSerie->id, 'tipo' => TipoLancamento::Despesa]);

    foreach ([$emLancamento, $emSerie] as $categoria) {
        $this->actingAs($this->user)
            ->put(route('categorias.update', $categoria), ['nome' => $categoria->nome, 'tipo' => 'receita'])
            ->assertSessionHasErrors('tipo');

        $this->actingAs($this->user)
            ->put(route('categorias.update', $categoria), ['nome' => $categoria->nome, 'tipo' => 'ambos'])
            ->assertSessionHasNoErrors();

        expect($categoria->fresh()->tipo)->toBe(TipoCategoria::Ambos);
    }
});

it('CA-033.1 deletes an unused own category', function () {
    $categoria = Categoria::factory()->for($this->user)->create();

    $this->actingAs($this->user)->delete(route('categorias.destroy', $categoria))->assertSessionHasNoErrors();

    expect($categoria->fresh())->toBeNull();
});

it('CA-033.2 moves shares and series to "Outros" when deleting a used category', function () {
    $categoria = Categoria::factory()->for($this->user)->create();
    $lancamento = Lancamento::factory()->for($this->user)->create();
    $cota = Cota::factory()->for($lancamento)->create(['categoria_id' => $categoria->id]);
    $serie = Parcelamento::factory()->for($this->user)->create(['categoria_id' => $categoria->id]);

    $this->actingAs($this->user)->delete(route('categorias.destroy', $categoria))->assertSessionHasNoErrors();

    expect($categoria->fresh())->toBeNull()
        ->and($cota->fresh()->categoria_id)->toBe($this->outros->id)
        ->and($serie->fresh()->categoria_id)->toBe($this->outros->id);
});
