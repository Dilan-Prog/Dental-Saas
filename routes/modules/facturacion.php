<?php

use App\Http\Controllers\Facturacion\InvoiceController;
use App\Http\Controllers\Facturacion\PaymentController;
use Illuminate\Support\Facades\Route;

/*
| Modulo Facturacion y cobranza. Cargado automaticamente dentro del grupo web+auth+active.member.
| Nombres: facturacion.* (facturas) y pagos.* (cobranza).
*/

Route::prefix('facturacion')->name('facturacion.')->group(function () {
    Route::get('/', [InvoiceController::class, 'index'])->name('index')->middleware('permission:facturacion,ver');
    Route::get('exportar', [InvoiceController::class, 'export'])->name('export')->middleware('permission:facturacion,exportar');
    Route::get('nueva', [InvoiceController::class, 'create'])->name('create')->middleware('permission:facturacion,crear');
    Route::get('desde-presupuesto/{quote}', [InvoiceController::class, 'fromQuote'])->name('fromQuote')
        ->whereUuid('quote')->middleware('permission:facturacion,crear');
    Route::post('/', [InvoiceController::class, 'store'])->name('store')->middleware('permission:facturacion,crear');

    Route::where(['invoice' => '[0-9a-fA-F-]{36}'])->group(function () {
        Route::get('{invoice}', [InvoiceController::class, 'show'])->name('show')->middleware('permission:facturacion,ver');
        Route::get('{invoice}/imprimir', [InvoiceController::class, 'print'])->name('print')->middleware('permission:facturacion,ver');
        Route::get('{invoice}/xml', [InvoiceController::class, 'xml'])->name('xml')->middleware('permission:facturacion,ver');
        Route::get('{invoice}/editar', [InvoiceController::class, 'edit'])->name('edit')->middleware('permission:facturacion,editar');
        Route::put('{invoice}', [InvoiceController::class, 'update'])->name('update')->middleware('permission:facturacion,editar');
        Route::delete('{invoice}', [InvoiceController::class, 'destroy'])->name('destroy')->middleware('permission:facturacion,desactivar');
        Route::post('{invoice}/emitir', [InvoiceController::class, 'emit'])->name('emit')->middleware('permission:facturacion,crear');
        Route::post('{invoice}/cancelar', [InvoiceController::class, 'cancel'])->name('cancel')->middleware('permission:facturacion,cancelar_cfdi');
    });
});

// Pago desde el detalle de una factura (modal "Registrar pago").
Route::post('facturacion/{invoice}/pagos', [PaymentController::class, 'store'])->name('pagos.store')
    ->whereUuid('invoice')->middleware('permission:facturacion,registrar_pagos');

// Cobranza (nombres pagos.*; el menu activa "Facturacion" con pagos.*).
Route::prefix('pagos')->name('pagos.')->middleware('permission:facturacion,registrar_pagos')->group(function () {
    Route::get('nuevo', [PaymentController::class, 'create'])->name('create');
    Route::post('/', [PaymentController::class, 'storeMulti'])->name('storeMulti');
    Route::post('{payment}/revertir', [PaymentController::class, 'reverse'])->name('reverse')->whereUuid('payment');
});
