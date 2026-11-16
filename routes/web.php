<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CarteiraController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ContatoController;
use App\Http\Controllers\CotaController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\LancamentoAnexoController;
use App\Http\Controllers\LancamentoController;
use App\Http\Controllers\ParcelamentoController;
use App\Http\Controllers\PrimeiroAcessoController;
use App\Http\Controllers\RecebidaController;
use App\Http\Controllers\TutorialVistoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', InicioController::class)->name('home');
    Route::post('primeiro-acesso', PrimeiroAcessoController::class)->name('primeiro-acesso');
    Route::post('tutorial-visto', TutorialVistoController::class)->name('tutorial-visto');

    Route::resource('lancamentos', LancamentoController::class)->only(['store', 'update', 'destroy']);
    Route::get('lancamentos/{lancamento}/anexo', [LancamentoAnexoController::class, 'show'])->name('lancamentos.anexo.show');
    Route::delete('lancamentos/{lancamento}/anexo', [LancamentoAnexoController::class, 'destroy'])->name('lancamentos.anexo.destroy');
    Route::post('parcelamentos/{parcelamento}/encerrar', [ParcelamentoController::class, 'encerrar'])->name('parcelamentos.encerrar');
    Route::patch('parcelamentos/{parcelamento}/total', [ParcelamentoController::class, 'alterarTotal'])->name('parcelamentos.total');
    Route::patch('cotas/{cota}/executado', [CotaController::class, 'alternarExecutado'])->name('cotas.executado');
    Route::put('cotas/{cota}', [CotaController::class, 'update'])->name('cotas.update');
    Route::delete('cotas/{cota}', [CotaController::class, 'destroy'])->name('cotas.destroy');
    Route::post('grupos/agrupar', [GrupoController::class, 'agrupar'])->name('grupos.agrupar');
    Route::post('grupos/desagrupar', [GrupoController::class, 'desagrupar'])->name('grupos.desagrupar');
    Route::patch('grupos/{grupo}/executado', [GrupoController::class, 'executar'])->name('grupos.executado');
    Route::patch('grupos/{grupo}', [GrupoController::class, 'update'])->name('grupos.update');
    Route::delete('grupos/{grupo}', [GrupoController::class, 'destroy'])->name('grupos.destroy');
    Route::get('recebidas', [RecebidaController::class, 'index'])->name('recebidas.index');

    Route::resource('carteiras', CarteiraController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('carteiras/{carteira}/padrao', [CarteiraController::class, 'definirPadrao'])->name('carteiras.padrao');

    Route::resource('categorias', CategoriaController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('contatos', [ContatoController::class, 'index'])->name('contatos.index');
    Route::post('contatos', [ContatoController::class, 'store'])->name('contatos.store');
    Route::post('contatos/{contato}/aceitar', [ContatoController::class, 'aceitar'])->name('contatos.aceitar');
    Route::post('contatos/{contato}/recusar', [ContatoController::class, 'recusar'])->name('contatos.recusar');
    Route::delete('contatos/{contato}', [ContatoController::class, 'destroy'])->name('contatos.destroy');
});

Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
