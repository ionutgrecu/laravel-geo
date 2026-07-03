<?php

namespace Ionutgrecu\LaravelGeo\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Ionutgrecu\LaravelGeo\Models\City;
use Ionutgrecu\LaravelGeo\Models\Country;
use Ionutgrecu\LaravelGeo\Models\County;
use Ionutgrecu\LaravelGeo\Models\Neighborhood;
use Ionutgrecu\LaravelGeo\Models\Region;
use Ionutgrecu\LaravelGeo\Models\ReverseGeocodeCache;
use Throwable;

class ReverseGeocodeService {
	public const PROVIDER = 'nominatim';
	protected const PROVIDER_RATE_LIMIT_KEY = 'laravel-geo:nominatim:reverse';
	protected const CACHE_KEY_VERSION = 'v2';

	public function reverseGeocode(float $latitude, float $longitude, ?int $zoom = null, ?string $acceptLanguage = null): array {
		$latitude = round($latitude, 4);
		$longitude = round($longitude, 4);
		$acceptLanguage = $this->normalizeAcceptLanguage($acceptLanguage);
		$cacheKey = $this->cacheKey($latitude, $longitude, $zoom, $acceptLanguage);

		$cache = ReverseGeocodeCache::query()
			->where('provider', self::PROVIDER)
			->where('cache_key', $cacheKey)
			->first();

		if ($cache && $cache->isFresh()) {
			$cache->forceFill(['last_hit_at' => now()])->save();

			return $this->formatResponse($cache, 'cache');
		}

		if (RateLimiter::tooManyAttempts(self::PROVIDER_RATE_LIMIT_KEY, 1)) {
			if ($cache) {
				$cache->forceFill(['last_hit_at' => now()])->save();

				return $this->formatResponse($cache, 'stale_cache');
			}

			return [
				'ok' => false,
				'status' => 429,
				'message' => 'Nominatim reverse geocode rate limit reached. Please retry shortly.',
			];
		}

		RateLimiter::hit(self::PROVIDER_RATE_LIMIT_KEY, 1);

		try {
			$providerResponse = $this->reverseGeocodeProvider($latitude, $longitude, $zoom, $acceptLanguage);
			$results = $this->normalizeProviderResponse($providerResponse['body']);

			$cache = ReverseGeocodeCache::query()->updateOrCreate(
				[
					'provider' => self::PROVIDER,
					'cache_key' => $cacheKey,
				],
				[
					'latitude' => $latitude,
					'longitude' => $longitude,
					'zoom' => $zoom,
					'accept_language' => $acceptLanguage,
					'results' => $results,
					'raw_response' => $providerResponse['body'],
					'http_status' => $providerResponse['status'],
					'expires_at' => now()->addDays($this->cacheTtlDays()),
					'last_hit_at' => now(),
				],
			);

			return $this->formatResponse($cache, 'provider');
		} catch (Throwable $exception) {
			if ($cache) {
				$cache->forceFill(['last_hit_at' => now()])->save();

				return $this->formatResponse($cache, 'stale_cache');
			}

			return [
				'ok' => false,
				'status' => 502,
				'message' => 'Reverse geocode provider is unavailable.',
			];
		}
	}

	protected function reverseGeocodeProvider(float $lat, float $lon, ?int $zoom, ?string $acceptLanguage): array {
		$parameters = [
			'lat' => $lat,
			'lon' => $lon,
			'format' => 'jsonv2',
			'addressdetails' => 1,
			'extratags' => 1,
		];

		if ($zoom !== null) {
			$parameters['zoom'] = $zoom;
		}

		if ($email = $this->email()) {
			$parameters['email'] = $email;
		}

		$headers = [
			'Accept' => 'application/json',
			'User-Agent' => $this->userAgent(),
		];

		if ($acceptLanguage) {
			$headers['Accept-Language'] = $acceptLanguage;
		}

		$response = Http::withHeaders($headers)
			->timeout($this->timeoutSeconds())
			->get(rtrim($this->baseUrl(), '/') . '/reverse', $parameters);

		if ($response->failed()) {
			throw new RequestException($response);
		}

		return [
			'status' => $response->status(),
			'body' => $response->json() ?? [],
		];
	}

	protected function normalizeProviderResponse(array $payload): ?array {
		$displayName = $payload['display_name'] ?? null;
		$latitude = $payload['lat'] ?? null;
		$longitude = $payload['lon'] ?? null;

		if (empty($displayName) || $latitude === null || $longitude === null) {
			return null;
		}

		$address = $payload['address'] ?? [];
		$extratags = $payload['extratags'] ?? [];
		$addressType = $payload['addresstype'] ?? $payload['type'] ?? null;
		$osmCode = $this->osmCode($payload['osm_type'] ?? null, $payload['osm_id'] ?? null);
		$wikiMain = $extratags['wikidata'] ?? null;

		$countryCode = isset($address['country_code']) ? strtoupper($address['country_code']) : null;
		$countryName = $address['country'] ?? null;
		$stateName = $address['state'] ?? $address['county'] ?? null;
		$countyIso = $address['ISO3166-2-lvl4'] ?? $address['ISO3166-2-lvl6'] ?? null;
		$cityName = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? $address['hamlet'] ?? null;
		$neighborhoodName = $address['neighbourhood'] ?? $address['suburb'] ?? $address['quarter'] ?? null;

		[$continent, $country] = $this->resolveCountry($countryCode, $countryName, $addressType, $wikiMain);
		$county = $this->resolveCounty($countryCode, $stateName, $countyIso, $addressType, $wikiMain);
		$countyCode = $county['code'] ?? null;
		$city = $this->resolveCity($countyCode, $countryCode, $cityName, $addressType, $wikiMain, $osmCode);
		$cityCode = $city['code'] ?? null;
		$neighborhood = $this->resolveNeighborhood($cityCode, $neighborhoodName, $addressType, $wikiMain, $osmCode);

		return [
			'provider' => self::PROVIDER,
			'provider_id' => $osmCode,
			'label' => $displayName,
			'continent' => $continent,
			'country' => $country,
			'county' => $county,
			'city' => $city,
			'neighborhood' => $neighborhood,
			'postcode' => $address['postcode'] ?? null,
			'street' => $address['road'] ?? $address['street'] ?? null,
			'house_number' => $address['house_number'] ?? $address['housenumber'] ?? null,
			'latitude' => (float) $latitude,
			'longitude' => (float) $longitude,
			'raw' => array_filter([
				'address' => $address,
				'extratags' => $extratags,
				'addresstype' => $addressType,
			], fn($value) => $value !== null && $value !== []),
		];
	}

	protected function resolveCountry(?string $countryCode, ?string $countryName, ?string $addressType, ?string $wikiMain): array {
		$country = null;
		$continent = null;

		if ($countryCode) {
			$model = $this->geoFind(fn() => Country::query()->where('code', $countryCode)->first());

			if ($model) {
				$country = $this->nullableObject([
					'name' => $model->name,
					'code' => $model->code,
					'iso2' => $model->iso2,
					'iso3' => $model->iso3,
					'iso_numeric' => $model->iso_numeric,
					'wiki_data_id' => $model->wiki_data_id,
				]);

				$region = $model->region;

				if ($region) {
					$continent = $this->nullableObject([
						'name' => $region->name,
						'code' => $region->code,
						'iso2' => $region->iso2,
						'wiki_data_id' => $region->wiki_data_id,
					]);
				}
			}
		}

		if ($country === null && ($countryName || $countryCode)) {
			$country = $this->nullableObject([
				'name' => $countryName,
				'code' => $countryCode,
				'iso2' => $countryCode,
				'iso3' => null,
				'iso_numeric' => null,
				'wiki_data_id' => $addressType === 'country' ? $wikiMain : null,
			]);
		}

		return [$continent, $country];
	}

	protected function resolveCounty(?string $countryCode, ?string $stateName, ?string $countyIso, ?string $addressType, ?string $wikiMain): ?array {
		if (!$stateName) {
			return null;
		}

		$model = null;

		if ($countryCode) {
			$model = $this->geoFind(fn() => County::query()
				->where('country_code', $countryCode)
				->where('name', $stateName)
				->first());

			if (!$model) {
				$model = $this->geoFind(fn() => County::query()
					->where('country_code', $countryCode)
					->whereRaw('LOWER(name) = LOWER(?)', [$stateName])
					->first());
			}
		}

		if ($model) {
			return $this->nullableObject([
				'name' => $model->name,
				'code' => $model->code,
				'wiki_data_id' => $model->wiki_data_id,
			]);
		}

		return $this->nullableObject([
			'name' => $stateName,
			'code' => $countyIso ? strtoupper($countyIso) : null,
			'wiki_data_id' => in_array($addressType, ['county', 'state'], true) ? $wikiMain : null,
		]);
	}

	protected function resolveCity(?string $countyCode, ?string $countryCode, ?string $cityName, ?string $addressType, ?string $wikiMain, ?string $osmCode): ?array {
		if (!$cityName) {
			return null;
		}

		$model = null;

		if ($countyCode) {
			$model = $this->geoFind(fn() => City::query()
				->where('county_code', $countyCode)
				->where('name', $cityName)
				->first());
		}

		if (!$model && $countryCode) {
			$model = $this->geoFind(fn() => City::query()
				->where('name', $cityName)
				->whereHas('county', fn($q) => $q->where('country_code', $countryCode))
				->first());
		}

		if ($model) {
			return $this->nullableObject([
				'name' => $model->name,
				'code' => $model->code,
				'wiki_data_id' => $model->wiki_data_id,
			]);
		}

		$isMain = in_array($addressType, ['city', 'town', 'village', 'municipality', 'hamlet'], true);
		$wikiDataId = $isMain ? $wikiMain : null;

		return $this->nullableObject([
			'name' => $cityName,
			'code' => $wikiDataId ?? ($isMain ? $osmCode : null),
			'wiki_data_id' => $wikiDataId,
		]);
	}

	protected function resolveNeighborhood(?string $cityCode, ?string $neighborhoodName, ?string $addressType, ?string $wikiMain, ?string $osmCode): ?array {
		if (!$neighborhoodName) {
			return null;
		}

		$model = null;

		if ($cityCode) {
			$model = $this->geoFind(fn() => Neighborhood::query()
				->where('city_code', $cityCode)
				->where('name', $neighborhoodName)
				->first());
		}

		if ($model) {
			return $this->nullableObject([
				'name' => $model->name,
				'code' => $model->code,
				'wiki_data_id' => $model->wiki_data_id,
			]);
		}

		$isMain = in_array($addressType, ['neighbourhood', 'suburb', 'quarter', 'hamlet', 'borough'], true);
		$wikiDataId = $isMain ? $wikiMain : null;

		return $this->nullableObject([
			'name' => $neighborhoodName,
			'code' => $wikiDataId ?? ($isMain ? $osmCode : null),
			'wiki_data_id' => $wikiDataId,
		]);
	}

	protected function osmCode(?string $osmType, mixed $osmId): ?string {
		if (empty($osmType) || $osmId === null || $osmId === '') {
			return null;
		}

		return strtoupper(substr((string) $osmType, 0, 1)) . $osmId;
	}

	protected function nullableObject(array $data): ?array {
		$filtered = array_filter($data, fn($value) => $value !== null && $value !== '');

		return empty($filtered) ? null : $filtered;
	}

	/**
	 * Best-effort lookup against the Ionutgrecu\LaravelGeo tables. Returns null
	 * when the geo package is unconfigured or its tables are unavailable, so
	 * the caller can fall back to the Nominatim-derived values.
	 */
	protected function geoFind(callable $callback) {
		try {
			return $callback();
		} catch (Throwable $exception) {
			return null;
		}
	}

	protected function formatResponse(ReverseGeocodeCache $cache, string $source): array {
		return [
			'ok' => true,
			'data' => $cache->results,
			'meta' => [
				'source' => $source,
				'cache_id' => $cache->id,
				'expires_at' => optional($cache->expires_at)->toISOString(),
				'attribution' => 'Data © OpenStreetMap contributors',
			],
		];
	}

	protected function cacheKey(float $lat, float $lon, ?int $zoom, ?string $acceptLanguage): string {
		return hash('md5', implode('|', [
			self::CACHE_KEY_VERSION,
			self::PROVIDER,
			number_format($lat, 4, '.', ''),
			number_format($lon, 4, '.', ''),
			$zoom ?? '',
			$acceptLanguage ?? '',
		]));
	}

	protected function normalizeAcceptLanguage(?string $acceptLanguage): ?string {
		if (!$acceptLanguage) {
			return null;
		}

		return substr(trim($acceptLanguage), 0, 100) ?: null;
	}

	protected function baseUrl(): string {
		return (string) config('geo.nominatim.base_url', 'https://nominatim.openstreetmap.org');
	}

	protected function userAgent(): string {
		return (string) config('geo.nominatim.user_agent', 'laravel-geo/1.0');
	}

	protected function timeoutSeconds(): int {
		return max(1, (int) config('geo.nominatim.timeout', 30));
	}

	protected function cacheTtlDays(): int {
		return max(1, (int) config('geo.nominatim.cache_ttl_days', 30));
	}

	protected function email(): ?string {
		return trim((string) config('geo.nominatim.email', '')) ?: null;
	}
}