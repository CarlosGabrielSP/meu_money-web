<?php

use App\Models\User;
use App\Notifications\DefinirSenhaNotification;
use Illuminate\Support\Facades\Notification;

it('CA-001.1 creates an administrator without password and emails the set-password link', function () {
    Notification::fake();

    $this->artisan('usuario:admin', ['nome' => 'Ana Admin', 'email' => 'ana@example.com'])
        ->assertSuccessful();

    $admin = User::query()->where('email', 'ana@example.com')->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and($admin->password)->toBeNull()
        ->and($admin->carteiras()->where('padrao', true)->count())->toBe(1);

    Notification::assertSentTo($admin, DefinirSenhaNotification::class);
});

it('CA-001.2 fails without creating anything when the email is taken', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ana@example.com']);

    $this->artisan('usuario:admin', ['nome' => 'Outra Ana', 'email' => 'ana@example.com'])
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
    Notification::assertNothingSent();
});
