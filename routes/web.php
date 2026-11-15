<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CarteiraController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CotaController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\LancamentoAnexoController;
use App\Http\Controllers\LancamentoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', InicioController::class)->name('home');

    Route::resource('lancamentos', LancamentoController::class)->only(['store', 'update', 'destroy']);
    Route::get('lancamentos/{lancamento}/anexo', [LancamentoAnexoController::class, 'show'])->name('lancamentos.anexo.show');
    Route::delete('lancamentos/{lancamento}/anexo', [LancamentoAnexoController::class, 'destroy'])->name('lancamentos.anexo.destroy');
    Route::patch('cotas/{cota}/executado', [CotaController::class, 'alternarExecutado'])->name('cotas.executado');

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
