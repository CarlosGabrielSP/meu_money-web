<?php

use App\Models\User;
use App\Notifications\DefinirSenhaNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Zuleica Admin', 'is_admin' => true]);
});

it('CA-002.1 creates a user without password, with the default wallet, and emails the link', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), ['nome' => 'Bruno', 'email' => 'bruno@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.usuarios.index'));

    $user = User::query()->where('email', 'bruno@example.com')->sole();

    expect($user->password)->toBeNull()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->carteiras()->sole())
        ->nome->toBe('Carteira principal')
        ->padrao->toBeTrue();

    Notification::assertSentTo($user, DefinirSenhaNotification::class);
});

it('CA-002.2 rejects an email that is already registered', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), ['nome' => 'Outro', 'email' => $this->admin->email])
        ->assertSessionHasErrors('email');
});

it('CA-002.3 CA-086.3 forbids non administrators', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.usuarios.index'))->assertForbidden();
    $this->actingAs($user)
        ->post(route('admin.usuarios.store'), ['nome' => 'X', 'email' => 'x@example.com'])
        ->assertForbidden();
});

it('CA-002.4 has no public registration route', function () {
    $this->get('/register')->assertNotFound();
});

it('CA-006.1 lists accounts with status and admin flag, without financial data', function () {
    User::factory()->create(['name' => 'Desativado', 'desativado_em' => now()]);

    $this->actingAs($this->admin)
        ->get(route('admin.usuarios.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Usuarios')
            ->has('usuarios', 2)
            ->where('usuarios.0', fn ($usuario) => array_keys($usuario->all()) === ['id', 'nome', 'email', 'isAdmin', 'ativo', 'temSenha'])
            ->where('usuarios.0.nome', 'Desativado')
            ->where('usuarios.0.ativo', false)
            ->missing('carteiras'),
        );
});

it('CA-006.2 deactivates the user and ends their sessions', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'sessao-do-usuario', 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.desativar', $user))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->desativado_em)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('CA-006.2 logs out a deactivated user on the next request', function () {
    $user = User::factory()->create(['desativado_em' => now()]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Conta desativada.']);

    $this->assertGuest();
});

it('CA-006.3 reactivates the user', function () {
    $user = User::factory()->create(['desativado_em' => now()]);

    $this->actingAs($this->admin)->post(route('admin.usuarios.reativar', $user));

    expect($user->fresh()->desativado_em)->toBeNull();
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

it('CA-006.4 blocks deactivating or demoting the only active administrator', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.desativar', $this->admin))
        ->assertSessionHasErrors('usuario');

    $this->actingAs($this->admin)
        ->patch(route('admin.usuarios.administrador', $this->admin), ['is_admin' => false])
        ->assertSessionHasErrors('usuario');

    expect($this->admin->fresh())
        ->is_admin->toBeTrue()
        ->desativado_em->toBeNull();
});

it('CA-006.4 counts only active administrators', function () {
    User::factory()->create(['is_admin' => true, 'desativado_em' => now()]);

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.desativar', $this->admin))
        ->assertSessionHasErrors('usuario');
});

it('CA-006.5 grants and revokes the administrator flag', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.usuarios.administrador', $user), ['is_admin' => true]);
    expect($user->fresh()->is_admin)->toBeTrue();

    $this->actingAs($this->admin)->patch(route('admin.usuarios.administrador', $user), ['is_admin' => false]);
    expect($user->fresh()->is_admin)->toBeFalse();
});

it('CA-002.5 keeps the user and warns the administrator when the email fails', function () {
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP fora do ar'));

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), ['nome' => 'Bruno', 'email' => 'bruno@example.com'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning');

    expect(User::query()->where('email', 'bruno@example.com')->exists())->toBeTrue();
});

it('CA-006.6 resends the set-password link only to accounts without password', function () {
    Notification::fake();
    $semSenha = User::factory()->create(['password' => null]);
    $comSenha = User::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.usuarios.reenviar-link', $semSenha))->assertRedirect();
    Notification::assertSentTo($semSenha, DefinirSenhaNotification::class);

    $this->actingAs($this->admin)->post(route('admin.usuarios.reenviar-link', $comSenha))->assertStatus(422);
    Notification::assertNotSentTo($comSenha, DefinirSenhaNotification::class);
});

it('CA-006.7 changes the email, clears verification and emails a confirmation link', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.usuarios.email', $user), ['email' => 'Novo@Example.com'])
        ->assertSessionHasNoErrors();

    expect($user->refresh())
        ->email->toBe('novo@example.com')
        ->email_verified_at->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('CA-006.7 rejects an email already in use regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.usuarios.email', $user), ['email' => 'Taken@Example.com'])
        ->assertSessionHasErrors('email');
});

it('logs a deactivated user out with a 303 on non-GET Inertia requests', function () {
    $user = User::factory()->create(['desativado_em' => now()]);

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->put(route('user-password.update'), [])
        ->assertStatus(303);
});
