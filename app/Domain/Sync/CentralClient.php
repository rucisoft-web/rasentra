<?php

namespace App\Domain\Sync;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Rasentra Lokal: klien HTTP ke API sinkronisasi Rasentra Central dengan token server outlet. */
final class CentralClient
{
    public function request(int $timeout = 20): PendingRequest
    {
        $url = (string) config('rasentra.central.url');
        if ($url === '') {
            throw new RuntimeException('RASENTRA_CENTRAL_URL belum diisi.');
        }

        return Http::baseUrl(rtrim($url, '/').'/api/v1/sync')
            ->withToken((string) config('rasentra.central.token'))
            ->timeout($timeout)->acceptJson();
    }
}
