<?php

namespace App\Http\Middleware;

use App\Models\Outlet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Konsol Rasentra Lokal: hanya kepala outlet (atau admin) dari outlet yang dilayani server ini.
 */
class EnsureLokalAccess
{
    public const ROLES = ['outlet_head', 'admin'];

    public static function allows($user): bool
    {
        if ($user === null || ! ($user->is_active ?? true) || ! in_array($user->role, self::ROLES, true)) {
            return false;
        }
        $outletId = Outlet::where('code', config('rasentra.outlet_code'))->value('id');

        return $user->role === 'admin' || $outletId === null || (int) $user->outlet_id === (int) $outletId;
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::allows($request->user()), 403, 'Konsol Rasentra Lokal hanya untuk kepala outlet.');

        return $next($request);
    }
}
