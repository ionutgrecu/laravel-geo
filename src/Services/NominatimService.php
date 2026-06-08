<?php

namespace Ionutgrecu\LaravelGeo\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class NominatimService {
    protected string $baseUrl;
    protected string $userAgent;
    protected string $email;
    protected int $rateLimitMs;
    protected int $timeout;
    protected Client $client;

    protected static ?float $lastRequestTime = null;

    public function __construct() {
        $this->baseUrl      = rtrim(config('geo.nominatim.base_url', 'https://nominatim.openstreetmap.org'), '/');
        $this->userAgent    = config('geo.nominatim.user_agent', 'laravel-geo/1.0');
        $this->email        = config('geo.nominatim.email', '');
        $this->rateLimitMs  = config('geo.nominatim.rate_limit_ms', 1100);
        $this->timeout      = config('geo.nominatim.timeout', 30);

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => $this->timeout,
            'headers'  => [
                'User-Agent' => $this->userAgent,
                'Accept'     => 'application/json',
            ],
        ]);
    }

    protected function throttle(): void {
        if (self::$lastRequestTime !== null) {
            $elapsed = (microtime(true) - self::$lastRequestTime) * 1000;
            $sleep   = $this->rateLimitMs - $elapsed;
            if ($sleep > 0) {
                usleep((int) ($sleep * 1000));
            }
        }
    }

    protected function request(string $endpoint, array $params = []): array {
        $defaults = [
            'format'         => 'jsonv2',
            'addressdetails' => 1,
            'extratags'      => 1,
            'namedetails'    => 1,
        ];

        if ($this->email) {
            $defaults['email'] = $this->email;
        }

        $params = array_merge($defaults, $params);

        $this->throttle();

        try {
            $response = $this->client->get($endpoint, ['query' => $params]);
            self::$lastRequestTime = microtime(true);
            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (GuzzleException $e) {
            Log::warning('Nominatim API request failed: ' . $e->getMessage());
            self::$lastRequestTime = microtime(true);
            return [];
        }
    }

    // --- Nominatim search methods ---

    public function searchCountries(?string $regionCode = null): array {
        $params = [
            'featureType' => 'country',
            'limit'       => 40,
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
            'featureType'  => 'state',
            'limit'         => 40,
        ]);
    }

    public function searchCities(string $countryCode, ?string $countyName = null, ?array $boundingBox = null): array {
        $params = [
            'countrycodes' => strtolower($countryCode),
            'limit'        => 40,
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
            $results = $this->request('/search', $params);
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
            'q'            => $name,
            'countrycodes' => strtolower($countryCode),
            'featureType'  => 'state',
            'limit'        => 1,
        ]);

        if (!empty($results) && isset($results[0]['boundingbox'])) {
            return $results[0]['boundingbox'];
        }

        return null;
    }

    public function overpassNeighborhoodsByWikiDataId(string $wikiDataId): array {
        $overpassUrl = config('geo.overpass.base_url', 'https://overpass-api.de/api/interpreter');
        $overpassTimeout = config('geo.overpass.timeout', 60);

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . 'area["wikidata"="' . $wikiDataId . '"]->.searchArea;'
            . '('
            . 'node["place"~"suburb|neighbourhood|quarter|hamlet"](area.searchArea);'
            . 'way["place"~"suburb|neighbourhood|quarter|hamlet"](area.searchArea);'
            . 'relation["place"~"suburb|neighbourhood|quarter|hamlet"](area.searchArea);'
            . ');'
            . 'out tags center;';

        try {
            $this->throttle();
            $response = (new Client())->post($overpassUrl, [
                'form_params' => ['data' => $query],
                'timeout'     => $overpassTimeout,
                'headers'     => ['User-Agent' => $this->userAgent],
            ]);

            self::$lastRequestTime = microtime(true);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['elements'] ?? [];
        } catch (GuzzleException $e) {
            Log::warning('Overpass API request failed for neighborhoods by wikidata: ' . $e->getMessage());
            self::$lastRequestTime = microtime(true);
            return [];
        }
    }

    public function nominatimDetailsByPlaceId(string $placeId): array {
        $result = $this->request('/details', [
            'place_id'     => $placeId,
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
            'limit'   => 40,
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
        $overpassUrl = config('geo.overpass.base_url', 'https://overpass-api.de/api/interpreter');
        $overpassTimeout = config('geo.overpass.timeout', 60);

        if ($countyIsoCode) {
            $areaFilter = 'area["ISO3166-2"="' . $countyIsoCode . '"]->.searchArea;';
        } else {
            $areaFilter = 'area["ISO3166-1"="' . strtoupper($countryCode) . '"]->.searchArea;';
        }

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . $areaFilter
            . '('
            . 'node["place"~"city|town|village"](area.searchArea);'
            . 'way["place"~"city|town|village"](area.searchArea);'
            . 'relation["place"~"city|town|village"](area.searchArea);'
            . ');'
            . 'out tags;';

        try {
            $this->throttle();
            $response = (new Client())->post($overpassUrl, [
                'form_params' => ['data' => $query],
                'timeout'     => $overpassTimeout,
                'headers'     => ['User-Agent' => $this->userAgent],
            ]);

            self::$lastRequestTime = microtime(true);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['elements'] ?? [];
        } catch (GuzzleException $e) {
            Log::warning('Overpass API request failed for cities: ' . $e->getMessage());
            self::$lastRequestTime = microtime(true);
            return [];
        }
    }

    public function overpassNeighborhoods(float $lat, float $lon, float $radiusKm = 10): array {
        $overpassUrl = config('geo.overpass.base_url', 'https://overpass-api.de/api/interpreter');
        $overpassTimeout = config('geo.overpass.timeout', 60);

        $delta = $radiusKm * 0.009;
        $bbox  = ($lat - $delta) . ',' . ($lon - $delta) . ',' . ($lat + $delta) . ',' . ($lon + $delta);

        $query = '[out:json][timeout:' . $overpassTimeout . '];'
            . '('
            . 'node["place"~"suburb|neighbourhood|quarter|hamlet"](' . $bbox . ');'
            . 'way["place"~"suburb|neighbourhood|quarter|hamlet"](' . $bbox . ');'
            . 'relation["place"~"suburb|neighbourhood|quarter|hamlet"](' . $bbox . ');'
            . ');'
            . 'out tags center;';

        try {
            $this->throttle();
            $response = (new Client())->post($overpassUrl, [
                'form_params' => ['data' => $query],
                'timeout'     => $overpassTimeout,
                'headers'     => ['User-Agent' => $this->userAgent],
            ]);

            self::$lastRequestTime = microtime(true);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['elements'] ?? [];
        } catch (GuzzleException $e) {
            Log::warning('Overpass API request failed for neighborhoods: ' . $e->getMessage());
            self::$lastRequestTime = microtime(true);
            return [];
        }
    }

    // --- Parse methods ---

    public function parseCountryResult(array $result): array {
        $address    = $result['address'] ?? [];
        $extratags  = $result['extratags'] ?? [];
        $names      = $result['namedetails'] ?? [];

        $code = strtoupper($address['country_code'] ?? '');

        return array_filter([
            'code'         => $code,
            'name'         => $result['name'] ?? $address['country'] ?? null,
            'name_int'     => $names['international'] ?? $names['name:en'] ?? $result['name'] ?? null,
            'iso2'         => $code ?: null,
            'wiki_data_id' => $extratags['wikidata'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    public function parseCountyResult(array $result, string $countryCode): array {
        $address   = $result['address'] ?? [];
        $extratags = $result['extratags'] ?? [];

        $name      = $address['state'] ?? $result['name'] ?? null;
        $isoCode   = $address['ISO3166-2-lvl4'] ?? $address['ISO3166-2-lvl6'] ?? null;

        if ($isoCode) {
            $code = strtoupper($isoCode);
        } elseif ($name) {
            $code = strtoupper($countryCode) . '-' . strtoupper(Str::slug($name, ''));
        } else {
            $code = null;
        }

        return array_filter([
            'code'         => $code,
            'country_code' => strtoupper($countryCode),
            'name'         => $name,
            'wiki_data_id' => $extratags['wikidata'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    public function parseCityResult(array $result, ?string $countyCode = null): array {
        $address   = $result['address'] ?? [];
        $extratags = $result['extratags'] ?? [];

        $name = $address['city'] ?? $address['town'] ?? $address['village'] ?? $result['name'] ?? null;

        $wikiDataId = $extratags['wikidata'] ?? null;
        $code       = $wikiDataId;
        if (!$code) {
            $osmType = $result['osm_type'] ?? '';
            $osmId    = $result['osm_id'] ?? '';
            $code     = strtoupper(substr($osmType, 0, 1)) . $osmId;
        }

        return array_filter([
            'code'         => $code,
            'county_code'  => $countyCode,
            'name'         => $name,
            'latitude'     => $result['lat'] ?? null,
            'longitude'    => $result['lon'] ?? null,
            'wiki_data_id' => $wikiDataId,
            'type'         => $result['addresstype'] ?? $result['type'] ?? null,
            'place_rank'   => $result['place_rank'] ?? null,
            'place_id'     => $result['place_id'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    public function parseNeighborhoodResult(array $result, string $cityCode): array {
        $extratags = $result['extratags'] ?? [];

        return array_filter([
            'city_code'    => $cityCode,
            'name'         => $result['name'] ?? null,
            'wiki_data_id' => $extratags['wikidata'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    /**
     * Parse an Overpass API element into a city data array.
     */
    public function parseOverpassCityElement(array $element, ?string $countyCode = null): array {
        $tags = $element['tags'] ?? [];

        $name = $tags['name'] ?? null;
        if (!$name) return [];

        $wikiDataId = $tags['wikidata'] ?? null;
        $code       = $wikiDataId;
        if (!$code) {
            $osmType = $element['type'] ?? '';
            $osmId    = (string) ($element['id'] ?? '');
            $code     = strtoupper(substr($osmType, 0, 1)) . $osmId;
        }

        $lat = $element['lat'] ?? $element['center']['lat'] ?? $tags['lat'] ?? null;
        $lon = $element['lon'] ?? $element['center']['lon'] ?? $tags['lon'] ?? null;

        $placeType = $tags['place'] ?? null;
        $placeRank  = match ($placeType) {
            'city'    => 16,
            'town'    => 18,
            'village' => 20,
            default   => null,
        };

        return array_filter([
            'code'         => $code,
            'county_code'  => $countyCode,
            'name'         => $name,
            'latitude'     => $lat,
            'longitude'    => $lon,
            'wiki_data_id' => $wikiDataId,
            'type'         => $placeType,
            'place_rank'   => $placeRank,
        ], fn($value) => $value !== null && $value !== '');
    }

    /**
     * Parse an Overpass API element into a neighborhood data array.
     */
    public function parseOverpassNeighborhoodElement(array $element, string $cityCode): array {
        $tags = $element['tags'] ?? [];
        $name = $tags['name'] ?? null;

        if (!$name) return [];

        return array_filter([
            'city_code'    => $cityCode,
            'name'         => $name,
            'wiki_data_id' => $tags['wikidata'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
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