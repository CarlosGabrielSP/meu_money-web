<?php

use App\Models\Carteira;
use App\Models\User;

/**
 * Protection against needless traffic before the Laravel Cloud deploy (DEC-094, RNF-026 to
 * RNF-029).
 */
it('RNF-026 closes the robots.txt to every crawler', function () {
    expect(file_get_contents(public_path('robots.txt')))->toBe("User-agent: *\nDisallow: /\n");
});

it('RNF-027 keeps every web page out of search engines', function () {
    $this->get(route('login'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('RNF-028 limits the password reset request to 10 a minute per IP', function () {
    foreach (range(1, 10) as $tentativa) {
        $this->post(route('password.email'), ['email' => "pessoa{$tentativa}@example.com"])->assertStatus(302);
    }

    $this->post(route('password.email'), ['email' => 'outra@example.com'])->assertStatus(429);
});

it('RNF-028 limits the Google login start to 10 a minute per IP', function () {
    foreach (range(1, 10) as $tentativa) {
        expect($this->get(route('auth.google.redirect'))->getStatusCode())->not->toBe(429);
    }

    $this->get(route('auth.google.redirect'))->assertStatus(429);
});

it('RNF-029 limits a signed-in user to 300 requests a minute', function () {
    $user = User::factory()->create();
    Carteira::factory()->for($user)->padrao()->create();

    foreach (range(1, 300) as $requisicao) {
        $this->actingAs($user)->get(route('carteiras.index'))->assertOk();
    }

    $this->actingAs($user)->get(route('carteiras.index'))->assertStatus(429);
});
