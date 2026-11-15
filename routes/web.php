<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CarteiraController;
use App\Http\Controllers\CategoriaController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::resource('carteiras', CarteiraController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('carteiras/{carteira}/padrao', [CarteiraController::class, 'definirPadrao'])->name('carteiras.padrao');

    Route::resource('categorias', CategoriaController::class)->only(['index', 'store', 'update', 'destroy']);
});

Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
