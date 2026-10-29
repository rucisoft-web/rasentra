<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token perangkat/server outlet untuk API sinkronisasi. Token disimpan sebagai sha256,
 * terikat ke satu outlet, dan dapat dicabut dengan menonaktifkan perangkat.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $device = $token ? Device::where('token_hash', hash('sha256', $token))->where('is_active', true)->first() : null;

        if ($device === null) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Token perangkat tidak sah.'], 401);
        }

        $device->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
