<?php

namespace App\Http\Middleware;

use App\Domain\Access\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Halaman outlet yang dibatasi izin dari matriks Peran & izin. */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(Permissions::allows($request->user(), $permission), 403, 'Peran Anda tidak memiliki izin untuk halaman ini.');

        return $next($request);
    }
}
