<?php

/*
|--------------------------------------------------------------------------
| CORS (ARCHITECTURE.md 2.5, binding)
|--------------------------------------------------------------------------
| The prototype ships its own middleware (App\Http\Middleware\HandleCors)
| instead of the fruitcake/laravel-cors package, so no extra composer
| dependency is introduced (2.5: "no additional packages"). The Vite dev
| origin may call the API with session credentials.
*/
return [
    'allowed_origins' => [env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')],
    'supports_credentials' => true,
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
];
