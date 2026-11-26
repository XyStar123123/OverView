<?php

use App\Http\Middleware\HandleCors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CORS for the Vite dev origin (config/cors.php; supports credentials for session auth).
        $middleware->append(HandleCors::class);
        // API has no web login route; unauthenticated API calls must 401 (ApiErrorFormatter), not crash on route('login').
        $middleware->redirectGuestsTo(fn () => null);
        // The auth routes carry the `web` group (session middleware); API endpoints are JSON + SameSite cookies, so CSRF never applies to them.
        $middleware->validateCsrfTokens(['api/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api route renders the binding ApiError shape (ARCHITECTURE.md 4.1).
        $exceptions->shouldRenderJsonWhen(fn ($request, \Throwable $e) => $request->expectsJson() || str_starts_with($request->path(), 'api/'));
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            return app(\App\Http\ApiErrorFormatter::class)->render($e, $request);
        });
    })->create();
