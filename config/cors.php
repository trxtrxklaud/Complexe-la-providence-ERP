<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
    |--------------------------------------------------------------------------
    | Allowed origins
    |--------------------------------------------------------------------------
    | Never use ['*'] in production. Set CORS_ALLOWED_ORIGINS in .env as a
    | comma separated list, e.g.:
    | CORS_ALLOWED_ORIGINS=https://erp.laprovidence.tn,https://www.laprovidence.tn
    */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:5173,http://localhost:8000,http://127.0.0.1:5173,http://127.0.0.1:8000'
        ))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
