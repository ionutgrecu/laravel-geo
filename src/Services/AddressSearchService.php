<?php

namespace Ionutgrecu\LaravelGeo\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use Ionutgrecu\LaravelGeo\Models\AddressSearchCache;

class AddressSearchService {
    public const PROVIDER = 'nominatim';
    protected const PROVIDER_RATE_LIMIT_KEY = 'laravel-geo:nominatim:search';

    public function search(string $query, int $limit = 5, ?string $countryCodes = null, ?string $acceptLanguage = null): array {
        $query = trim($query);
        $limit = max(1, min(10, $limit));
        $countryCodes = $this->normalizeCountryCodes($countryCodes);
        $acceptLanguage = $this->normalizeAcceptLanguage($acceptLanguage);
        $normalizedQuery = $this->normalizeQuery($query);
        $cacheKey = $this->cacheKey($normalizedQuery, $limit, $countryCodes, $acceptLanguage);

        $cache = AddressSearchCache::query()
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
                'message' => 'Nominatim search rate limit reached. Please retry shortly.',
            ];
        }

        RateLimiter::hit(self::PROVIDER_RATE_LIMIT_KEY, 1);

        try {
            $providerResponse = $this->searchProvider($query, $limit, $countryCodes, $acceptLanguage);
            $results = $this->normalizeProviderResponse($providerResponse['body']);

            $cache = AddressSearchCache::query()->updateOrCreate(
                [
                    'provider' => self::PROVIDER,
                    'cache_key' => $cacheKey,
                ],
                [
                    'query' => $query,
                    'normalized_query' => $normalizedQuery,
                    'countrycodes' => $countryCodes,
                    'accept_language' => $acceptLanguage,
                    'limit' => $limit,
                    'results' => $results,
                    'raw_response' => $providerResponse['body'],
                    'result_count' => count($results),
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
                'message' => 'Address search provider is unavailable.',
            ];
        }
    }

    protected function searchProvider(string $query, int $limit, ?string $countryCodes, ?string $acceptLanguage): array {
        $parameters = [
            'q' => $query,
            'format' => 'geocodejson',
            'addressdetails' => 1,
            'limit' => $limit,
        ];

        if ($countryCodes) {
            $parameters['countrycodes'] = $countryCodes;
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
            ->get(rtrim($this->baseUrl(), '/') . '/search', $parameters);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? [],
        ];
    }

    protected function normalizeProviderResponse(array $payload): array {
        return collect($payload['features'] ?? [])
            ->map(function (array $feature) {
                $geocoding = $feature['properties']['geocoding'] ?? [];
                $coordinates = $feature['geometry']['coordinates'] ?? [];

                $longitude = $coordinates[0] ?? null;
                $latitude = $coordinates[1] ?? null;

                return [
                    'provider' => self::PROVIDER,
                    'provider_id' => $this->providerId($geocoding),
                    'label' => $geocoding['label'] ?? null,
                    'country' => $geocoding['country'] ?? null,
                    'country_code' => isset($geocoding['country_code']) ? strtoupper($geocoding['country_code']) : null,
                    'county' => $geocoding['county'] ?? $geocoding['state'] ?? null,
                    'city' => $geocoding['city'] ?? $geocoding['town'] ?? $geocoding['village'] ?? $geocoding['municipality'] ?? null,
                    'postcode' => $geocoding['postcode'] ?? null,
                    'street' => $geocoding['street'] ?? null,
                    'house_number' => $geocoding['housenumber'] ?? null,
                    'latitude' => $latitude !== null ? (float)$latitude : null,
                    'longitude' => $longitude !== null ? (float)$longitude : null,
                    'raw' => $geocoding,
                ];
            })
            ->filter(fn(array $result) => !empty($result['label']) && $result['latitude'] !== null && $result['longitude'] !== null)
            ->values()
            ->toArray();
    }

    protected function providerId(array $geocoding): ?string {
        if (empty($geocoding['osm_type']) || empty($geocoding['osm_id'])) {
            return null;
        }

        return strtoupper(substr((string)$geocoding['osm_type'], 0, 1)) . $geocoding['osm_id'];
    }

    protected function formatResponse(AddressSearchCache $cache, string $source): array {
        return [
            'ok' => true,
            'data' => $cache->results ?? [],
            'meta' => [
                'source' => $source,
                'cache_id' => $cache->id,
                'expires_at' => optional($cache->expires_at)->toISOString(),
                'attribution' => 'Data © OpenStreetMap contributors',
            ],
        ];
    }

    protected function cacheKey(string $normalizedQuery, int $limit, ?string $countryCodes, ?string $acceptLanguage): string {
        return hash('md5', implode('|', [
            self::PROVIDER,
            $normalizedQuery,
            $countryCodes ?? '',
            $acceptLanguage ?? '',
            $limit,
        ]));
    }

    protected function normalizeQuery(string $query): string {
        return strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? $query));
    }

    protected function normalizeCountryCodes(?string $countryCodes): ?string {
        if (!$countryCodes) {
            return null;
        }

        $codes = collect(explode(',', $countryCodes))
            ->map(fn(string $code) => strtolower(trim($code)))
            ->filter(fn(string $code) => preg_match('/^[a-z]{2}$/', $code) === 1)
            ->unique()
            ->values()
            ->all();

        return empty($codes) ? null : implode(',', $codes);
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
        return max(1, (int) config('geo.nominatim.cache_ttl_days', 365));
    }

    protected function email(): ?string {
        return trim((string) config('geo.nominatim.email', '')) ?: null;
    }
}