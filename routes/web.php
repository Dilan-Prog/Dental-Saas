<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check() ? redirect('/dashboard') : redirect()->route('login');
});

// La ruta nombrada "dashboard" la define el modulo Dashboard (routes/modules/dashboard.php).

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// Catalogo de componentes del sistema de diseño (solo entorno local).
if (app()->environment('local')) {
    Route::view('/_styleguide', '_styleguide')->name('styleguide');
}
