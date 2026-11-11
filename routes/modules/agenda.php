<?php

use App\Http\Controllers\Agenda\AgendaController;
use App\Http\Controllers\Agenda\AppointmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo Agenda (citas)
|--------------------------------------------------------------------------
| Cargado por RouteServiceProvider con web + auth + active.member.
| Nombres: agenda.index, agenda.patients, agenda.slots, citas.*
*/

Route::prefix('agenda')->name('agenda.')->middleware('permission:agenda,ver')->group(function () {
    Route::get('/', [AgendaController::class, 'index'])->name('index');
    Route::get('pacientes', [AgendaController::class, 'patients'])->name('patients');
    Route::get('disponibilidad', [AgendaController::class, 'slots'])->name('slots');
});

Route::prefix('citas')->name('citas.')->group(function () {
    Route::post('/', [AppointmentController::class, 'store'])->middleware('permission:agenda,crear')->name('store');
    Route::get('{appointment}', [AppointmentController::class, 'show'])->middleware('permission:agenda,ver')->name('show');

    Route::middleware('permission:agenda,editar')->group(function () {
        Route::put('{appointment}', [AppointmentController::class, 'update'])->name('update');
        Route::post('{appointment}/reagendar', [AppointmentController::class, 'reschedule'])->name('reschedule');
        Route::post('{appointment}/cancelar', [AppointmentController::class, 'cancel'])->name('cancel');
        Route::post('{appointment}/confirmar', [AppointmentController::class, 'confirm'])->name('confirm');
        Route::post('{appointment}/iniciar', [AppointmentController::class, 'start'])->name('start');
        Route::post('{appointment}/completar', [AppointmentController::class, 'complete'])->name('complete');
        Route::post('{appointment}/no-asistio', [AppointmentController::class, 'noshow'])->name('noshow');
    });
});
