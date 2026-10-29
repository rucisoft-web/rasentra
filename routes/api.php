<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

// Rasentra Central: API sinkronisasi untuk Rasentra Lokal di outlet (token perangkat).
Route::prefix('v1/sync')->middleware(['device', 'throttle:600,1'])->controller(SyncController::class)->group(function () {
    Route::post('events', 'events');
    Route::get('changes', 'changes');
    Route::post('ack', 'ack');
    Route::post('heartbeat', 'heartbeat');
});
