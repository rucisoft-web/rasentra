<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tamu demo boleh mencoba transaksi, tetapi tidak boleh mengubah pengguna, outlet atau
 * pemasangan server agar demo tetap utuh untuk pengunjung berikutnya.
 */
class GuardDemoSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('rasentra.demo.enabled') || ! $request->hasSession() || ! $request->session()->has('demo')
            || $request->isMethodSafe() || ! $request->routeIs(...config('rasentra.demo.blocked_routes'))) {
            return $next($request);
        }

        $message = 'Mode tamu: aksi ini dinonaktifkan di server demo.';
        if ($request->expectsJson()) {
            return response()->json(['error' => 'demo_blocked', 'message' => $message], 403);
        }

        return back()->with('error', $message);
    }
}
