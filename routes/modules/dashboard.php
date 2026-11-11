<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\NotificationController;
use App\Http\Controllers\Dashboard\SearchController;
use App\Services\Dashboard\NotificationService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

/*
|--------------------------------------------------------------------------
| Modulo Dashboard: tablero, notificaciones y buscador global
|--------------------------------------------------------------------------
*/

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('permission:dashboard,ver')
    ->name('dashboard');

// Buscador global (topbar). Cada grupo de resultados se filtra por permiso.
Route::get('/buscar', [SearchController::class, 'index'])->name('search');

Route::get('/notificaciones', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notificaciones/leer-todas', [NotificationController::class, 'readAll'])->name('notifications.readAll');
Route::post('/notificaciones/{recipient}/leer', [NotificationController::class, 'read'])->name('notifications.read');

// Contador de la campana: el layout lo lee de $notificationCount. Si las rutas
// estan en cache este composer no se registra y el panel lo corrige por JS.
View::composer('layouts.app', function ($view) {
    if (! auth()->check() || array_key_exists('notificationCount', $view->getData())) {
        return;
    }
    try {
        NotificationService::sync();
        $view->with('notificationCount', NotificationService::unreadCount(auth()->user()));
    } catch (\Throwable $e) {
        report($e);
    }
});
