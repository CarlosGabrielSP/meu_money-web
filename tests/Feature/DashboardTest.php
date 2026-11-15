<?php

use App\Models\Carteira;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('home'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the main screen', function () {
    $user = User::factory()->create();
    Carteira::factory()->for($user)->padrao()->create();
    $this->actingAs($user);

    $response = $this->get(route('home'));
    $response->assertOk();
});
