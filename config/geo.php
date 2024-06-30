<?php

return [
    'database_connection' => env('GEO_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
    'table_prefix' => env('GEO_TABLE_PREFIX', 'geo_'),
];