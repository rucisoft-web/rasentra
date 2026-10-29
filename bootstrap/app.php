<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\EnsureCoreAccess;
use App\Support\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['device' => AuthenticateDevice::class, 'core' => EnsureCoreAccess::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('core', 'core/*') ? route('core.login') : route('pos.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('pos/api/*') || $request->is('kitchen/api/*') || $request->is('inventory/api/*') || $request->expectsJson(),
        );
        // Pelanggaran aturan bisnis dikembalikan dengan kode stabil agar layar POS bisa bereaksi.
        $exceptions->render(function (DomainException $e, Request $request) {
            // Rasentra Core memakai formulir HTML: kembali ke halaman sebelumnya dengan pesan.
            if ($request->is('core', 'core/*') && ! $request->expectsJson()) {
                return back()->withInput()->with('error', $e->getMessage());
            }

            return response()->json([
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ], $e->status);
        });
    })->create();
