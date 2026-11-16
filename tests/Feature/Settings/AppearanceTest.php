<?php

use App\Enums\Paleta;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('CA-100.1 a user who never chose a palette sees the six palettes with Neutro selected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Appearance')
            ->where('paletas', fn ($paletas) => collect($paletas)->pluck('valor')->all() === ['neutro', 'esmeralda', 'ardosia', 'obsidiana', 'areia', 'grafite'])
            ->where('paletas.1', ['valor' => 'esmeralda', 'nome' => 'Esmeralda'])
            ->where('auth.user.paleta', 'neutro'),
        );
});

test('CA-100.2 choosing Esmeralda saves the palette in the account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('appearance.update'), ['paleta' => 'esmeralda'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('appearance.edit'));

    expect($user->refresh()->paleta)->toBe(Paleta::Esmeralda);
});

test('CA-100.3 any authenticated page comes from the server with the account palette', function (string $rota) {
    $user = User::factory()->create(['paleta' => Paleta::Esmeralda]);

    $this->actingAs($user)
        ->get(route($rota))
        ->assertOk()
        ->assertSee('data-paleta="esmeralda"', false);
})->with(['profile.edit', 'appearance.edit']);

test('CA-100.4 rejects a palette outside the list and keeps the current one', function () {
    $user = User::factory()->create(['paleta' => Paleta::Ardosia]);

    $this->actingAs($user)
        ->patch(route('appearance.update'), ['paleta' => 'roxo'])
        ->assertSessionHasErrors(['paleta' => 'O valor selecionado para o campo paleta é inválido.']);

    expect($user->refresh()->paleta)->toBe(Paleta::Ardosia);
});

test('RNF-020 sends guests who try to change the palette to the login page', function () {
    $this->patch(route('appearance.update'), ['paleta' => 'esmeralda'])
        ->assertRedirect(route('login'));
});

test('CA-100.5 a visitor sees the login page with the Neutro palette', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-paleta="neutro"', false);
});

test('CA-100.6 the browser bar uses the main background of the palette in the active mode', function (string $aparencia, string $cor) {
    $user = User::factory()->create(['paleta' => Paleta::Esmeralda]);

    $this->actingAs($user)
        ->withUnencryptedCookie('appearance', $aparencia)
        ->get(route('profile.edit'))
        ->assertSee('<meta name="theme-color" content="'.$cor.'">', false);
})->with([
    'dark' => ['dark', '#09110e'],
    'light' => ['light', '#f6f9f8'],
]);

test('CA-100.6 the browser bar turns to the palette dark background before the app loads when the system is dark', function () {
    $user = User::factory()->create(['paleta' => Paleta::Esmeralda]);

    $this->actingAs($user)
        ->withUnencryptedCookie('appearance', 'system')
        ->get(route('profile.edit'))
        ->assertSeeInOrder([
            '<meta name="theme-color" content="#f6f9f8">',
            'if (prefersDark) {',
            "document.querySelector('meta[name=\"theme-color\"]')?.setAttribute('content', '#09110e');",
        ], false);
});

test('RN-152 a stored palette outside the list falls back to Neutro instead of breaking the pages', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['paleta' => 'roxo']);

    $this->actingAs($user->refresh())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('data-paleta="neutro"', false)
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.paleta', 'neutro'));
});

test('DEC-062 the browser bar color of each palette matches its main background in app.css', function (Paleta $paleta) {
    $css = file_get_contents(resource_path('css/app.css'));
    $seletores = $paleta === Paleta::Neutro
        ? ['claro' => ":root,\n[data-paleta='neutro']", 'escuro' => '.dark']
        : ['claro' => "[data-paleta='{$paleta->value}']", 'escuro' => "[data-paleta='{$paleta->value}'].dark"];

    foreach ($seletores as $modo => $seletor) {
        preg_match('/^'.preg_quote($seletor, '/').' \{(.*?)\}/ms', $css, $bloco);

        expect($bloco)->not->toBeEmpty()
            ->and($bloco[1])->toContain("--theme-color: {$paleta->fundo()[$modo]};");

        if ($paleta !== Paleta::Neutro) {
            expect($bloco[1])->toContain("--background: {$paleta->fundo()[$modo]};");
        }
    }
})->with(Paleta::cases());
