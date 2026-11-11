<?php

use App\Http\Controllers\Recetas\MedicationController;
use App\Http\Controllers\Recetas\PrescriptionController;
use Illuminate\Support\Facades\Route;

/*
| Módulo RECETAS. Se carga solo (web + auth + active.member) desde
| routes/modules/*.php. Los permisos son (módulo = recetas, acción).
*/

Route::pattern('prescription', '[0-9a-fA-F-]{36}');

Route::prefix('recetas')->group(function () {
    // Listado (y receta seleccionada)
    Route::get('/', [PrescriptionController::class, 'index'])
        ->middleware('permission:recetas,ver')->name('recetas.index');

    // Crear
    Route::get('/crear', [PrescriptionController::class, 'create'])
        ->middleware('permission:recetas,crear')->name('recetas.create');
    Route::post('/', [PrescriptionController::class, 'store'])
        ->middleware('permission:recetas,crear')->name('recetas.store');

    // Typeahead (JSON)
    Route::get('/pacientes/buscar', [PrescriptionController::class, 'searchPatients'])
        ->middleware('permission:recetas,ver')->name('recetas.pacientes.buscar');

    // Catálogo de medicamentos (antes de {prescription} para no chocar)
    Route::prefix('medicamentos')->group(function () {
        Route::get('/', [MedicationController::class, 'index'])
            ->middleware('permission:recetas,ver')->name('recetas.medicamentos.index');
        Route::get('/buscar', [MedicationController::class, 'search'])
            ->middleware('permission:recetas,ver')->name('recetas.medicamentos.buscar');
        Route::post('/', [MedicationController::class, 'store'])
            ->middleware('permission:recetas,crear')->name('recetas.medicamentos.store');
        Route::put('/{medication}', [MedicationController::class, 'update'])
            ->middleware('permission:recetas,editar')->name('recetas.medicamentos.update')
            ->whereUuid('medication');
        Route::patch('/{medication}/estado', [MedicationController::class, 'toggle'])
            ->middleware('permission:recetas,editar')->name('recetas.medicamentos.toggle')
            ->whereUuid('medication');
    });

    // Una receta
    Route::middleware('permission:recetas,ver')->group(function () {
        Route::get('/{prescription}', [PrescriptionController::class, 'show'])->name('recetas.show');
        Route::get('/{prescription}/imprimir', [PrescriptionController::class, 'print'])->name('recetas.print');
        Route::get('/{prescription}/pdf', [PrescriptionController::class, 'pdf'])->name('recetas.pdf');
    });
    Route::middleware('permission:recetas,editar')->group(function () {
        Route::get('/{prescription}/editar', [PrescriptionController::class, 'edit'])->name('recetas.edit');
        Route::put('/{prescription}', [PrescriptionController::class, 'update'])->name('recetas.update');
        Route::delete('/{prescription}', [PrescriptionController::class, 'destroy'])->name('recetas.destroy');
        Route::post('/{prescription}/enviar', [PrescriptionController::class, 'send'])->name('recetas.send');
    });
    Route::post('/{prescription}/emitir', [PrescriptionController::class, 'emit'])
        ->middleware('permission:recetas,emitir_recetas')->name('recetas.emit');
    Route::post('/{prescription}/repetir', [PrescriptionController::class, 'repeat'])
        ->middleware('permission:recetas,crear')->name('recetas.repeat');
});
