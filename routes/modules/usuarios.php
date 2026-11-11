<?php

use App\Http\Controllers\Usuarios\InvitationController;
use App\Http\Controllers\Usuarios\RoleController;
use App\Http\Controllers\Usuarios\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Usuarios y Roles
|--------------------------------------------------------------------------
| Este archivo se carga dentro del grupo web + auth + active.member
| (RouteServiceProvider), salvo la aceptación pública de invitaciones.
*/

// ---- Aceptación de invitación (pública: el invitado aún no tiene sesión) ----
Route::withoutMiddleware(['auth', 'active.member'])->group(function () {
    Route::get('/invitaciones/{token}', [InvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->middleware('throttle:30,1')
        ->name('invitations.accept');

    Route::post('/invitaciones/{token}', [InvitationController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->middleware('throttle:10,1')
        ->name('invitations.complete');
});

// ------------------------------- Usuarios -------------------------------
Route::prefix('usuarios')->name('usuarios.')->group(function () {
    Route::get('/', [UsuarioController::class, 'index'])->middleware('permission:usuarios,ver')->name('index');
    Route::post('/', [UsuarioController::class, 'store'])->middleware('permission:usuarios,crear')->name('store');

    Route::get('/{member}/editar', [UsuarioController::class, 'edit'])->middleware('permission:usuarios,ver')->name('edit');
    Route::put('/{member}', [UsuarioController::class, 'update'])->middleware('permission:usuarios,editar')->name('update');
    Route::post('/{member}/estado', [UsuarioController::class, 'estado'])->middleware('permission:usuarios,desactivar')->name('estado');
    Route::post('/{member}/acceso', [UsuarioController::class, 'acceso'])->middleware('permission:usuarios,editar')->name('acceso');
    Route::delete('/{member}/invitacion', [UsuarioController::class, 'revocar'])->middleware('permission:usuarios,editar')->name('invitacion.revocar');
    Route::get('/{member}/firma', [UsuarioController::class, 'firma'])->middleware('permission:usuarios,ver')->name('firma');
});

// --------------------------------- Roles ---------------------------------
Route::prefix('roles')->name('roles.')->group(function () {
    Route::get('/', [RoleController::class, 'index'])->middleware('permission:roles,ver')->name('index');
    Route::post('/', [RoleController::class, 'store'])->middleware('permission:roles,crear')->name('store');

    Route::get('/{role}', [RoleController::class, 'show'])->middleware('permission:roles,ver')->name('show');
    Route::put('/{role}', [RoleController::class, 'update'])->middleware('permission:roles,editar')->name('update');
    Route::put('/{role}/permisos', [RoleController::class, 'permisos'])->middleware('permission:roles,editar')->name('permisos');
    Route::post('/{role}/estado', [RoleController::class, 'estado'])->middleware('permission:roles,desactivar')->name('estado');
    Route::post('/{role}/usuarios', [RoleController::class, 'asignar'])->middleware('permission:roles,editar')->name('usuarios.asignar');
    Route::delete('/{role}/usuarios/{member}', [RoleController::class, 'quitar'])->middleware('permission:roles,editar')->name('usuarios.quitar');
});
