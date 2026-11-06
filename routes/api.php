<?php

use App\Http\Controllers\SyncController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Rasentra Central: API sinkronisasi untuk Rasentra Lokal di outlet (token perangkat).
Route::prefix('v1/sync')->middleware(['device', 'throttle:600,1'])->controller(SyncController::class)->group(function () {
    Route::post('events', 'events');
    Route::get('changes', 'changes');
    Route::get('snapshot', 'snapshot');
    Route::post('ack', 'ack');
    Route::post('heartbeat', 'heartbeat');
    Route::get('member-balance', 'memberBalance');
});

// Rasentra CRM: webhook WhatsApp Business Cloud API (diverifikasi dengan App Secret Meta).
Route::get('crm/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])->name('crm.whatsapp.webhook');
Route::post('crm/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:1200,1');
