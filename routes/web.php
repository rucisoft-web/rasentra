<?php

use App\Http\Controllers\KitchenController;
use App\Http\Controllers\MobileController;
use App\Http\Controllers\PosAuthController;
use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Route;

// Landing page Rasentra: fitur, alur kerja retail/resto, panduan.
Route::view('/', 'landing')->name('landing');

// Rasentra POS (layar sentuh kasir) di server outlet.
Route::get('/pos/login', [PosAuthController::class, 'show'])->name('pos.login');
Route::post('/pos/login', [PosAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/pos/logout', [PosAuthController::class, 'logout'])->name('pos.logout');

Route::middleware('auth')->group(function () {
    Route::view('/pos', 'pos.app')->name('pos');

    Route::prefix('pos/api')->controller(PosController::class)->group(function () {
        Route::get('bootstrap', 'bootstrap');
        Route::get('catalog', 'catalog');
        Route::post('orders', 'createOrder');
        Route::get('orders/{order}', 'showOrder');
        Route::post('orders/{order}/scan', 'scan');
        Route::post('orders/{order}/items', 'addItem');
        Route::post('orders/{order}/hold', 'hold');
        Route::post('orders/{order}/resume', 'resume');
        Route::post('orders/{order}/payments', 'pay');
        Route::post('orders/{order}/finalize', 'finalize');
        Route::post('orders/{order}/kitchen', 'sendToKitchen');
        Route::post('orders/{order}/cooking-from-receipt', 'cookingFromReceipt');
        Route::post('lines/{line}/void', 'voidLine');
        Route::post('lines/{line}/price', 'overridePrice');
        Route::post('lines/{line}/cooking', 'cooking');
        Route::get('payments/{payment}', 'paymentStatus');
        Route::get('shift', 'shiftSummary');
        Route::post('shift/open', 'openShift');
        Route::post('shift/cash', 'cashMovement');
        Route::post('shift/close', 'closeShift');
    });

    // Rasentra Kitchen: layar station, expeditor, antrean pickup, analisis waktu saji.
    Route::controller(KitchenController::class)->group(function () {
        Route::get('kitchen', 'home')->name('kitchen');
        Route::get('kitchen/queue', 'queueScreen')->name('kitchen.queue');
        Route::get('kitchen/s/{station}', 'screen')->name('kitchen.screen');

        Route::prefix('kitchen/api')->group(function () {
            Route::get('board/{station}', 'data');
            Route::post('tickets/{ticket}/bump', 'bumpTicket');
            Route::post('items/{item}/bump', 'bumpItem');
            Route::post('items/{item}/recall', 'recall');
            Route::post('items/{item}/cancel', 'cancel');
            Route::post('scan', 'scan');
            Route::get('queue', 'queue');
            Route::get('analytics', 'analytics');
        });
    });

    // Rasentra Mobile: handheld inventory (penerimaan, stok opname, cek stok).
    Route::get('mobile', [MobileController::class, 'app'])->name('mobile');
    Route::prefix('mobile/api')->controller(MobileController::class)->group(function () {
        Route::get('lookup', 'lookup');
        Route::get('receiving', 'expected');
        Route::post('receiving', 'receive');
        Route::get('counts', 'sessions');
        Route::post('counts', 'openSession');
        Route::get('counts/{session}', 'showSession');
        Route::post('counts/{session}/lines', 'countLine');
        Route::post('counts/{session}/review', 'review');
        Route::post('counts/{session}/approve', 'approve');
    });
});
