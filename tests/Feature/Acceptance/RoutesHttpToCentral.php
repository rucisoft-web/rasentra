<?php

namespace Tests\Feature\Acceptance;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/** Panggilan HTTP Rasentra Lokal ke Central diteruskan ke aplikasi uji ini sendiri. */
trait RoutesHttpToCentral
{
    protected function routeHttpToCentral(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = parse_url($request->url());
            $path = $url['path'].(isset($url['query']) ? '?'.$url['query'] : '');
            $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
            // Panggilan API tidak boleh membawa sesi login layar yang sedang diuji.
            $response = $this->withToken($token)->json($request->method(), $path, $request->method() === 'GET' ? [] : $request->data());

            return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
        });
    }
}
