<?php

$rawOrigins = env('CORS_ALLOWED_ORIGINS', '*');
$allowedOrigins = array_filter(array_map('trim', explode(',', $rawOrigins)));

if (!$allowedOrigins) {
    $allowedOrigins = ['*'];
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
