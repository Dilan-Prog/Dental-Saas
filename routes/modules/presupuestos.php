<?php

use App\Http\Controllers\Presupuestos\QuoteController;
use Illuminate\Support\Facades\Route;

/*
| Presupuestos (planes de tratamiento y cotizaciones).
| Se carga dentro de web + auth + active.member (ver RouteServiceProvider).
*/
Route::prefix('presupuestos')->group(function () {
    Route::get('/', [QuoteController::class, 'index'])
        ->name('presupuestos.index')->middleware('permission:presupuestos,ver');

    Route::get('nuevo', [QuoteController::class, 'create'])
        ->name('presupuestos.create')->middleware('permission:presupuestos,crear');

    // Búsqueda de pacientes para el formulario (JSON).
    Route::get('pacientes', [QuoteController::class, 'patients'])
        ->name('presupuestos.patients')->middleware('permission:presupuestos,crear,editar');

    Route::post('/', [QuoteController::class, 'store'])
        ->name('presupuestos.store')->middleware('permission:presupuestos,crear');

    Route::get('{quote}', [QuoteController::class, 'show'])
        ->name('presupuestos.show')->middleware('permission:presupuestos,ver');

    Route::get('{quote}/editar', [QuoteController::class, 'edit'])
        ->name('presupuestos.edit')->middleware('permission:presupuestos,editar');

    Route::put('{quote}', [QuoteController::class, 'update'])
        ->name('presupuestos.update')->middleware('permission:presupuestos,editar');

    Route::get('{quote}/imprimir', [QuoteController::class, 'print'])
        ->name('presupuestos.print')->middleware('permission:presupuestos,ver');

    Route::post('{quote}/enviar', [QuoteController::class, 'send'])
        ->name('presupuestos.send')->middleware('permission:presupuestos,editar');

    Route::post('{quote}/aprobar', [QuoteController::class, 'approve'])
        ->name('presupuestos.approve')->middleware('permission:presupuestos,editar');

    Route::post('{quote}/rechazar', [QuoteController::class, 'reject'])
        ->name('presupuestos.reject')->middleware('permission:presupuestos,editar');

    Route::post('{quote}/reabrir', [QuoteController::class, 'reopen'])
        ->name('presupuestos.reopen')->middleware('permission:presupuestos,editar');

    Route::delete('{quote}', [QuoteController::class, 'destroy'])
        ->name('presupuestos.destroy')->middleware('permission:presupuestos,desactivar');
});
