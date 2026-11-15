<?php

use App\Actions\Usuarios\CriarUsuario;
use App\Models\User;
use App\Notifications\DefinirSenhaNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

it('CA-003.1 sets the password, verifies the email and signs the user in', function () {
    Notification::fake();
    [$user] = app(CriarUsuario::class)->handle('Carla', 'carla@example.com');

    Notification::assertSentTo($user, DefinirSenhaNotification::class, function (DefinirSenhaNotification $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'senha-segura-123',
            'password_confirmation' => 'senha-segura-123',
        ])->assertSessionHasNoErrors()->assertRedirect(config('fortify.home'));

        return true;
    });

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('CA-003.2 rejects an invalid or expired link and offers a new one', function () {
    $user = User::factory()->unverified()->create(['password' => null]);

    $this->from(route('password.reset', ['token' => 'invalido']))
        ->post(route('password.update'), [
            'token' => 'invalido',
            'email' => $user->email,
            'password' => 'senha-segura-123',
            'password_confirmation' => 'senha-segura-123',
        ])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->password)->toBeNull();
    $this->get(route('password.request'))->assertOk();
    $this->assertGuest();
});

it('CA-003.2 shows the error and the new-link option as soon as an invalid link is opened', function () {
    $user = User::factory()->create();

    $this->get(route('password.reset', ['token' => 'invalido', 'email' => $user->email]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/ResetPassword')
            ->where('tokenValido', false)
            ->where('temSenha', false),
        );
});

it('CA-003.3 titles a valid link page "Definir senha" without a password and "Redefinir senha" with one', function () {
    Notification::fake();
    [$user] = app(CriarUsuario::class)->handle('Carla', 'carla@example.com');
    $comSenha = User::factory()->create();

    Notification::assertSentTo($user, DefinirSenhaNotification::class, function (DefinirSenhaNotification $notification) use ($user) {
        $this->get(route('password.reset', ['token' => $notification->token, 'email' => $user->email]))
            ->assertInertia(fn (Assert $page) => $page->where('tokenValido', true)->where('temSenha', false));

        return true;
    });

    $this->get(route('password.reset', ['token' => Password::createToken($comSenha), 'email' => $comSenha->email]))
        ->assertInertia(fn (Assert $page) => $page->where('tokenValido', true)->where('temSenha', true));
});

it('does not reveal account details without a valid token', function () {
    $comSenha = User::factory()->create();

    $this->get(route('password.reset', ['token' => 'invalido', 'email' => $comSenha->email]))
        ->assertInertia(fn (Assert $page) => $page->where('tokenValido', false)->where('temSenha', false));
});

it('sends a deactivated user to the login page with the reason after the reset', function () {
    Notification::fake();
    $user = User::factory()->create(['desativado_em' => now()]);
    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'senha-segura-123',
            'password_confirmation' => 'senha-segura-123',
        ])->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'Conta desativada.']);

        return true;
    });

    $this->assertGuest();
});
