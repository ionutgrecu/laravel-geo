# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is `ionutgrecu/laravel-geo`, a Laravel package providing hierarchical geo-location data (Regions > Countries > Counties > Cities > Neighborhoods) plus Nominatim-based address search and reverse geocoding services. It ships Eloquent models, migrations, JSON seed data, an artisan import command, and geocoding cache models. Supports Laravel 6–11.

## Commands

```bash
composer test                # Run PHPUnit via composer script
vendor/bin/phpunit            # Run PHPUnit directly
```

Tests use Orchestra Testbench. No Docker setup needed — this is a standalone package.

## Architecture

### Model Hierarchy

Models are linked by **code fields** (not auto-increment IDs) for cross-system compatibility. Always reference models by their `code`, never by `id`.

- **Region** — top-level geographic area (e.g. "Europe", code `EU`)
  - hasMany Countries via `region_code`
- **Country** — linked to Region via `region_code`
  - hasMany Counties via `country_code`
- **County** — linked to Country via `country_code`
  - hasMany Cities via `county_code`
- **City** — linked to County via `county_code`
  - hasMany Neighborhoods via `city_code`
- **Neighborhood** — linked to City via `city_code`

### Configurable Table Prefix & Connection

All models read `config('geo.database_connection')` and `config('geo.table_prefix')` in their constructor. This means the actual table names are like `geo_regions`, `geo_countries`, `geo_address_searches`, `geo_reverse_geocodes`, etc. Migrations also resolve table names and connection from the model, so they stay in sync.

### Custom Query Builders

Each model overrides `newEloquentBuilder()` with a custom builder (`src/Builders/`) that overrides `find()` to search across multiple identifier fields:

- **RegionQueryBuilder**: finds by `code`, `iso2`, or `name`
- **CountryQueryBuilder**: finds by `code`, `iso2`, `iso3`, `iso_numeric`, `name`, or `name_int`
- **CountyQueryBuilder**: finds by `code` or `name`
- **CityQueryBuilder**: finds by `code` or `name`

Passing an array to `find()` returns a Collection with `whereIn`/`orWhereIn` across all identifier fields.

### Global Scopes

Country, County, and City models auto-eager-load their parent relation via a global scope in `booted()` (`withRegion`, `withCountry`, `withCounty`). Region and Country also have a `setFavorite()` static method that adds a global scope sorting a specific code first.

### Data Layer

Static geo data lives in `data/` as JSON files:
- `data/regions.json` — all regions
- `data/countries.json` — all countries
- `data/counties/{ISO2}.json` — one file per country
- `data/cities/{ISO2}.json` — one file per country (94 country files currently)

`JsonLocationsService` reads these files. `GeoService` uses it for imports and also provides query methods (`getRegions`, `getCountries`, `getCounties`, `getCities`, `getNeighborhoods`, `getLocationsTree`).

### Artisan Command

`geo:import-regions {regions?} {--c|countries}` — imports regions (and optionally countries/counties) from JSON into the database. Uses `firstOrNew` for upsert behavior.

### Nominatim Geocoding Services

The package provides two Nominatim-based geocoding services (separate from the existing `NominatimService`, which is a low-level Guzzle client for imports):

- **`AddressSearchService`** (`src/Services/AddressSearchService.php`) — forward geocoding (address → coordinates). Uses Laravel's `Http` facade with `geocodejson` format. Results cached in `geo_address_searches` with TTL-based expiration. Rate-limited via `RateLimiter` with key `laravel-geo:nominatim:search`.
- **`ReverseGeocodeService`** (`src/Services/ReverseGeocodeService.php`) — reverse geocoding (coordinates → address). Uses `jsonv2` format. Results cached in `geo_reverse_geocodes`. Rate-limited with key `laravel-geo:nominatim:reverse`. Enriches results by looking up `Country`, `County`, `City`, and `Neighborhood` models (best-effort via `geoFind()`, falls back to Nominatim-derived values when geo tables are unavailable). Coordinates rounded to 4 decimal places for cache key stability. Uses `CACHE_KEY_VERSION = 'v2'`.

Both services: registered as singletons in `LaravelGeoServiceProvider`. Config via `config('geo.nominatim.*')` (`base_url`, `user_agent`, `email`, `timeout`, `cache_ttl_days`). Graceful stale-cache fallback on provider failure (returns expired cache instead of erroring). Cache models use the `HasUlidPrimaryKeyTrait`.

### ULID Primary Key Trait

`src/Traits/HasUlidPrimaryKeyTrait.php` — generates 20-character uppercase hex IDs at the `creating` event. Format: `HEX(unix_microtime) + 6 random hex chars` (using `bin2hex(random_bytes(3))`). Used by `AddressSearchCache` and `ReverseGeocodeCache` models. Unlike the rest of laravel-geo's models (which use auto-incrementing integer PKs), these cache models use string PKs to match the original `iqapp-realestate` schema.

### Known Issues

- `CountryQueryBuilder` imports `Websea\Iqapp\Helpers\iq` but does not use it — this creates a hard dependency on the iqApp framework in the query builder.
- `NominatimService` has leftover `dd()` debug calls in `overpassCities` and `overpassNeighborhoods` that will dump-and-die at runtime.