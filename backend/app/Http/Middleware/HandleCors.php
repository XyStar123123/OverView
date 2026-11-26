<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal CORS middleware for the Vite dev origin (config/cors.php).
 *
 * Replaces fruitcake/laravel-cors with zero extra composer packages
 * (ARCHITECTURE.md 2.5). Session-based auth requires
 * supports_credentials=true, so "*" is never used as an origin.
 */
class HandleCors
{
    /**
     * @param  \Closure(Request): (Response|\Illuminate\Http\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $config = config('cors');
        $origin = $request->header('Origin');

        $response = $next($request);

        $allowed = $origin !== null && in_array($origin, $config['allowed_origins'], true);

        if ($allowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        if ($request->isMethod('OPTIONS')) {
            $response->headers->set('Access-Control-Allow-Methods', implode(', ', $config['allowed_methods']));
            $response->headers->set('Access-Control-Allow-Headers', $request->header('Access-Control-Request-Headers') ?? implode(', ', $config['allowed_headers']));
            $response->headers->set('Access-Control-Max-Age', (string) $config['max_age']);
        }

        return $response;
    }
}
