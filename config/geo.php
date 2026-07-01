<?php

return [
    'database_connection' => env('GEO_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
    'table_prefix' => env('GEO_TABLE_PREFIX', 'geo_'),

    'nominatim' => [
        'base_url'      => env('GEO_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent'    => env('GEO_NOMINATIM_USER_AGENT', 'laravel-geo/1.0'),
        'email'         => env('GEO_NOMINATIM_EMAIL', ''),
        'rate_limit_ms' => env('GEO_NOMINATIM_RATE_LIMIT_MS', 1100),
        'timeout'       => env('GEO_NOMINATIM_TIMEOUT', 30),
    ],

    'overpass' => [
        'base_url' => env('GEO_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        'timeout'  => env('GEO_OVERPASS_TIMEOUT', 60),
    ],
];