<?php

namespace App\Http\Middleware;

use App\Domain\Core\CoreAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pintu Rasentra Core: hanya peran kantor pusat, dan hanya area yang diizinkan perannya.
 */
class EnsureCoreAccess
{
    public function handle(Request $request, Closure $next, string $area = 'dashboard'): Response
    {
        $user = $request->user();
        if (! CoreAccess::isCoreUser($user)) {
            Auth::logout();

            return redirect()->route('core.login')->withErrors(['email' => 'Akun ini bukan akun kantor pusat.']);
        }
        abort_unless(CoreAccess::allows($user, $area), 403, 'Peran Anda tidak memiliki akses ke menu ini.');

        return $next($request);
    }
}
