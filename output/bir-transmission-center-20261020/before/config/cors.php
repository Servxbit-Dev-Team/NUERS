<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'https://nuers.net',
        'https://www.nuers.net',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 86400,
    'supports_credentials' => true,
];
