<?php

use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;

it('CA-007.1 deletes all own data in an order the foreign keys accept', function () {
    $user = User::factory()->create();
    $lancamento = Lancamento::factory()->for($user)->create();
    $cota = Cota::factory()->for($lancamento)->create();
    $parcelamento = Parcelamento::factory()->for($user)->create(['carteira_id' => $cota->carteira_id, 'categoria_id' => $cota->categoria_id]);
    Grupo::factory()->for($user)->create(['carteira_id' => $cota->carteira_id]);
    $user->forceFill(['ultima_carteira_id' => $cota->carteira_id])->save();
    $outroUsuario = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->fresh())->toBeNull()
        ->and(Lancamento::query()->count())->toBe(0)
        ->and(Cota::query()->count())->toBe(0)
        ->and($parcelamento->fresh())->toBeNull()
        ->and($user->carteiras()->count())->toBe(0)
        ->and($outroUsuario->fresh())->not->toBeNull();
});

it('CA-007.5 blocks deletion of the last active administrator', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect($admin->fresh())->not->toBeNull();
    $this->assertAuthenticatedAs($admin);
});

it('CA-007.6 confirms deletion of an account without password by typing the own email', function () {
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['confirmacao_email' => 'outro@example.com'])
        ->assertSessionHasErrors('confirmacao_email');
    expect($user->fresh())->not->toBeNull();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['confirmacao_email' => $user->email])
        ->assertSessionHasNoErrors();
    expect($user->fresh())->toBeNull();
});
