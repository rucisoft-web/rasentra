<?php

use App\Http\Controllers\InventoryController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\Lokal\LokalController;
use App\Http\Controllers\Lokal\SetupController;
use App\Http\Controllers\PosAuthController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

// Landing page Rasentra: fitur, alur kerja retail/resto, panduan.
Route::view('/', 'landing')->name('landing');

// Rasentra POS (layar sentuh kasir), dilayani Rasentra Lokal di outlet.
Route::get('/pos/login', [PosAuthController::class, 'show'])->name('pos.login');
Route::post('/pos/login', [PosAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/pos/logout', [PosAuthController::class, 'logout'])->name('pos.logout');

// Rasentra Lokal: konsol server outlet. Pemasangan terbuka tanpa login hanya selama server
// belum punya petugas (lihat SetupController); health check untuk pemantauan.
Route::get('/lokal/health', [LokalController::class, 'health'])->name('lokal.health');
Route::prefix('lokal/setup')->name('lokal.setup')->controller(SetupController::class)->group(function () {
    Route::get('/', 'show');
    Route::post('/', 'store')->middleware('throttle:10,1')->name('.store');
    Route::post('bootstrap', 'bootstrap')->name('.bootstrap');
});
Route::middleware(['auth', 'lokal'])->prefix('lokal')->name('lokal.')->controller(LokalController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('sync/push', 'push')->name('push');
    Route::post('sync/pull', 'pull')->name('pull');
    Route::post('sync/heartbeat', 'heartbeat')->name('heartbeat');
    Route::get('outbox', 'outbox')->name('outbox');
    Route::post('outbox/retry', 'retryAll')->name('outbox.retry-all');
    Route::post('outbox/{event}/retry', 'retry')->name('outbox.retry');
    Route::get('publications', 'publications')->name('publications');
    Route::get('close-day', 'closeDay')->name('close-day');
    Route::post('close-day', 'storeCloseDay')->name('close-day.store');
    Route::post('backup', 'backup')->name('backup');
});

Route::middleware('auth')->group(function () {
    Route::view('/pos', 'pos.app')->name('pos');
    Route::get('pos/api/sync-status', [LokalController::class, 'posStatus']);
    Route::controller(ReceiptController::class)->group(function () {
        Route::get('pos/receipt/{order}', 'order')->name('pos.receipt');
        Route::get('pos/receipt/return/{salesReturn}', 'salesReturn')->name('pos.receipt.return');
        Route::get('pos/shift-report/{shift}', 'shift')->name('pos.shift-report');
    });

    Route::prefix('pos/api')->controller(PosController::class)->group(function () {
        Route::get('bootstrap', 'bootstrap');
        Route::get('catalog', 'catalog');
        Route::post('orders', 'createOrder');
        Route::get('orders/{order}', 'showOrder');
        Route::get('history', 'history');
        Route::get('orders/{order}/detail', 'orderDetail');
        Route::post('orders/{order}/returns', 'createReturn');
        Route::post('orders/{order}/customer', 'customer');
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

    // Rasentra Inventory: handheld inventory (penerimaan, stok opname, cek stok).
    Route::get('inventory', [InventoryController::class, 'app'])->name('inventory');
    Route::prefix('inventory/api')->controller(InventoryController::class)->group(function () {
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

require __DIR__.'/core.php';
