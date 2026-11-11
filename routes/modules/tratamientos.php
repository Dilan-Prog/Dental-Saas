<?php

use App\Http\Controllers\Tratamientos\TaxProfileController;
use App\Http\Controllers\Tratamientos\TreatmentCategoryController;
use App\Http\Controllers\Tratamientos\TreatmentController;
use Illuminate\Support\Facades\Route;

/*
| Catálogo de tratamientos (Configuración > Tratamientos), categorías e impuestos.
| Se carga dentro de web + auth + active.member (ver RouteServiceProvider).
*/
Route::prefix('tratamientos')->group(function () {
    Route::get('/', [TreatmentController::class, 'index'])
        ->name('tratamientos.index')->middleware('permission:tratamientos,ver');

    Route::post('/', [TreatmentController::class, 'store'])
        ->name('tratamientos.store')->middleware('permission:tratamientos,crear');

    // Categorías
    Route::post('categorias', [TreatmentCategoryController::class, 'store'])
        ->name('tratamientos.categorias.store')->middleware('permission:tratamientos,crear');
    Route::put('categorias/{category}', [TreatmentCategoryController::class, 'update'])
        ->name('tratamientos.categorias.update')->middleware('permission:tratamientos,editar');
    Route::delete('categorias/{category}', [TreatmentCategoryController::class, 'destroy'])
        ->name('tratamientos.categorias.destroy')->middleware('permission:tratamientos,desactivar');

    // Perfiles de impuestos
    Route::post('impuestos', [TaxProfileController::class, 'store'])
        ->name('tratamientos.impuestos.store')->middleware('permission:tratamientos,crear');
    Route::put('impuestos/{taxProfile}', [TaxProfileController::class, 'update'])
        ->name('tratamientos.impuestos.update')->middleware('permission:tratamientos,editar');
    Route::patch('impuestos/{taxProfile}/estado', [TaxProfileController::class, 'toggle'])
        ->name('tratamientos.impuestos.toggle')->middleware('permission:tratamientos,desactivar');

    // Tratamientos
    Route::put('{treatment}', [TreatmentController::class, 'update'])
        ->name('tratamientos.update')->middleware('permission:tratamientos,editar');
    Route::patch('{treatment}/estado', [TreatmentController::class, 'toggle'])
        ->name('tratamientos.toggle')->middleware('permission:tratamientos,desactivar');
    Route::delete('{treatment}', [TreatmentController::class, 'destroy'])
        ->name('tratamientos.destroy')->middleware('permission:tratamientos,desactivar');
});
