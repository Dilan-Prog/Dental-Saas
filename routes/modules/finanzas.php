<?php

use App\Http\Controllers\Finanzas\CashController;
use App\Http\Controllers\Finanzas\CategoryController;
use App\Http\Controllers\Finanzas\FinanzasController;
use App\Http\Controllers\Finanzas\MovementController;
use Illuminate\Support\Facades\Route;

/*
| Modulo Finanzas (se carga dentro de web + auth + active.member).
| Permisos: finanzas.{ver,crear,editar,desactivar,exportar,cerrar_caja}
*/
Route::prefix('finanzas')->group(function () {
    // Resumen y transacciones
    Route::get('/', [FinanzasController::class, 'index'])->name('finanzas.index')->middleware('permission:finanzas,ver');
    Route::get('/exportar', [FinanzasController::class, 'export'])->name('finanzas.export')->middleware('permission:finanzas,exportar');

    Route::post('/movimientos', [MovementController::class, 'store'])->name('finanzas.movimientos.store')->middleware('permission:finanzas,crear');
    Route::post('/movimientos/{movement}/revertir', [MovementController::class, 'reverse'])->name('finanzas.movimientos.reverse')->middleware('permission:finanzas,editar');
    Route::get('/movimientos/{movement}/comprobante', [MovementController::class, 'receipt'])->name('finanzas.movimientos.receipt')->middleware('permission:finanzas,ver');

    // Cajas y turnos
    Route::get('/cajas', [CashController::class, 'index'])->name('finanzas.cajas.index')->middleware('permission:finanzas,ver');
    Route::get('/cajas/turnos/{session}', [CashController::class, 'show'])->name('finanzas.cajas.show')->middleware('permission:finanzas,ver');
    Route::post('/cajas', [CashController::class, 'storeRegister'])->name('finanzas.cajas.store')->middleware('permission:finanzas,crear');
    Route::put('/cajas/{register}', [CashController::class, 'updateRegister'])->name('finanzas.cajas.update')->middleware('permission:finanzas,editar');
    Route::post('/turnos/abrir', [CashController::class, 'open'])->name('finanzas.turnos.open')->middleware('permission:finanzas,crear');
    Route::post('/turnos/{session}/cerrar', [CashController::class, 'close'])->name('finanzas.turnos.close')->middleware('permission:finanzas,cerrar_caja');
    Route::post('/turnos/{session}/ajustes', [CashController::class, 'adjust'])->name('finanzas.turnos.adjust')->middleware('permission:finanzas,cerrar_caja');

    // Categorias
    Route::get('/categorias', [CategoryController::class, 'index'])->name('finanzas.categorias.index')->middleware('permission:finanzas,ver');
    Route::post('/categorias', [CategoryController::class, 'store'])->name('finanzas.categorias.store')->middleware('permission:finanzas,crear');
    Route::put('/categorias/{category}', [CategoryController::class, 'update'])->name('finanzas.categorias.update')->middleware('permission:finanzas,editar');
    Route::patch('/categorias/{category}/estado', [CategoryController::class, 'toggle'])->name('finanzas.categorias.toggle')->middleware('permission:finanzas,desactivar');
});
