# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is `ionutgrecu/laravel-geo`, a Laravel package providing hierarchical geo-location data (Regions > Countries > Counties > Cities > Neighborhoods) plus Nominatim-based address search and reverse geocoding services. It ships Eloquent models, migrations, JSON seed data, an artisan import command, and geocoding cache models. Requires PHP 8.1+; supports Laravel 9–11 (the `illuminate/support` constraint still lists 6–11, but Laravel 6–8 do not run on PHP 8.1).

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

### Administrative Entities (Wikidata-sourced)

`geo_administrative_entities` is a self-referential hierarchy keyed by a string `id` column, with a `type` string column (length 50, indexed) discriminating entity kinds (continent, region, country, state, county, city, neighborhood, …). It has a nullable `polygon` longText column (hidden on the model by default, like `geo_countries`/`geo_counties`/`geo_cities`). Continents are top-level (no `parent_id`/`country_id`).

- **`GeoAdministrativeEntity`** model (`src/Models/GeoAdministrativeEntity.php`): non-incrementing string PK `id`, `$guarded = ['created_at']`, `$hidden = ['polygon']`, `$casts` includes `'type' => EntityType::class` (enum round-trips to/from the DB as its backed string value). Self-ref `parent()`/`children()` and `country()` relations via `parent_id`/`country_id`.
- **`EntityType`** enum (`src/Enums/EntityType.php`) — backed string enum, the single source of truth for entity-kind values (`Continent`, `Region`, `Country`, `State`, `Province`, `Territory`, `County`, `District`, `Department`, `Prefecture`, `Canton`, `Parish`, `Municipality`, `City`, `Town`, `Village`, `Hamlet`, `Borough`, `Suburb`, `Quarter`, `Neighborhood`). Values mirror OSM/Nominatim lowercase strings.
- **`WikidataService`** (`src/Services/WikidataService.php`) — SPARQL client for the Wikidata Query Service (`https://query.wikidata.org/sparql`). Uses Laravel's `Http` facade (POST form-urlencoded, `Accept: application/sparql-results+json`, descriptive User-Agent required by WDQS). The continent Q-IDs are pinned in `WikidataService::CONTINENT_QIDS` (explicit list rather than `wdt:P31 wd:Q5107`, to fix the Oceania ambiguity). `queryContinents()` runs a **single SPARQL request** that returns one row per (continent, language) with `?name` (the `rdfs:label`), `?language` (`BIND(LANG(?name))`), plus OPTIONAL `?osmRelation` (wdt:P402), `?coord`/`?population` (repeated across language rows). `fetchContinentsWithNames()` groups those bindings by Q-id into one entry per continent with the entity fields plus a `names` sub-array of `[language, name]` pairs; the entity `name` is the English label when present, else the first label (ORDER BY ?continent ?language keeps the fallback stable). `queryCountries()` / `fetchCountriesWithNames()` mirror the same shape for `wd:Q6256` sovereign states, additionally projecting `?continent` (P30) as `continent_qid` for the caller to resolve to `parent_id`. Config under `geo.wikidata.*` (`base_url`, `user_agent`, `timeout`).
- **PK convention**: continent rows use the OSM relation id (wdt:P402) prefixed with `'WD'` (e.g. `WD1466586`) as `id` when available, else fall back to `'WD' + Q-id` (e.g. `WDQ5107`). Country rows use `'R' + OSM relation id` (P402, e.g. `R2202162`) as `id` to match the `R{id}`/`W{id}`/`N{id}` OSM/Nominatim convention (countries are OSM relations); countries without P402 are skipped. `wikidata_id` always holds the bare Q-id (e.g. `Q15`). Other OSM-sourced rows use the `R{id}` / `W{id}` / `N{id}` prefix per Nominatim convention.
- **Per-language names** — `geo_administrative_entity_names` (composite PK `id`+`language`, `name` string(255), FK cascade) holds every `rdfs:label` for each entity. `GeoAdministrativeEntityName::upsertName($id, $language, $name)` is the shared idempotent upsert — it uses a DB-level `upsert()` (NOT Eloquent `firstOrNew` + `save()`, because both key columns are guarded and the composite PK has no single `$primaryKey` for save's UPDATE WHERE). Overwrites `name` + `updated_at` on conflict; `created_at` set on insert only. Both `fetchContinentsFromWikidata()` and `geo:fill-continents` / `geo:fill-countries` call it inline while iterating the single-query result (one pass for entity + names).
- **`GeoService::getContinents()`** — DB-first; when the table is empty and `$online` is true, fetches the ~7 continents from Wikidata via `fetchContinentsFromWikidata()` (single SPARQL request → single-pass upsert of entity rows + names), then re-queries. Upserts use `firstOrNew(['id' => ...])` + fill-empty-attributes + `save()` (idempotent). Reads filter with `->where('type', EntityType::Continent->value)` (query-builder `where` does not consult model casts, so pass the scalar).
- **`geo:fill-continents`** artisan command (`src/Console/FillContinents.php`) — forces the fill from the single Wikidata SPARQL request; single-pass upsert of entities and per-language names. Registered in `LaravelGeoServiceProvider` as `continents.fill`.
- **`geo:fill-countries`** artisan command (`src/Console/FillCountries.php`) — forces the fill of `wd:Q6256` sovereign states from a single Wikidata SPARQL request; single-pass upsert of `EntityType::Country` entities + per-language names. Builds a `wikidata_id => id` map of existing continent rows once and resolves each country's `parent_id` from its P30 `continent_qid` (null when no continent matches). Warns and continues with null `parent_id` if `geo:fill-continents` hasn't run yet. Registered in `LaravelGeoServiceProvider` as `countries.fill`.

### Nominatim Geocoding Services

The package provides two Nominatim-based geocoding services (separate from the existing `NominatimService`, which is a low-level Guzzle client for imports):

- **`AddressSearchService`** (`src/Services/AddressSearchService.php`) — forward geocoding (address → coordinates). Uses Laravel's `Http` facade with `geocodejson` format. Results cached in `geo_address_searches` with TTL-based expiration. Rate-limited via `RateLimiter` with key `laravel-geo:nominatim:search`.
- **`ReverseGeocodeService`** (`src/Services/ReverseGeocodeService.php`) — reverse geocoding (coordinates → address). Uses `jsonv2` format. Results cached in `geo_reverse_geocodes`. Rate-limited with key `laravel-geo:nominatim:reverse`. Enriches results by looking up `Country`, `County`, `City`, and `Neighborhood` models (best-effort via `geoFind()`, falls back to Nominatim-derived values when geo tables are unavailable). Coordinates rounded to 4 decimal places for cache key stability. Uses `CACHE_KEY_VERSION = 'v2'`.

Both services: registered as singletons in `LaravelGeoServiceProvider`. Config via `config('geo.nominatim.*')` (`base_url`, `user_agent`, `email`, `timeout`, `cache_ttl_days`). Graceful stale-cache fallback on provider failure (returns expired cache instead of erroring). Cache models use the `HasUlidPrimaryKeyTrait`.

### ULID Primary Key Trait

`src/Traits/HasUlidPrimaryKeyTrait.php` — generates 20-character uppercase hex IDs at the `creating` event. Format: `HEX(unix_microtime) + 6 random hex chars` (using `bin2hex(random_bytes(3))`). Used by `AddressSearchCache` and `ReverseGeocodeCache` models. Unlike the rest of laravel-geo's models (which use auto-incrementing integer PKs), these cache models use string PKs to match the original `iqapp-realestate` schema.

### Known Issues

- `CountryQueryBuilder` imports `Websea\Iqapp\Helpers\iq` but does not use it — this creates a hard dependency on the iqApp framework in the query builder.
- `NominatimService` has leftover `dd()` debug calls in `overpassCities` and `overpassNeighborhoods` that will dump-and-die at runtime. (The stray `dd()` in the old `overpassContinents` was removed when continents moved to Wikidata.)
- `NominatimService` passes through arbitrary remote-API strings into the `type` column of `geo_administrative_entities` (e.g. `addresstype`, OSM `place` tags). Now that `GeoAdministrativeEntity` casts `type` to the `EntityType` enum, a passthrough value not in the enum's case set will throw `InvalidArgumentException` on model rehydrate. Follow-up: extend the enum or guard the cast for unknown passthrough values.
- `composer.json` still advertises `illuminate/support: ^6.0|...|^11.0`, but the PHP `^8.1` requirement and the enum make Laravel 6–8 effectively unsupported.