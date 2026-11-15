<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

it('CA-008.1 opens the security page without password confirmation for an account without password', function () {
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Security')->where('auth.temSenha', false));
});

it('CA-008.2 sets a password without the current one', function () {
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'password' => 'senha-segura-123',
            'password_confirmation' => 'senha-segura-123',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('senha-segura-123', $user->fresh()->password))->toBeTrue();
});
