<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    /**
     * Los dominios desde donde se sirve el frontend, separados por coma en
     * CORS_ALLOWED_ORIGINS. En desarrollo alcanza con los patrones de abajo,
     * pero un sitio publicado tiene un origen propio que hay que declarar:
     * con `supports_credentials` no se puede usar `*`.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),
    'allowed_origins_patterns' => [
        '#^https?://localhost(?::\\d+)?$#',
        '#^https?://127\\.0\\.0\\.1(?::\\d+)?$#',
        '#^https?://\\[::1\\](?::\\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
