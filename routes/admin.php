<?php

use App\Http\Controllers\Admin\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:administrar'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    Route::post('usuarios/{usuario}/desativar', [UsuarioController::class, 'desativar'])->name('usuarios.desativar');
    Route::post('usuarios/{usuario}/reativar', [UsuarioController::class, 'reativar'])->name('usuarios.reativar');
    Route::post('usuarios/{usuario}/reenviar-link', [UsuarioController::class, 'reenviarLink'])->name('usuarios.reenviar-link');
    Route::patch('usuarios/{usuario}/email', [UsuarioController::class, 'alterarEmail'])->name('usuarios.email');
    Route::patch('usuarios/{usuario}/data-nascimento', [UsuarioController::class, 'alterarDataNascimento'])->name('usuarios.data-nascimento');
    Route::patch('usuarios/{usuario}/administrador', [UsuarioController::class, 'alterarAdministrador'])->name('usuarios.administrador');
});
