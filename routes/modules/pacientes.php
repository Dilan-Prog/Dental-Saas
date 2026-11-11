<?php

use App\Http\Controllers\Pacientes\PatientController;
use App\Http\Controllers\Pacientes\PatientFiscalProfileController;
use App\Http\Controllers\Pacientes\PatientGuardianController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Pacientes. Ya dentro de web + auth + active.member (RouteServiceProvider).
| Permisos (módulo "pacientes"): ver, crear, editar, desactivar, exportar.
*/
Route::prefix('pacientes')->name('pacientes.')->group(function () {
    Route::get('/', [PatientController::class, 'index'])
        ->name('index')->middleware('permission:pacientes,ver');

    // Debe ir antes de {patient} para no ser capturada como id.
    Route::get('exportar', [PatientController::class, 'export'])
        ->name('export')->middleware('permission:pacientes,exportar');

    Route::post('/', [PatientController::class, 'store'])
        ->name('store')->middleware('permission:pacientes,crear');

    Route::get('{patient}', [PatientController::class, 'show'])
        ->name('show')->whereUuid('patient')->withTrashed()->middleware('permission:pacientes,ver');

    Route::put('{patient}', [PatientController::class, 'update'])
        ->name('update')->whereUuid('patient')->middleware('permission:pacientes,editar');

    // Baja lógica (nunca borrado físico).
    Route::delete('{patient}', [PatientController::class, 'destroy'])
        ->name('destroy')->whereUuid('patient')->middleware('permission:pacientes,desactivar');

    Route::post('{patient}/restaurar', [PatientController::class, 'restore'])
        ->name('restore')->whereUuid('patient')->withTrashed()->middleware('permission:pacientes,desactivar');

    // Solo administradores (se valida también en el FormRequest).
    Route::post('{patient}/anonimizar', [PatientController::class, 'anonymize'])
        ->name('anonymize')->whereUuid('patient')->withTrashed()->middleware('permission:pacientes,desactivar');

    // Datos fiscales (crea o edita según profile_id).
    Route::post('{patient}/fiscal', [PatientFiscalProfileController::class, 'store'])
        ->name('fiscal.store')->whereUuid('patient')->middleware('permission:pacientes,editar');
    Route::post('{patient}/fiscal/{profile}/predeterminado', [PatientFiscalProfileController::class, 'makeDefault'])
        ->name('fiscal.default')->whereUuid(['patient', 'profile'])->middleware('permission:pacientes,editar');
    Route::delete('{patient}/fiscal/{profile}', [PatientFiscalProfileController::class, 'destroy'])
        ->name('fiscal.destroy')->whereUuid(['patient', 'profile'])->middleware('permission:pacientes,editar');

    // Tutores (crea o edita según guardian_id).
    Route::post('{patient}/tutores', [PatientGuardianController::class, 'store'])
        ->name('guardians.store')->whereUuid('patient')->middleware('permission:pacientes,editar');
    Route::delete('{patient}/tutores/{guardian}', [PatientGuardianController::class, 'destroy'])
        ->name('guardians.destroy')->whereUuid(['patient', 'guardian'])->middleware('permission:pacientes,editar');
});
