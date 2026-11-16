<?php

use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00'));
});

/**
 * A user in the first access with a default wallet.
 */
function usuarioNoPrimeiroAcesso(array $atributos = []): User
{
    $user = User::factory()->create(['primeiro_acesso_em' => null, ...$atributos]);
    Carteira::factory()->for($user)->padrao()->create();

    return $user;
}

it('CA-111.1 asks the question to a user who is 50 today and keeps the first access open', function () {
    $user = usuarioNoPrimeiroAcesso(['data_nascimento' => '1976-11-15']);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('perguntarModoSimples', true));

    expect($user->refresh()->primeiro_acesso_em)->toBeNull();
});

it('CA-111.2 does not ask a user who is 49 and ends the first access', function () {
    $user = usuarioNoPrimeiroAcesso(['data_nascimento' => '1976-11-16']);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('perguntarModoSimples', false));

    expect($user->refresh()->primeiro_acesso_em)->not->toBeNull();
});

it('CA-111.3 does not ask a user without birth date and ends the first access', function () {
    $user = usuarioNoPrimeiroAcesso();

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('perguntarModoSimples', false));

    expect($user->refresh()->primeiro_acesso_em)->not->toBeNull();
});

it('CA-111.4 CA-111.5 saves the answer, ends the first access and returns home without reopening the form', function (bool $resposta) {
    $user = usuarioNoPrimeiroAcesso(['data_nascimento' => '1950-01-01', 'modo_simples' => false]);

    $this->actingAs($user)
        ->from(route('home', ['novo' => 1]))
        ->post(route('primeiro-acesso'), ['modo_simples' => $resposta])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect($user->refresh())
        ->modo_simples->toBe($resposta)
        ->primeiro_acesso_em->not->toBeNull();
})->with(['Sim' => true, 'Não, agora não' => false]);

it('RN-155 an answer after the first access changes nothing', function () {
    $user = usuarioNoPrimeiroAcesso(['primeiro_acesso_em' => now()->subDay(), 'modo_simples' => true]);

    $this->actingAs($user)->post(route('primeiro-acesso'), ['modo_simples' => false]);

    expect($user->refresh()->modo_simples)->toBeTrue();
});

it('CA-111.6 does not ask after the first access', function () {
    $user = usuarioNoPrimeiroAcesso(['data_nascimento' => '1950-01-01', 'primeiro_acesso_em' => now()->subDay()]);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('perguntarModoSimples', false));
});

it('CA-111.7 asks again after a reload while the question is not answered', function () {
    $user = usuarioNoPrimeiroAcesso(['data_nascimento' => '1976-11-15']);

    $this->actingAs($user)->get(route('home'));

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('perguntarModoSimples', true));
});

it('CA-111.8 does not ask when the simple mode is already on and ends the first access', function () {
    $user = usuarioNoPrimeiroAcesso(['data_nascimento' => '1976-11-15', 'modo_simples' => true]);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('perguntarModoSimples', false));

    expect($user->refresh()->primeiro_acesso_em)->not->toBeNull();
});

it('CA-112.1 turns the simple mode on and off in Aparência', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('appearance.update'), ['modo_simples' => true])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('appearance.edit'));

    expect($user->refresh()->modo_simples)->toBeTrue();

    $this->actingAs($user)->patch(route('appearance.update'), ['modo_simples' => false]);

    expect($user->refresh()->modo_simples)->toBeFalse();
});

it('RN-157 changing the simple mode keeps the palette, and an invalid value changes nothing', function () {
    $user = User::factory()->create(['paleta' => 'areia']);

    $this->actingAs($user)
        ->patch(route('appearance.update'), ['modo_simples' => 'talvez'])
        ->assertSessionHasErrors('modo_simples');

    $this->actingAs($user)->patch(route('appearance.update'), ['modo_simples' => true]);

    expect($user->refresh())
        ->modo_simples->toBeTrue()
        ->paleta->value->toBe('areia');
});

it('CA-112.2 every page receives modoSimples from the account and renders it on the html element', function (bool $modoSimples) {
    $user = User::factory()->create(['modo_simples' => $modoSimples]);

    $resposta = $this->actingAs($user)->get(route('profile.edit'));

    $resposta->assertInertia(fn (Assert $page) => $page->where('modoSimples', $modoSimples));

    $modoSimples
        ? $resposta->assertSee('data-modo-simples', false)
        : $resposta->assertDontSee('data-modo-simples', false);
})->with([true, false]);

it('CA-113.1 the simple mode ignores the filters of the URL', function () {
    $user = User::factory()->create(['modo_simples' => true]);
    $carteira = Carteira::factory()->for($user)->padrao()->create();
    $categoria = Categoria::factory()->for($user)->create(['tipo' => TipoCategoria::Ambos]);
    lancamentoComCota($user, $carteira, $categoria, ['tipo' => 'receita', 'data' => '2026-11-05', 'descricao' => 'Salário']);
    lancamentoComCota($user, $carteira, $categoria, ['tipo' => 'despesa', 'data' => '2026-11-10', 'descricao' => 'Mercado'], ['executado' => true]);

    $this->actingAs($user)
        ->get(route('home', ['tipo' => 'despesa', 'categoria' => $categoria->id + 1, 'status' => 'pendente', 'compartilhado' => 'sim']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filtros', ['tipo' => null, 'categoria' => null, 'status' => null, 'compartilhado' => null])
            ->where('lancamentos.receita.0.descricao', 'Salário')
            ->where('lancamentos.despesa.0.descricao', 'Mercado'));
});

it('RN-161 the simple mode lists items without subsections by date and creation, groups included', function () {
    $user = User::factory()->create(['modo_simples' => true]);
    $carteira = Carteira::factory()->for($user)->padrao()->create();
    $categoria = Categoria::factory()->for($user)->create(['tipo' => TipoCategoria::Ambos]);
    $grupo = Grupo::factory()->for($user)->for($carteira)->create(['tipo' => 'despesa']);

    lancamentoComCota($user, $carteira, $categoria, ['data' => '2026-11-20', 'descricao' => 'Luz']);
    $agrupado = lancamentoComCota($user, $carteira, $categoria, ['data' => '2026-11-10', 'descricao' => 'Cartão']);
    Cota::query()->where('lancamento_id', $agrupado->id)->update(['grupo_id' => $grupo->id]);
    $this->travel(1)->minutes();
    lancamentoComCota($user, $carteira, $categoria, ['data' => '2026-11-10', 'descricao' => 'Mercado']);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lancamentos.despesa', fn ($itens) => collect($itens)->pluck('descricao')->all() === ['Cartão', 'Mercado', 'Luz'])
            ->where('lancamentos.receita', [])
            ->where('grupos', []));
});

it('RN-161 the full mode keeps the subsections', function () {
    $user = User::factory()->create(['modo_simples' => false]);
    $carteira = Carteira::factory()->for($user)->padrao()->create();
    $categoria = Categoria::factory()->for($user)->create(['tipo' => TipoCategoria::Ambos]);
    lancamentoComCota($user, $carteira, $categoria, ['data' => '2026-11-20', 'descricao' => 'Luz']);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('lancamentos.despesa.avista.0.descricao', 'Luz'));
});

it('CA-113.2 an entry created with only the basic fields is saved as in the full mode', function () {
    $user = User::factory()->create(['modo_simples' => true]);
    $carteira = Carteira::factory()->for($user)->padrao()->create();
    $categoria = Categoria::factory()->for($user)->create(['tipo' => TipoCategoria::Despesa]);

    $this->actingAs($user)
        ->post(route('lancamentos.store'), [
            'tipo' => 'despesa',
            'descricao' => 'Mercado',
            'valor' => '150,00',
            'data' => '2026-11-10',
            'categoria_id' => $categoria->id,
            'carteira_id' => $carteira->id,
        ])
        ->assertSessionHasNoErrors();

    $lancamento = Lancamento::query()->sole();

    expect($lancamento)
        ->valor->toBe(15000)
        ->parcelamento_id->toBeNull()
        ->modo_rateio->toBeNull()
        ->and($lancamento->cotas()->sole())
        ->carteira_id->toBe($carteira->id)
        ->executado->toBeTrue();
});
