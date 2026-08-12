<?php

return [
    'database_connection' => env('GEO_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
    'table_prefix' => env('GEO_TABLE_PREFIX', 'geo_'),

    'nominatim' => [
        'base_url'      => env('GEO_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent'    => env('GEO_NOMINATIM_USER_AGENT', 'laravel-geo/1.0'),
        'email'         => env('GEO_NOMINATIM_EMAIL', ''),
        'rate_limit_ms' => env('GEO_NOMINATIM_RATE_LIMIT_MS', 2100),
        'timeout'       => env('GEO_NOMINATIM_TIMEOUT', 30),
        'cache_ttl_days' => env('GEO_NOMINATIM_CACHE_TTL_DAYS', 365),
    ],

    'overpass' => [
        'base_url'      => env('GEO_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        'timeout'       => env('GEO_OVERPASS_TIMEOUT', 180),
        'rate_limit_ms' => env('GEO_OVERPASS_RATE_LIMIT_MS', 2100),
    ],

    'wikidata' => [
        'base_url'   => env('GEO_WIKIDATA_URL', 'https://query.wikidata.org/sparql'),
        'user_agent' => env('GEO_WIKIDATA_USER_AGENT', 'laravel-geo/1.0'),
        'timeout'    => env('GEO_WIKIDATA_TIMEOUT', 60),
    ],
];