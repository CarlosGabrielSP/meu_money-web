<?php

use App\Models\User;

it('CA-004.2 refuses login of a deactivated account with valid credentials', function () {
    $user = User::factory()->create(['desativado_em' => now()]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Conta desativada.']);

    $this->assertGuest();
});

it('does not reveal deactivation when the password is wrong', function () {
    $user = User::factory()->create(['desativado_em' => now()]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'errada'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
});

it('refuses password login for an account without password', function () {
    $user = User::factory()->create(['password' => null]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => ''])
        ->assertSessionHasErrors();

    $this->assertGuest();
});
