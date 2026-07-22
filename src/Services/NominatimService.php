<?php

namespace Ionutgrecu\LaravelGeo\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use function dd;
use function strtoupper;
use function usleep;

class NominatimService {
    protected string $baseUrl;
    protected string $userAgent;
    protected string $email;
    protected int    $rateLimitMs;
    protected int    $timeout;
    protected Client $client;

    public function __construct() {
        $this->baseUrl     = rtrim(config('geo.nominatim.base_url', 'https://nominatim.openstreetmap.org'), '/');
        $this->userAgent   = config('geo.nominatim.user_agent', config('app.name'));
        $this->email       = config('geo.nominatim.email', '');
        $this->rateLimitMs = config('geo.nominatim.rate_limit_ms', 1100);
        $this->timeout     = config('geo.nominatim.timeout', 30);

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Block until it is safe to call the Nominatim API again, using the
     * Laravel cache to coordinate rate limiting across separate web requests
     * (a static in-process timestamp does not survive between requests).
     */
    protected function throttleNominatim(): void {
        $this->acquireRateLimitLock(
            'laravel-geo:nominatim:throttle',
            (int) config('geo.nominatim.rate_limit_ms', 1100),
            60,
        );
    }

    /**
     * Block until it is safe to call the Overpass API again, using the
     * Laravel cache to coordinate across separate web requests.
     */
    protected function throttleOverpass(): void {
        $this->acquireRateLimitLock(
            'laravel-geo:overpass:throttle',
            (int) config('geo.overpass.rate_limit_ms', 1500)
        );
    }

    protected function acquireRateLimitLock(string $key, int $rateLimitMs, ?int $blockSecondsOverride = null): void {
        if ($rateLimitMs <= 0) {
            return;
        }

        // Lock auto-expires after the rate-limit window, so once we make a
        // request the next caller (this process or any other) is forced to
        // wait until the window elapses. We never release it manually.
        $ttlSeconds    = max(1, (int) ceil($rateLimitMs / 1000));
        $blockSeconds  = $blockSecondsOverride
            ?? max($ttlSeconds, (int) ceil($rateLimitMs * 3 / 1000));
        $retryDelayMs  = max(1, (int) ceil($rateLimitMs / 2));

        // Retry indefinitely on timeout. The caller prefers honoring the
        // Nominatim/Overpass rate-limit policy over returning fast, so we keep
        // waiting until the lock is acquired rather than firing the API call
        // without the gap. NOTE: under sustained contention this can block a
        // web request for a long time — long-running contexts (import
        // commands) are fine; web controllers may need a shorter
        // rate_limit_ms or a queue if they time out.
        while (true) {
            try {
                Cache::lock($key, $ttlSeconds)->block($blockSeconds);
                usleep($retryDelayMs * 1000);
                return;
            } catch (LockTimeoutException $e) {
                Log::debug('laravel-geo: throttle lock timeout for ' . $key . ', retrying');
                usleep($retryDelayMs * 1000);
            }
        }
    }

    protected function request(string $endpoint, array $params = []): array {
        $defaults = [
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'extratags' => 1,
            'namedetails' => 1,
        ];

        if ($this->email) {
            $defaults['email'] = $this->email;
        }

        $params = array_merge($defaults, $params);

        $this->throttleNominatim();

        try {
            $response = $this->client->get($endpoint, ['query' => $params]);
            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (GuzzleException $e) {
            Log::warning('Nominatim API request failed: ' . $e->getMessage());
            return [];
        }
    }

    // --- Nominatim search methods ---

    public function searchCountries(?string $regionCode = null): array {
        $params = [
            'featureType' => 'country',
            'limit' => 40,
        ];

        if ($regionCode) {
            $countries = $this->getCountriesForRegion($regionCode);
            $results   = [];
            foreach ($countries as $countryCode) {
                $params['countrycodes'] = strtolower($countryCode);
                $params['q']            = $countryCode;
                $response               = $this->request('/search', $params);
                if (!empty($response)) {
                    $results = array_merge($results, $response);
                }
            }
            return $results;
        }

        return $this->request('/search', $params);
    }

    public function searchCounties(string $countryCode): array {
        return $this->request('/search', [
            'countrycodes' => strtolower($countryCode),
            'featureType' => 'state',
            'limit' => 40,
        ]);
    }

    public function searchCities(string $countryCode, ?string $countyName = null, ?array $boundingBox = null): array {
        $params = [
            'countrycodes' => strtolower($countryCode),
            'limit' => 40,
            'polygon_geojson' => 1,
        ];

        if ($boundingBox) {
            $params['viewbox'] = $boundingBox[2] . ',' . $boundingBox[0] . ',' . $boundingBox[3] . ',' . $boundingBox[1];
            $params['bounded'] = 1;
        }

        if ($countyName && !isset($params['bounded'])) {
            $params['state'] = $countyName;
        }

        $allResults = [];

        $featureTypes = ['city', 'settlement'];

        foreach ($featureTypes as $featureType) {
            $params['featureType'] = $featureType;
            $results               = $this->request('/search', $params);
            if (is_array($results)) {
                $allResults = array_merge($allResults, $results);
            }
        }

        $seen = [];
        return array_filter($allResults, function ($result) use (&$seen) {
            $key = ($result['osm_type'] ?? '') . '_' . ($result['osm_id'] ?? '');
            if ($key && isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        });
    }

    public function lookupBoundingBox(string $countryCode, string $name): ?array {
        $results = $this->request('/search', [
            'q' => $name,
            'countrycodes' => strtolower($countryCode),
            'featureType' => 'state',
            'limit' => 1,
        ]);

        if (!empty($results) && isset($results[0]['boundingbox'])) {
            return $results[0]['boundingbox'];
        }

        return null;
    }

    public function overpassCityBoundaryByWikiDataId(string $wikiDataId): ?array {
        $overpassTimeout = (int) config('geo.overpass.timeout', 180);

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . 'relation["wikidata"="' . $wikiDataId . '"]["boundary"="administrative"];'
            . 'out geom;';

        $element = $this->overpassFirstElement($query, 'city boundary by wikidata');
        return $element ? $this->convertOverpassGeometryToGeoJSON($element) : null;
    }

    public function overpassCityBoundaryByName(string $name, string $countryCode, ?string $countyIsoCode = null): ?array {
        $overpassTimeout = (int) config('geo.overpass.timeout', 180);

        if ($countyIsoCode) {
            $areaFilter = 'area["ISO3166-2"="' . $countyIsoCode . '"]["boundary"="administrative"]->.searchArea;';
        } else {
            $areaFilter = 'area["ISO3166-1"="' . strtoupper($countryCode) . '"]["boundary"="administrative"]->.searchArea;';
        }

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . $areaFilter
            . 'relation["boundary"="administrative"]["name"="' . $this->escapeOverpassString($name) . '"](area.searchArea);'
            . 'out geom;';

        $element = $this->overpassFirstElement($query, 'city boundary by name');
        return $element ? $this->convertOverpassGeometryToGeoJSON($element) : null;
    }

    public function polygonIsPoint(?string $polygon): bool {
        if ($polygon === null || $polygon === '')
            return true;
        $decoded = json_decode($polygon, true);
        return !is_array($decoded) || ($decoded['type'] ?? '') === 'Point';
    }

    protected function overpassFirstElement(string $query, string $context): ?array {
        $data     = $this->overpassRequest($query, $context);
        $elements = $data['elements'] ?? [];
        return $elements[0] ?? null;
    }

    /**
     * POST an Overpass QL query and return the decoded JSON envelope, with
     * retry on transient HTTP 429 / 503 / 504. Up to 5 attempts total with
     * exponential backoff (1.5s, 3s, 6s, 12s, 24s) plus ±25% jitter. Each
     * attempt re-acquires the cross-request Overpass throttle lock so the
     * rate-limit gap is honored even across retries. Returns null when the
     * final attempt fails or a non-retryable error is hit; callers surface
     * that as null/empty exactly as before.
     */
    protected function overpassRequest(string $query, string $context): ?array {
        $overpassUrl     = (string) config('geo.overpass.base_url', 'https://overpass-api.de/api/interpreter');
        $overpassTimeout = (int) config('geo.overpass.timeout', 180);

        $retryable    = [429, 503, 504];
        $maxAttempts  = 5;
        $baseDelayMs  = 1500;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->throttleOverpass();

            try {
                $response = (new Client())->post($overpassUrl, [
                    'form_params' => ['data' => $query],
                    'timeout' => $overpassTimeout,
                    'headers' => ['User-Agent' => $this->userAgent],
                ]);

                $body = $response->getBody()->getContents();
//dump($query,$body);
                return json_decode($body, true) ?: [];
            } catch (GuzzleException $e) {
                $status = ($e instanceof BadResponseException && $e->hasResponse())
                    ? $e->getResponse()->getStatusCode()
                    : null;

                if (!in_array($status, $retryable, true) || $attempt === $maxAttempts) {
                    Log::warning('Overpass API request failed for ' . $context . ': ' . $e->getMessage());
                    return null;
                }

                // Exponential backoff with ±25% jitter.
                $delayMs = (int) ceil($baseDelayMs * (2 ** ($attempt - 1)));
                $jitter  = random_int((int) (-$delayMs * 0.25), (int) ($delayMs * 0.25));
                $sleepMs = max(0, $delayMs + $jitter);
                usleep($sleepMs * 1000);

                Log::debug(sprintf(
                    'laravel-geo: overpass %d for %s, retrying (attempt %d/%d after %dms)',
                    $status, $context, $attempt + 1, $maxAttempts, $sleepMs,
                ));
            }
        }

        return null;
    }

    protected function escapeOverpassString(string $value): string {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public function overpassNeighborhoodsByWikiDataId(string $wikiDataId): array {
        $query = '[out:json][timeout:180];

                   area["wikidata"="' . $wikiDataId . '"]["boundary"="administrative"]->.city;

                   (
                     nwr(area.city)["place"~"^(neighbourhood|quarter|suburb|city_block|district)$"];
                   );

                   out center tags;';

        $data = $this->overpassRequest($query, 'neighborhoods by wikidata');

        return $data['elements'] ?? [];
    }

    /**
     * Look up a single OSM object (e.g. "R1466586") via Nominatim /lookup and
     * return its full polygon geometry as a GeoJSON array, or null when the
     * object has no boundary polygon or the request fails.
     */
    public function nominatimLookupPolygon(string $osmId): ?array {
        $result = $this->request('/lookup', [
            'osm_ids' => $osmId,
            'format' => 'geojson',
            'polygon_geojson' => 1,
            'addressdetails' => 0,
            'extratags' => 0,
            'namedetails' => 0,
        ]);

        if (!is_array($result)) {
            return null;
        }

        $features = $result['features'] ?? [];

        return $features[0]['geometry'] ?? null;
    }

    /**
     * Resolve a country's OSM relation id via the Overpass API by its ISO2
     * code, returning the id in the format Nominatim /lookup expects (e.g.
     * "R1466586"), or null when the country relation cannot be found or the
     * request fails. Countries are admin_level=2 relations tagged with
     * ISO3166-1.
     */
    public function overpassCountryOsmId(string $countryCode): ?string {
        $overpassTimeout = (int) config('geo.overpass.timeout', 180);

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . 'relation["boundary"="administrative"]["admin_level"="2"]["ISO3166-1"="' . strtoupper($countryCode) . '"];'
            . 'out tags;';

        $data = $this->overpassRequest($query, 'country');

        $elements = $data['elements'] ?? [];
        if (empty($elements[0])) {
            return null;
        }

        $id = $elements[0]['id'] ?? null;

        return $id !== null ? 'R' . (string)$id : null;
    }

    public function nominatimDetailsByPlaceId(string $placeId): array {
        $result = $this->request('/details', [
            'place_id' => $placeId,
            'linked_place' => 1,
        ]);

        $hierarchy = $result['hierarchy'] ?? [];

        $allowedTypes = ['suburb', 'neighbourhood', 'quarter', 'hamlet', 'borough', 'city_district', 'district', 'subdivision'];

        $neighborhoods = [];
        foreach ($hierarchy as $level) {
            foreach ($level as $item) {
                $type = $item['type'] ?? '';
                if (in_array($type, $allowedTypes)) {
                    $neighborhoods[] = $item;
                }
            }
        }

        return $neighborhoods;
    }

    public function searchNeighborhoods(float $lat, float $lon, float $radiusKm = 10): array {
        $delta = $radiusKm * 0.009;

        $params = [
            'viewbox' => ($lon - $delta) . ',' . ($lat - $delta) . ',' . ($lon + $delta) . ',' . ($lat + $delta),
            'bounded' => 1,
            'limit' => 40,
            'polygon_geojson' => 1,
        ];

        $results = $this->request('/search', $params);

        $allowedTypes = ['suburb', 'neighbourhood', 'quarter', 'hamlet', 'borough', 'city_district', 'district', 'subdivision'];

        return array_filter($results, function ($result) use ($allowedTypes) {
            $addressType = $result['addresstype'] ?? '';
            $type        = $result['type'] ?? '';
            return in_array($addressType, $allowedTypes) || ($type === 'neighbourhood');
        });
    }

    // --- Overpass API methods for enumeration ---

    public function overpassCities(string $countryCode, ?string $countyIsoCode = null): array {
        $overpassTimeout = (int) config('geo.overpass.timeout', 180);

        if ($countyIsoCode) {
            $areaFilter = 'area["ISO3166-2"="' . $countyIsoCode . '"]["boundary"="administrative"]->.searchArea;';
        } else {
            $areaFilter = 'area["ISO3166-1"="' . strtoupper($countryCode) . '"]["boundary"="administrative"]->.searchArea;';
        }

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . $areaFilter
            . '('
            . 'node["place"~"city|town|village"](area.searchArea);'
            . 'way["place"~"city|town|village"](area.searchArea);'
            . 'relation["place"~"city|town|village"](area.searchArea);'
            . ');'
            . 'out geom center tags;';

        $data = $this->overpassRequest($query, 'cities');

        return $data['elements'] ?? [];
    }

    public function overpassCounties(string $countryCode): array {
        $overpassTimeout = (int) config('geo.overpass.timeout', 180);

        $areaFilter = 'area["ISO3166-1"="' . strtoupper($countryCode) . '"]["boundary"="administrative"]->.searchArea;';

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . $areaFilter
            . 'relation["boundary"="administrative"]["admin_level"~"^[4-6]$"]["ISO3166-2"~"^'.strtoupper($countryCode).'-"](area.searchArea);'
            . 'out center tags;';

        $data = $this->overpassRequest($query, 'counties');

        return $data['elements'] ?? [];
    }

    public function overpassNeighborhoods(float $lat, float $lon, float $radiusKm = 10): array {
        $overpassTimeout = (int) config('geo.overpass.timeout', 180);

        $delta = $radiusKm * 0.009;
        $bbox  = ($lat - $delta) . ',' . ($lon - $delta) . ',' . ($lat + $delta) . ',' . ($lon + $delta);

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . '('
            . 'node["place"~"suburb|neighbourhood|quarter|hamlet"](' . $bbox . ');'
            . 'way["place"~"suburb|neighbourhood|quarter|hamlet"](' . $bbox . ');'
            . 'relation["place"~"suburb|neighbourhood|quarter|hamlet"](' . $bbox . ');'
            . ');'
            . 'out geom center;';

        $data = $this->overpassRequest($query, 'neighborhoods');

        return $data['elements'] ?? [];
    }

    // --- Parse methods ---

    public function parseCountryResult(array $result): array {
        $address   = $result['address'] ?? [];
        $extratags = $result['extratags'] ?? [];
        $names     = $result['namedetails'] ?? [];

        $code = strtoupper($address['country_code'] ?? '');

        return array_filter([
            'code' => $code,
            'name' => $result['name'] ?? $address['country'] ?? null,
            'name_int' => $names['international'] ?? $names['name:en'] ?? $result['name'] ?? null,
            'iso2' => $code ?: null,
            'wiki_data_id' => $extratags['wikidata'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    public function parseCountyResult(array $result, string $countryCode): array {
        $address   = $result['address'] ?? [];
        $extratags = $result['extratags'] ?? [];

        $name    = $address['state'] ?? $result['name'] ?? null;
        $isoCode = $address['ISO3166-2-lvl4'] ?? $address['ISO3166-2-lvl6'] ?? null;

        if ($isoCode) {
            $code = strtoupper($isoCode);
        } elseif ($name) {
            $code = strtoupper($countryCode) . '-' . strtoupper(Str::slug($name, ''));
        } else {
            $code = null;
        }

        return array_filter([
            'code' => $code,
            'country_code' => strtoupper($countryCode),
            'name' => $name,
            'wiki_data_id' => $extratags['wikidata'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    public function parseOverpassCountyElement(array $element, string $countryCode): array {
        $tags = $element['tags'] ?? [];
        $name = $tags['name'] ?? null;
        if (!$name) {
            return [];
        }

        $isoCode = $tags['ISO3166-2'] ?? null;
        if ($isoCode) {
            $code = strtoupper($isoCode);
        } else {
            $code = strtoupper($countryCode) . '-' . strtoupper(Str::slug($name, ''));
        }

        // OSM object id prefixed with the type letter (N/W/R), matching the
        // format Nominatim /lookup expects for its `osm_ids` parameter.
        $osmType = strtoupper(substr((string)($element['type'] ?? ''), 0, 1));
        $osmId   = $osmType !== '' && ($element['id'] ?? null) !== null
            ? $osmType . (string)$element['id']
            : null;

        return array_filter([
            'code' => $code,
            'country_code' => strtoupper($countryCode),
            'osm_id' => $osmId,
            'name' => $name,
            'fips' => $tags['fips_code'] ?? $tags['fips'] ?? null,
            'wiki_data_id' => $tags['wikidata'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    public function parseCityResult(array $result, ?string $countyCode = null): array {
        $address   = $result['address'] ?? [];
        $extratags = $result['extratags'] ?? [];

        $name = $address['city'] ?? $address['town'] ?? $address['village'] ?? $result['name'] ?? null;

        $wikiDataId = $extratags['wikidata'] ?? null;

        // Nominatim Lookup format: OSM object ID prefixed with N (node), W (way), or R (relation).
        $osmType = $result['osm_type'] ?? '';
        $osmId   = $result['osm_id'] ?? '';
        $code    = strtoupper(substr($osmType, 0, 1)) . $osmId;

        return array_filter([
            'code' => $code,
            'county_code' => $countyCode,
            'name' => $name,
            'latitude' => $result['lat'] ?? null,
            'longitude' => $result['lon'] ?? null,
            'wiki_data_id' => $wikiDataId,
            'type' => $result['addresstype'] ?? $result['type'] ?? null,
            'place_rank' => $result['place_rank'] ?? null,
            'population' => isset($extratags['population']) ? (int)$extratags['population'] : null,
            'place_id' => $result['place_id'] ?? null,
            'polygon' => isset($result['geojson']) ? json_encode($result['geojson']) : null,
        ], fn($value) => $value !== null && $value !== '');
    }

    public function parseNeighborhoodResult(array $result, string $cityCode): array {
        $extratags = $result['extratags'] ?? [];

        $wikiDataId = $extratags['wikidata'] ?? null;
        $code       = $wikiDataId;
        if (!$code) {
            $osmType = $result['osm_type'] ?? '';
            $osmId   = $result['osm_id'] ?? '';
            $code    = strtoupper(substr($osmType, 0, 1)) . $osmId;
        }

        return array_filter([
            'city_code' => $cityCode,
            'code' => $code,
            'name' => $result['name'] ?? null,
            'wiki_data_id' => $wikiDataId,
            'type' => $result['addresstype'] ?? $result['type'] ?? null,
            'place_rank' => $result['place_rank'] ?? null,
            'latitude' => $result['lat'] ?? null,
            'longitude' => $result['lon'] ?? null,
            'polygon' => isset($result['geojson']) ? json_encode($result['geojson']) : null,
        ], fn($value) => $value !== null && $value !== '');
    }

    /**
     * Parse an Overpass API element into a city data array.
     */
    public function parseOverpassCityElement(array $element, ?string $countyCode = null): array {
        $tags = $element['tags'] ?? [];

        $name = $tags['name'] ?? null;
        if (!$name)
            return [];

        $wikiDataId = $tags['wikidata'] ?? null;

        // Nominatim Lookup format: OSM object ID prefixed with N (node), W (way), or R (relation).
        $osmType = $element['type'] ?? '';
        $osmId   = (string)($element['id'] ?? '');
        $code    = strtoupper(substr($osmType, 0, 1)) . $osmId;

        $lat = $element['lat'] ?? $element['center']['lat'] ?? $tags['lat'] ?? null;
        $lon = $element['lon'] ?? $element['center']['lon'] ?? $tags['lon'] ?? null;

        $placeType = $tags['place'] ?? null;
        $placeRank = match ($placeType) {
            'city'    => 16,
            'town'    => 18,
            'village' => 20,
            default   => null,
        };

        $geojson = $this->convertOverpassGeometryToGeoJSON($element);

        return array_filter([
            'code' => $code,
            'county_code' => $countyCode,
            'name' => $name,
            'latitude' => $lat,
            'longitude' => $lon,
            'wiki_data_id' => $wikiDataId,
            'type' => $placeType,
            'place_rank' => $placeRank,
            'population' => isset($tags['population']) ? (int)$tags['population'] : null,
            'polygon' => $geojson ? json_encode($geojson) : null,
        ], fn($value) => $value !== null && $value !== '');
    }

    /**
     * Parse an Overpass API element into a neighborhood data array.
     */
    public function parseOverpassNeighborhoodElement(array $element, string $cityCode): array {
        $code = $element['code'] ?? null;
        $tags = $element['tags'] ?? [];
        $name = $tags['name'] ?? null;

        if (!$name)
            return [];

        $wikiDataId = $tags['wikidata'] ?? null;
        if (!$code)
            $code = $wikiDataId;

        // OSM id (type-prefixed, e.g. "R1466586") is always derived so the
        // queued EnrichNeighborhoodBoundaryJob can call Nominatim /lookup
        // by osm_id regardless of which value `code` ended up with
        // (wikidata vs OSM-prefixed). Empty when the element has no id.
        $osmType  = $element['type'] ?? '';
        $osmIdRaw = (string)($element['id'] ?? '');
        $osmIdKey = $osmIdRaw !== '' ? strtoupper(substr($osmType, 0, 1)) . $osmIdRaw : null;

        if (!$code)
            $code = $osmIdKey;

        $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
        $lon = $element['lon'] ?? $element['center']['lon'] ?? null;

        $geojson = $this->convertOverpassGeometryToGeoJSON($element);

        $placeType = $tags['place'] ?? null;
        $placeRank = match ($placeType) {
            'neighbourhood' => 30,
            'quarter'       => 25,
            'suburb'         => 20,
            'city_block'     => 20,
            'district'       => 14,
            default          => null,
        };

        return array_filter([
            'city_code' => $cityCode,
            'code' => $code,
            'osm_id' => $osmIdKey,
            'name' => $name,
            'wiki_data_id' => $wikiDataId,
            'type' => $placeType,
            'place_rank' => $placeRank,
            'latitude' => $lat,
            'longitude' => $lon,
            'polygon' => $geojson ? json_encode($geojson) : null,
        ], fn($value) => $value !== null && $value !== '');
    }

    /**
     * Convert Overpass API element geometry to GeoJSON format.
     * Handles nodes (Point), ways (Polygon if closed), and relations (MultiPolygon from outer members).
     */
    public function convertOverpassGeometryToGeoJSON(array $element): ?array {
        $type = $element['type'] ?? '';

        // Node → Point
        if ($type === 'node') {
            $lat = $element['lat'] ?? null;
            $lon = $element['lon'] ?? null;
            if ($lat === null || $lon === null)
                return null;

            return ['type' => 'Point', 'coordinates' => [(float)$lon, (float)$lat]];
        }

        // Way → Polygon (if closed ring) or LineString
        if ($type === 'way') {
            $geometry = $element['geometry'] ?? [];
            if (empty($geometry))
                return null;

            $coordinates = array_map(fn($p) => [(float)($p['lon'] ?? 0), (float)($p['lat'] ?? 0)], $geometry);

            // Closed way → Polygon
            $first = $coordinates[0] ?? null;
            $last  = $coordinates[count($coordinates) - 1] ?? null;
            if ($first && $last && $first[0] === $last[0] && $first[1] === $last[1] && count($coordinates) >= 4) {
                return ['type' => 'Polygon', 'coordinates' => [$coordinates]];
            }

            // Open way → LineString (not a boundary, but still useful)
            return ['type' => 'LineString', 'coordinates' => $coordinates];
        }

        // Relation → MultiPolygon (assemble from outer member ways)
        if ($type === 'relation') {
            $members    = $element['members'] ?? [];
            $outerRings = [];

            foreach ($members as $member) {
                if (($member['role'] ?? '') !== 'outer')
                    continue;
                $memberGeometry = $member['geometry'] ?? [];
                if (empty($memberGeometry))
                    continue;

                $coordinates = array_map(fn($p) => [(float)($p['lon'] ?? 0), (float)($p['lat'] ?? 0)], $memberGeometry);
                if (count($coordinates) >= 4) {
                    $outerRings[] = $coordinates;
                }
            }

            if (empty($outerRings))
                return null;

            if (count($outerRings) === 1) {
                return ['type' => 'Polygon', 'coordinates' => $outerRings];
            }

            return ['type' => 'MultiPolygon', 'coordinates' => array_map(fn($ring) => [$ring], $outerRings)];
        }

        return null;
    }

    protected function getCountriesForRegion(string $regionCode): array {
        $jsonService = app(JsonLocationsService::class);
        $countries   = $jsonService->getCountries($regionCode);
        $codes       = [];
        foreach ($countries as $country) {
            if (!empty($country['code'])) {
                $codes[] = $country['code'];
            }
        }
        return $codes;
    }
}