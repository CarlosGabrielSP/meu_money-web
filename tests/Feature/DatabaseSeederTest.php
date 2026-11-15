<?php

use App\Models\User;
use App\Notifications\DefinirSenhaNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('seeds the configured administrator like usuario:admin, with the default wallet and the set-password link, once', function () {
    config(['app.seed_admin' => ['name' => 'Ana Admin', 'email' => 'ana@example.com']]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->sole();

    expect($admin)
        ->name->toBe('Ana Admin')
        ->email->toBe('ana@example.com')
        ->is_admin->toBeTrue()
        ->password->toBeNull()
        ->and($admin->carteiras()->where('padrao', true)->value('nome'))->toBe('Carteira principal');

    Notification::assertSentToTimes($admin, DefinirSenhaNotification::class, 1);
});

it('seeds no administrator and sends nothing when name or email is not configured', function (array $administrador) {
    config(['app.seed_admin' => $administrador]);

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'nothing' => [['name' => null, 'email' => null]],
    'no email' => [['name' => 'Ana Admin', 'email' => '']],
    'no name' => [['name' => '', 'email' => 'ana@example.com']],
]);
