<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00'));
    $this->admin = User::factory()->create(['is_admin' => true]);
});

it('CA-110.1 saves the birth date given by the administrator on creation', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), ['nome' => 'Bruno', 'email' => 'bruno@example.com', 'data_nascimento' => '1970-05-10'])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'bruno@example.com')->sole()->data_nascimento->toDateString())->toBe('1970-05-10');
});

it('CA-110.1 creates the user without birth date when none is given', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), ['nome' => 'Bruno', 'email' => 'bruno@example.com', 'data_nascimento' => ''])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'bruno@example.com')->sole()->data_nascimento)->toBeNull();
});

it('CA-110.2 rejects a future date or one before 1900 on creation', function (string $data) {
    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), ['nome' => 'Bruno', 'email' => 'bruno@example.com', 'data_nascimento' => $data])
        ->assertSessionHasErrors('data_nascimento');

    expect(User::query()->where('email', 'bruno@example.com')->exists())->toBeFalse();
})->with(['2026-11-16', '1899-12-31']);

it('CA-110.2 rejects a future date or one before 1900 in the administrator action', function (string $data) {
    $user = User::factory()->create(['data_nascimento' => '1980-01-01']);

    $this->actingAs($this->admin)
        ->patch(route('admin.usuarios.data-nascimento', $user), ['data_nascimento' => $data])
        ->assertSessionHasErrors('data_nascimento');

    expect($user->refresh()->data_nascimento->toDateString())->toBe('1980-01-01');
})->with(['2026-11-16', '1899-12-31']);

it('CA-110.2 rejects a future date or one before 1900 in the profile', function (string $data) {
    $user = User::factory()->create(['data_nascimento' => '1980-01-01']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'data_nascimento' => $data])
        ->assertSessionHasErrors('data_nascimento');

    expect($user->refresh()->data_nascimento->toDateString())->toBe('1980-01-01');
})->with(['2026-11-16', '1899-12-31']);

it('RN-154 accepts today and 1900-01-01 as birth dates', function (string $data) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'data_nascimento' => $data])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->data_nascimento->toDateString())->toBe($data);
})->with(['2026-11-15', '1900-01-01']);

it('CA-110.3 the administrator sets and removes a birth date', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.usuarios.data-nascimento', $user), ['data_nascimento' => '1960-03-02'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.usuarios.index'));

    expect($user->refresh()->data_nascimento->toDateString())->toBe('1960-03-02');

    $this->actingAs($this->admin)
        ->patch(route('admin.usuarios.data-nascimento', $user), ['data_nascimento' => ''])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->data_nascimento)->toBeNull();
});

it('CA-110.4 the user changes the own birth date in the profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'data_nascimento' => '1975-08-20'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->data_nascimento->toDateString())->toBe('1975-08-20');
});

it('CA-110.5 the user list sends neither birth date nor age', function () {
    User::factory()->create(['data_nascimento' => '1970-05-10']);

    $this->actingAs($this->admin)
        ->get(route('admin.usuarios.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Usuarios')
            ->where('usuarios', fn ($usuarios) => collect($usuarios)->every(
                fn (array $usuario): bool => array_keys($usuario) === ['id', 'nome', 'email', 'isAdmin', 'ativo', 'temSenha'],
            )));
});

it('CA-110.6 a non administrator cannot change the birth date of another user', function () {
    $comum = User::factory()->create();
    $outro = User::factory()->create(['data_nascimento' => '1980-01-01']);

    $this->actingAs($comum)
        ->patch(route('admin.usuarios.data-nascimento', $outro), ['data_nascimento' => '1990-01-01'])
        ->assertForbidden();

    expect($outro->refresh()->data_nascimento->toDateString())->toBe('1980-01-01');
});
