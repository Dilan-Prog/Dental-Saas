<?php

use App\Http\Controllers\Inventario\InsumoController;
use App\Http\Controllers\Inventario\InventarioController;
use App\Http\Controllers\Inventario\MovimientoController;
use App\Http\Controllers\Inventario\ProveedorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo Inventario
|--------------------------------------------------------------------------
| Se carga solo dentro del grupo web + auth + active.member.
| Nombres: inventario.index (resumen / transacciones), inventario.items.*,
| inventario.movimientos.*, inventario.proveedores.*
*/

Route::prefix('inventario')->name('inventario.')->group(function () {
    // Resumen / Transacciones y exportacion CSV
    Route::get('/', [InventarioController::class, 'index'])
        ->middleware('permission:inventario,ver')->name('index');
    Route::get('/exportar', [InventarioController::class, 'export'])
        ->middleware('permission:inventario,exportar')->name('export');

    // Insumos
    Route::post('/insumos', [InsumoController::class, 'store'])
        ->middleware('permission:inventario,crear')->name('items.store');
    Route::get('/insumos/{item}', [InsumoController::class, 'show'])
        ->middleware('permission:inventario,ver')->name('items.show');
    Route::put('/insumos/{item}', [InsumoController::class, 'update'])
        ->middleware('permission:inventario,editar')->name('items.update');
    Route::put('/insumos/{item}/sucursal', [InsumoController::class, 'stockSettings'])
        ->middleware('permission:inventario,editar')->name('items.stock-settings');
    Route::patch('/insumos/{item}/estado', [InsumoController::class, 'toggle'])
        ->middleware('permission:inventario,desactivar')->name('items.toggle');

    // Movimientos (inmutables: se corrigen con ajuste o reversion)
    Route::post('/movimientos/entrada', [MovimientoController::class, 'entrada'])
        ->middleware('permission:inventario,crear')->name('movimientos.entrada');
    Route::post('/movimientos/salida', [MovimientoController::class, 'salida'])
        ->middleware('permission:inventario,crear')->name('movimientos.salida');
    Route::post('/movimientos/ajuste', [MovimientoController::class, 'ajuste'])
        ->middleware('permission:inventario,crear')->name('movimientos.ajuste');
    Route::post('/movimientos/inicial', [MovimientoController::class, 'inicial'])
        ->middleware('permission:inventario,crear')->name('movimientos.inicial');
    Route::post('/movimientos/{movement}/reversion', [MovimientoController::class, 'reversion'])
        ->middleware('permission:inventario,editar')->name('movimientos.reversion');

    // Proveedores
    Route::get('/proveedores', [ProveedorController::class, 'index'])
        ->middleware('permission:inventario,ver')->name('proveedores.index');
    Route::post('/proveedores', [ProveedorController::class, 'store'])
        ->middleware('permission:inventario,crear')->name('proveedores.store');
    Route::put('/proveedores/{supplier}', [ProveedorController::class, 'update'])
        ->middleware('permission:inventario,editar')->name('proveedores.update');
    Route::patch('/proveedores/{supplier}/estado', [ProveedorController::class, 'toggle'])
        ->middleware('permission:inventario,desactivar')->name('proveedores.toggle');
    Route::delete('/proveedores/{supplier}', [ProveedorController::class, 'destroy'])
        ->middleware('permission:inventario,desactivar')->name('proveedores.destroy');
});

// Binding implicito por UUID: {item}, {movement} y {supplier} resuelven los modelos
// InventoryItem, InventoryMovement y Supplier (con el filtro de organizacion del modelo).
