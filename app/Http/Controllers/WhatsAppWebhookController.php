<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Messenger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook WhatsApp Cloud API untuk Rasentra CRM. GET: verifikasi saat didaftarkan di Meta
 * (hub.verify_token). POST: pesan masuk dan status kiriman, ditolak bila tanda tangan
 * X-Hub-Signature-256 (HMAC-SHA256 dengan App Secret) tidak cocok.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');
        if ($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, Messenger $messenger): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Hub-Signature-256'))) {
            return response('Invalid signature', 401);
        }
        try {
            $messenger->handleWebhook($request->json()->all());
        } catch (\Throwable $e) {
            // Tetap 200 agar Meta tidak mengirim ulang tanpa henti; kesalahan dicatat di log.
            Log::error('whatsapp.webhook', ['error' => $e->getMessage()]);
        }

        return response('OK', 200);
    }
}
