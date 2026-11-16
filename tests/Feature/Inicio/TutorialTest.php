<?php

use App\Models\Carteira;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00'));
});

/**
 * A user with a default wallet; the factory marks the tutorial as seen.
 */
function usuarioDoTutorial(array $atributos = []): User
{
    $user = User::factory()->create($atributos);
    Carteira::factory()->for($user)->padrao()->create();

    return $user;
}

it('CA-120.1 opens the tour for a user who never saw it, also with the simple mode question pending', function () {
    $user = usuarioDoTutorial([
        'tutorial_visto_em' => null,
        'primeiro_acesso_em' => null,
        'data_nascimento' => '1950-01-01',
    ]);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('perguntarModoSimples', true)
            ->where('mostrarTutorial', true));

    expect($user->refresh()->tutorial_visto_em)->toBeNull();
});

it('CA-120.2 finishing or skipping the tour saves tutorial_visto_em', function () {
    $user = usuarioDoTutorial(['tutorial_visto_em' => null]);

    $this->actingAs($user)->post(route('tutorial-visto'))->assertRedirect(route('home'));

    expect($user->refresh()->tutorial_visto_em?->toDateTimeString())->toBe('2026-11-15 10:00:00');
});

it('CA-120.3 does not open the tour after it was seen', function () {
    $user = usuarioDoTutorial();

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('mostrarTutorial', false));
});

it('CA-121.1 "Ver tutorial" opens the tour again without changing tutorial_visto_em', function () {
    $user = usuarioDoTutorial(['tutorial_visto_em' => '2026-11-01 08:00:00']);

    $this->actingAs($user)->get(route('home', ['tutorial' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('mostrarTutorial', true));

    expect($user->refresh()->tutorial_visto_em->toDateTimeString())->toBe('2026-11-01 08:00:00');
});

it('CA-121.2 finishing the tour opened again keeps the first tutorial_visto_em', function () {
    $user = usuarioDoTutorial(['tutorial_visto_em' => '2026-11-01 08:00:00']);

    $this->actingAs($user)->post(route('tutorial-visto'))->assertRedirect(route('home'));

    expect($user->refresh()->tutorial_visto_em->toDateTimeString())->toBe('2026-11-01 08:00:00');
});

it('DEC-069 finishing the tour returns to the same wallet and month without "tutorial" and "novo"', function () {
    $user = usuarioDoTutorial();
    $carteira = $user->carteiras()->firstOrFail();

    $this->actingAs($user)
        ->from(route('home', ['carteira' => $carteira->id, 'mes' => '2026-03', 'tutorial' => 1, 'novo' => 1]))
        ->post(route('tutorial-visto'))
        ->assertRedirect(route('home', ['carteira' => $carteira->id, 'mes' => '2026-03']));
});

it('RN-165 a guest cannot mark the tutorial as seen', function () {
    $this->post(route('tutorial-visto'))->assertRedirect(route('login'));
});
