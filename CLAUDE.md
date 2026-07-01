# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is `ionutgrecu/laravel-geo`, a Laravel package providing hierarchical geo-location data (Regions > Countries > Counties > Cities > Neighborhoods). It ships Eloquent models, migrations, JSON seed data, and an artisan import command. Supports Laravel 6–11.

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
  - hasMany Neighborhoods via `city_id` (note: Neighborhood uses `city_id` integer FK, not a code)
- **Neighborhood** — linked to City via `city_id`

### Configurable Table Prefix & Connection

All models read `config('geo.database_connection')` and `config('geo.table_prefix')` in their constructor. This means the actual table names are like `geo_regions`, `geo_countries`, etc. Migrations also resolve table names and connection from the model, so they stay in sync.

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

### Known Issues

- `GeoService::__construct()` has a typo: `$this->jonLocationsService` (missing the `s` in `json`). The property declaration is correct (`$jsonLocationsService`), so the typo assigns to an undeclared dynamic property.
- `CountryQueryBuilder` imports `Websea\Iqapp\Helpers\iq` but does not use it — this creates a hard dependency on the iqApp framework in the query builder.