<?php

use App\Http\Controllers\Configuracion\ClinicaController;
use App\Http\Controllers\Configuracion\FacturacionController;
use App\Http\Controllers\Configuracion\NotificacionesController;
use App\Http\Controllers\Configuracion\PerfilController;
use App\Http\Controllers\Configuracion\SucursalesController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Configuración (se carga con web + auth + active.member).
| Mi perfil solo exige sesión: cualquier usuario puede editar sus datos.
*/

Route::prefix('configuracion')->group(function () {
    // Clínica (Datos de la clínica)
    Route::get('/', [ClinicaController::class, 'show'])->name('configuracion.index')->middleware('permission:configuracion,ver');
    Route::put('clinica', [ClinicaController::class, 'update'])->name('configuracion.clinica.update')->middleware('permission:configuracion,editar');
    Route::get('clinica/logo', [ClinicaController::class, 'logo'])->name('configuracion.clinica.logo');

    // Facturación (datos fiscales del emisor)
    Route::get('facturacion', [FacturacionController::class, 'show'])->name('configuracion.facturacion')->middleware('permission:configuracion,ver');
    Route::put('facturacion', [FacturacionController::class, 'update'])->name('configuracion.facturacion.update')->middleware('permission:configuracion,editar');
    Route::put('facturacion/folios', [FacturacionController::class, 'folios'])->name('configuracion.facturacion.folios')->middleware('permission:configuracion,editar');

    // Notificaciones
    Route::get('notificaciones', [NotificacionesController::class, 'show'])->name('configuracion.notificaciones')->middleware('permission:configuracion,ver');
    Route::put('notificaciones', [NotificacionesController::class, 'update'])->name('configuracion.notificaciones.update')->middleware('permission:configuracion,editar');

    // Sucursales (solo Owner: se valida además en el controlador)
    Route::get('sucursales', [SucursalesController::class, 'index'])->name('configuracion.sucursales')->middleware('permission:configuracion,ver');
    Route::post('sucursales', [SucursalesController::class, 'store'])->name('configuracion.sucursales.store')->middleware('permission:configuracion,crear');
    Route::put('sucursales/{branch}', [SucursalesController::class, 'update'])->name('configuracion.sucursales.update')->middleware('permission:configuracion,editar');
    Route::patch('sucursales/{branch}/estado', [SucursalesController::class, 'status'])->name('configuracion.sucursales.status')->middleware('permission:configuracion,desactivar');
    Route::patch('sucursales/{branch}/principal', [SucursalesController::class, 'main'])->name('configuracion.sucursales.main')->middleware('permission:configuracion,editar');

    // Mi perfil (cualquier usuario autenticado)
    Route::get('perfil', [PerfilController::class, 'show'])->name('configuracion.perfil');
    Route::put('perfil', [PerfilController::class, 'update'])->name('configuracion.perfil.update');
    Route::put('perfil/password', [PerfilController::class, 'password'])->name('configuracion.perfil.password');
    Route::get('perfil/avatar', [PerfilController::class, 'avatar'])->name('configuracion.perfil.avatar');
    Route::get('perfil/firma', [PerfilController::class, 'firma'])->name('configuracion.perfil.firma');
});
