# laravel-geo

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Travis](https://img.shields.io/travis/ionutgrecu/laravel-geo.svg?style=flat-square)]()
[![Total Downloads](https://img.shields.io/packagist/dt/ionutgrecu/laravel-geo.svg?style=flat-square)](https://packagist.org/packages/ionutgrecu/laravel-geo)

## Install
`composer require ionutgrecu/laravel-geo`

Requires PHP 8.1+ and Laravel 9–11.

## Usage
This package facilitates the creation of geo models and migrations. Model relationships are established using codes to ensure compatibility across various systems utilizing this package. When referencing models in your code, always use their codes instead of their IDs.

Additionally, you can import specific regions or all available regions into the database as needed.

``` code
php artisan geo:import-regions {regions? : Comma separated list of regions to import. Ex.: eu,na. Default: all regions.} {--c|countries : Import countries.}
```

### Administrative entities (continents, regions, …)

The `geo_administrative_entities` table is a self-referential hierarchy keyed by a string `id` column and discriminated by a `type` column. Entity kinds are defined by the `Ionutgrecu\LaravelGeo\Enums\EntityType` backed string enum (continent, region, country, state, county, city, neighborhood, …). Wikidata-sourced continent rows use `id = 'WD' + OSM relation id` (wdt:P402, e.g. `WD1466586`) when available, else fall back to `'WD' + Q-id` (e.g. `WDQ5107`); the bare Q-id is stored in `wikidata_id`.

Fetch the ~7 continents from the Wikidata Query Service (SPARQL) and upsert them into the table:

``` code
php artisan geo:fill-continents
```

The command is idempotent (only fills empty attributes on existing rows). `GeoService::getContinents()` reads the table first and falls back to a Wikidata SPARQL fetch when empty. Wikidata settings live under `geo.wikidata.*` (`GEO_WIKIDATA_URL`, `GEO_WIKIDATA_USER_AGENT`, `GEO_WIKIDATA_TIMEOUT`).

## Todo
- [ ] Add support for importing cities
- [ ] Further develop the GeoService class
- [ ] Fetch regions from Wikidata SPARQL (continents already supported)

## Credits

- [Ionut Grecu](https://github.com/ionutgrecu)
- [All Contributors](https://github.com/ionutgrecu/laravel-geo/contributors)

## Security
If you discover any security-related issues, please email ionut@grecu.eu instead of using the issue tracker.

## License
The MIT License (MIT). Please see [License File](/LICENSE.md) for more information.