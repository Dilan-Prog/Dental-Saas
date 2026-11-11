<?php

use App\Http\Controllers\Expediente\ClinicalFileController;
use App\Http\Controllers\Expediente\ClinicalNoteController;
use App\Http\Controllers\Expediente\DocumentController;
use App\Http\Controllers\Expediente\ExpedienteController;
use App\Http\Controllers\Expediente\PatientTreatmentController;
use App\Http\Controllers\Expediente\ToothFindingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo Expediente clínico + Documentos
|--------------------------------------------------------------------------
| Se carga dentro de web + auth + active.member (ver RouteServiceProvider).
| Nombres de enlace cruzado: expediente.show {patient}, documentos.index.
*/

// Descarga de archivos clínicos: URL firmada de corta vida (no hay acceso público al disco).
Route::get('expediente/archivos/{file}/descargar', [ClinicalFileController::class, 'download'])
    ->middleware(['signed', 'permission:expediente,ver'])
    ->name('expediente.archivos.download');

Route::prefix('expediente/{patient}')->name('expediente.')->group(function () {
    Route::get('/', [ExpedienteController::class, 'show'])->middleware('permission:expediente,ver')->name('show');

    Route::put('historial', [ExpedienteController::class, 'updateHistory'])->middleware('permission:expediente,editar')->name('historial.update');
    Route::put('datos', [ExpedienteController::class, 'updatePatient'])->middleware('permission:pacientes,editar')->name('datos.update');

    // Notas clínicas
    Route::get('notas/nueva', [ClinicalNoteController::class, 'create'])->middleware('permission:expediente,crear')->name('notas.create');
    Route::post('notas', [ClinicalNoteController::class, 'store'])->middleware('permission:expediente,crear')->name('notas.store');
    Route::get('notas/{note}', [ClinicalNoteController::class, 'show'])->middleware('permission:expediente,ver')->name('notas.show');
    Route::put('notas/{note}', [ClinicalNoteController::class, 'update'])->middleware('permission:expediente,editar')->name('notas.update');
    Route::post('notas/{note}/firmar', [ClinicalNoteController::class, 'sign'])->middleware('permission:expediente,firmar_notas')->name('notas.sign');
    Route::post('notas/{note}/adendas', [ClinicalNoteController::class, 'addendum'])->middleware('permission:expediente,firmar_notas')->name('notas.addendum');
    Route::delete('notas/{note}', [ClinicalNoteController::class, 'destroy'])->middleware('permission:expediente,editar')->name('notas.destroy');

    // Archivos
    Route::post('archivos', [ClinicalFileController::class, 'store'])->middleware('permission:expediente,crear')->name('archivos.store');
    Route::delete('archivos/{file}', [ClinicalFileController::class, 'destroy'])->middleware('permission:expediente,desactivar')->name('archivos.destroy');

    // Odontograma
    Route::post('odontograma', [ToothFindingController::class, 'store'])->middleware('permission:expediente,editar')->name('odontograma.store');

    // Tratamientos del paciente
    Route::post('tratamientos', [PatientTreatmentController::class, 'store'])->middleware('permission:expediente,crear')->name('tratamientos.store');
    Route::patch('tratamientos/{treatment}/estado', [PatientTreatmentController::class, 'status'])->middleware('permission:expediente,editar')->name('tratamientos.status');
    Route::delete('tratamientos/{treatment}', [PatientTreatmentController::class, 'destroy'])->middleware('permission:expediente,desactivar')->name('tratamientos.destroy');

    // Consentimientos
    Route::post('consentimientos', [ExpedienteController::class, 'storeConsent'])->middleware('permission:expediente,crear')->name('consentimientos.store');
});

// Documentos clínicos (plantillas imprimibles)
Route::prefix('documentos')->name('documentos.')->group(function () {
    Route::get('/', [DocumentController::class, 'index'])->middleware('permission:documentos,ver')->name('index');
    Route::get('ver/{file}', [DocumentController::class, 'show'])->middleware('permission:documentos,ver')->name('show');
    Route::get('{template}/crear', [DocumentController::class, 'create'])->middleware('permission:documentos,crear')->name('create')
        ->where('template', 'historia-clinica|autorizacion-tratamiento');
    Route::get('{template}/vista-previa', [DocumentController::class, 'preview'])->middleware('permission:documentos,ver')->name('preview')
        ->where('template', 'historia-clinica|autorizacion-tratamiento');
    Route::post('{template}', [DocumentController::class, 'store'])->middleware('permission:documentos,crear')->name('store')
        ->where('template', 'historia-clinica|autorizacion-tratamiento');
});
