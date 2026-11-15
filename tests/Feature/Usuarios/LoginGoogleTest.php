<?php

use App\Models\User;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * @param  array<string, mixed>  $atributos
 */
function googleVerificado(array $atributos): GoogleUser
{
    return GoogleUser::fake($atributos)->setRaw(['email_verified' => true]);
}

it('redirects to Google', function () {
    Socialite::fake('google');

    $this->get(route('auth.google.redirect'))->assertRedirect();
});

it('CA-005.1 signs in an existing user, stores google_id and verifies the email', function () {
    $user = User::factory()->unverified()->create(['email' => 'dani@example.com']);
    Socialite::fake('google', googleVerificado(['id' => 'google-123', 'name' => 'Dani', 'email' => 'Dani@Example.com']));

    $this->get(route('auth.google.callback'))->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())
        ->google_id->toBe('google-123')
        ->email_verified_at->not->toBeNull();
});

it('CA-005.2 refuses an unknown email and never creates an account', function () {
    Socialite::fake('google', googleVerificado(['id' => 'google-999', 'email' => 'nova@example.com']));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->where('email', 'nova@example.com')->exists())->toBeFalse();
});

it('CA-005.3 refuses a deactivated user', function () {
    User::factory()->create(['email' => 'dani@example.com', 'desativado_em' => now()]);
    Socialite::fake('google', googleVerificado(['id' => 'google-123', 'email' => 'dani@example.com']));

    $this->get(route('auth.google.callback'))->assertSessionHasErrors(['email' => 'Conta desativada.']);

    $this->assertGuest();
});

it('CA-005.4 refuses a Google account whose email is not verified', function () {
    $user = User::factory()->create(['email' => 'dani@example.com']);
    Socialite::fake('google', GoogleUser::fake(['id' => 'google-123', 'email' => 'dani@example.com'])->setRaw(['email_verified' => false]));

    $this->get(route('auth.google.callback'))->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($user->fresh()->google_id)->toBeNull();
});

it('CA-005.5 refuses a Google account already linked to another user', function () {
    User::factory()->create(['email' => 'antigo@example.com', 'google_id' => 'google-123']);
    $user = User::factory()->create(['email' => 'dani@example.com']);
    Socialite::fake('google', googleVerificado(['id' => 'google-123', 'email' => 'dani@example.com']));

    $this->get(route('auth.google.callback'))->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($user->fresh()->google_id)->toBeNull();
});
