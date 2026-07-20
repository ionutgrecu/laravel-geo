<?php

namespace Ionutgrecu\LaravelGeo\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Ionutgrecu\LaravelGeo\Jobs\EnrichCityBoundaryJob;
use Ionutgrecu\LaravelGeo\Models\City;
use Ionutgrecu\LaravelGeo\Models\Country;
use Ionutgrecu\LaravelGeo\Models\County;
use Ionutgrecu\LaravelGeo\Models\Neighborhood;
use Ionutgrecu\LaravelGeo\Models\Region;

/**
 * Class GeoService
 * @package Ionutgrecu\LaravelGeo\Services
 * @description This class is responsible for working with app available geo locations (regions, countries, counties).
 */
class GeoService {
    protected JsonLocationsService $jsonLocationsService;
    protected NominatimService     $nominatimService;

    public function __construct() {
        $this->jsonLocationsService = app(JsonLocationsService::class);
        $this->nominatimService     = app(NominatimService::class);
    }

    /**
     * @param array|null $regions
     * @return \Generator
     * @description This method imports json regions (known region) into the database (available regions). If the $regions parameter is provided, only the regions with the specified ISO2 codes will be imported.
     */
    function importRegions(?array $regions = null) {
        $staticRegions = $this->jsonLocationsService->getRegions();
        foreach ($staticRegions as $staticRegion) {
            if ($regions) {
                array_walk($regions, function (&$region) {
                    $region = strtoupper(trim($region));
                });
                if (!in_array($staticRegion['code'], $regions))
                    continue;
            }

            $region = Region::firstOrNew([
                'code' => $staticRegion['code'],
            ]);
            $region->fill([
                'name' => $staticRegion['name'],
                'iso2' => $staticRegion['iso2'],
                'wiki_data_id' => $staticRegion['wikiDataId'],
            ]);
            $region->save();

            yield $region;
        }
    }

    function importCountry(array $country) {
        $countryModel = Country::firstOrNew([
            'code' => $country['code'],
        ]);
        $countryModel->fill([
            'region_code' => $country['regionCode'],
            'name' => $country['name'],
            'name_int' => $country['nameInt'],
            'code' => $country['code'],
            'iso2' => $country['iso2'],
            'iso3' => $country['iso3'],
            'iso_numeric' => $country['isoNumeric'],
            'phone_code' => $country['phoneCode'],
            'currency' => $country['currency'],
            'languages' => $country['languages'],
            'wiki_data_id' => $country['wikiDataId'],
        ]);
        $countryModel->save();

        return $countryModel;
    }

    function importCounty(array $county) {
        $countyModel = County::firstOrNew([
            'code' => $county['code'],
        ]);
        $countyModel->fill([
            'country_code' => $county['countryCode'],
            'name' => $county['name'],
            'fips' => $county['fips'],
            'wiki_data_id' => $county['wikiDataId'],
        ]);
        $countyModel->save();

        return $countyModel;
    }

    function importCity(array $city): City {
        $cityModel = City::firstOrNew([
            'code' => $city['code'],
        ]);
        $cityModel->fill([
            'county_code' => $city['countyCode'],
            'name' => $city['name'],
            'latitude' => $city['latitude'],
            'longitude' => $city['longitude'],
            'wiki_data_id' => $city['wikiDataId'] ?? null,
            'type' => $city['type'] ?? null,
            'place_rank' => $city['placeRank'] ?? null,
            'place_id' => $city['placeId'] ?? null,
            'polygon' => $city['polygon'] ?? null,
        ]);
        $cityModel->save();

        return $cityModel;
    }

    function getRegions(bool $includeCountries = false): Collection {
        $regionQueryBuilder = Region::query();

        if ($includeCountries)
            $regionQueryBuilder->with('countries');

        return $regionQueryBuilder->get();
    }

    function getCountries(bool $includeCounties = false, bool $online = true): Collection {
        $countryQueryBuilder = Country::query();

        if ($includeCounties)
            $countryQueryBuilder->with('counties');

        $results = $countryQueryBuilder->get();

        if ($results->isEmpty() && $online) {
            $this->fetchCountriesFromNominatim();

            $countryQueryBuilder = Country::query();
            if ($includeCounties)
                $countryQueryBuilder->with('counties');
            $results = $countryQueryBuilder->get();
        }

        return $results;
    }

    function getCountriesByRegion(string $regionCode, bool $includeCounties = false): Collection {
        $query = Country::query()->where('region_code', $regionCode)->orderBy('name', 'ASC');

        if ($includeCounties)
            $query->with('counties');

        return $query->get();
    }

    function getCounties(string $countryCode, bool $includeCities = false, bool $online = true): Collection {
        $countyQueryBuilder = County::query()->where('country_code', $countryCode)->orderBy('name', 'ASC');

        if ($includeCities)
            $countyQueryBuilder->with('cities');

        $results = $countyQueryBuilder->get();

        if ($results->isEmpty() && $online) {
            $this->fetchCountiesFromNominatim($countryCode);

            $countyQueryBuilder = County::query()->where('country_code', $countryCode)->orderBy('name', 'ASC');
            if ($includeCities)
                $countyQueryBuilder->with('cities');
            $results = $countyQueryBuilder->get();
        }

        return $results;
    }

    function getCities(?string $countyCode = null, ?string $countryCode = null, bool $online = true): Collection {
        $query = City::query()->orderBy('name', 'ASC');

        if ($countyCode) {
            $query->where('county_code', $countyCode);
        }

        if ($countryCode) {
            $query->whereHas('county', function ($q) use ($countryCode) {
                $q->where('country_code', $countryCode);
            });
        }

        $results = $query->get();

        if ($results->isEmpty() && $online) {
            $this->fetchCitiesFromNominatim($countyCode, $countryCode);
            $results = $query->get();
        }

        return $results;
    }

    function getNeighborhoods(string $cityCode, bool $online = true): ?Collection {
        $results = Neighborhood::query()->where('city_code', $cityCode)->orderBy('name', 'ASC')->get();

        // A sentinel row with a null name marks "we already checked, this city has no neighborhoods".
        if ($results->contains(fn($n) => $n->name === null))
            return null;

        if ($results->isEmpty() && $online) {
            $dataList = $this->fetchNeighborhoodsFromNominatim($cityCode);

            if (empty($dataList)) {
                // Persist the null sentinel so we don't hit Nominatim again for this city.
                Neighborhood::create([
                    'city_code' => $cityCode,
                    'code' => null,
                    'name' => null,
                ]);
            } else {
                foreach ($dataList as $data) {
                    if (empty($data['code']) || empty($data['name']))
                        continue;

                    $neighborhood = Neighborhood::firstOrNew(['code' => $data['code']]);
                    $neighborhood->fill($data)->save();
                }
            }

            $results = Neighborhood::query()->where('city_code', $cityCode)->orderBy('name', 'ASC')->get();

            if ($results->contains(fn($n) => $n->name === null))
                return null;
        }

        return $results;
    }

    function getLocationsTree(): Collection {
        return Region::with('countries', 'countries.counties')->get();
    }

    private function fetchCountriesFromNominatim(): void {
        try {
            $results = $this->nominatimService->searchCountries();

            foreach ($results as $result) {
                $data = $this->nominatimService->parseCountryResult($result);
                if (empty($data['code']))
                    continue;

                $country = Country::firstOrNew(['code' => $data['code']]);
                $country->fill($data)->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Nominatim fetch failed for countries: ' . $e->getMessage());
        }
    }

    private function fetchCountiesFromNominatim(string $countryCode): void {
        try {
            $elements = $this->nominatimService->overpassCounties($countryCode);

            foreach ($elements as $element) {
                $data = $this->nominatimService->parseOverpassCountyElement($element, $countryCode);
                if (empty($data['code']) || empty($data['name']))
                    continue;

                $county = County::firstOrNew(['code' => $data['code']]);
                $county->fill($data)->save();
            }
        } catch (\Throwable $e) {
            Log::warning("Nominatim fetch failed for counties in {$countryCode}: " . $e->getMessage());
        }
    }

    private function fetchCitiesFromNominatim(?string $countyCode, ?string $countryCode): void {
        if (!$countryCode && !$countyCode)
            return;

        $resolvedCountryCode = $countryCode;
        $countyName          = null;

        if ($countyCode && !$countryCode) {
            $county = County::query()->where('code', $countyCode)->first();
            if ($county) {
                $resolvedCountryCode = $county->country_code;
                $countyName          = $county->name;
            }
        } elseif ($countyCode) {
            $county = County::query()->where('code', $countyCode)->first();
            if ($county) {
                $countyName = $county->name;
            }
        }

        if (!$resolvedCountryCode)
            return;

        try {
            // Use Overpass API for full enumeration when we have a county ISO code
            if ($countyCode) {
                $elements = $this->nominatimService->overpassCities($resolvedCountryCode, $countyCode);
                $elements = $this->dedupOverpassCityElements($elements);

                foreach ($elements as $element) {
                    $data = $this->nominatimService->parseOverpassCityElement($element, $countyCode);
                    if (empty($data['name']) || empty($data['code']))
                        continue;

                    // Skip if a more prominent representation of the same city
                    // already exists (lower place_rank = more prominent). Keeps
                    // the existing row and avoids demoting it / re-keying its
                    // code via the OR-match below.
                    if (!empty($data['wiki_data_id']) && $data['place_rank'] !== null) {
                        if (City::where('wiki_data_id', $data['wiki_data_id'])
                            ->whereNotNull('place_rank')
                            ->where('place_rank', '<', $data['place_rank'])
                            ->exists()
                        ) {
                            continue;
                        }
                    }

                    // Look up an existing row by the most specific key available for
                    // this call. When a county is in scope, also match by (name,
                    // county_code) so a city re-arriving with a different OSM
                    // code (e.g. node -> relation) still hits its stored row.
                    // Join on wiki_data_id only when BOTH the incoming $data
                    // and the stored row actually carry one — the whereNotNull
                    // guards the OR clause from matching NULL-keyed rows.
                    $query = City::query();

                    if ($countyCode) {
                        $query->where(function ($q) use ($data, $countyCode) {
                            $q->where('name', $data['name'])
                              ->where('county_code', $countyCode);
                        })->orWhere('code', $data['code']);

                        if (!empty($data['wiki_data_id'])) {
                            $query->orWhere(function ($q) use ($data) {
                                $q->whereNotNull('wiki_data_id')
                                  ->where('wiki_data_id', $data['wiki_data_id']);
                            });
                        }
                    } else {
                        $query->where('code', $data['code']);

                        if (!empty($data['wiki_data_id'])) {
                            $query->orWhere(function ($q) use ($data) {
                                $q->whereNotNull('wiki_data_id')
                                  ->where('wiki_data_id', $data['wiki_data_id']);
                            });
                        }
                    }

                    $city = $query->first();
                    if (!$city)
                        $city = new City();

                    // Fill only empty properties of the existing row from
                    // $data. Never overwrite a previously stored value (e.g.
                    // a real Polygon must not be clobbered by a new Point).
                    // The `code` attribute is resolved separately by OSM-type
                    // priority (N > R > W).
                    foreach ($data as $key => $value) {
                        if ($key === 'code')
                            continue;

                        if ($value === null || $value === '')
                            continue;

                        $current = $city->getAttribute($key);
                        if ($current === null || $current === '') {
                            $city->setAttribute($key, $value);
                        }
                    }

                    $city->code = $this->pickCityCodeByPriority($city->code ?? null, $data['code'] ?? null);
                    $city->save();

                    // Dispatch a per-city async enrichment job for every
                    // stored city. The job self-decides whether to fetch a
                    // boundary: if the stored polygon is already a real
                    // Polygon/MultiPolygon (way/relation cities) it exits
                    // successfully without an Overpass call.
                    EnrichCityBoundaryJob::dispatch($city->code, $resolvedCountryCode, $countyCode);
                }
                return;
            }

            // Fallback to Nominatim search for country-wide queries
            $boundingBox = null;
            if ($countyName) {
                $boundingBox = $this->nominatimService->lookupBoundingBox($resolvedCountryCode, $countyName);
            }

            $results = $this->nominatimService->searchCities($resolvedCountryCode, $countyName, $boundingBox);

            foreach ($results as $result) {
                $data = $this->nominatimService->parseCityResult($result, $countyCode);
                if (empty($data['name']))
                    continue;

                $code = $data['code'] ?? null;
                if (!$code)
                    continue;

                $data = $this->enrichCityBoundaryPolygon($data, $resolvedCountryCode, $countyCode);

                $city = City::firstOrNew(['code' => $code]);
                $city->fill($data)->save();
            }
        } catch (\Throwable $e) {
            Log::warning("Nominatim fetch failed for cities: " . $e->getMessage(),['trace'=>$e->getTraceAsString()]);
        }
    }

    /**
     * When a city was parsed as a Point (or with no geometry), attempt to fetch
     * its real administrative boundary polygon via Overpass — first by wikidata
     * ID, then by name within the county/country area. Leaves the polygon as-is
     * when a real polygon was already parsed (way/relation cities) or when no
     * boundary relation can be found.
     */
    private function enrichCityBoundaryPolygon(array $data, string $countryCode, ?string $countyCode): array {
        if (!$this->nominatimService->polygonIsPoint($data['polygon'] ?? null)) {
            return $data;
        }

        $boundary = null;
        if (!empty($data['wiki_data_id'])) {
            $boundary = $this->nominatimService->overpassCityBoundaryByWikiDataId($data['wiki_data_id']);
        }
        if (!$boundary && !empty($data['name'])) {
            $boundary = $this->nominatimService->overpassCityBoundaryByName(
                $data['name'], $countryCode, $countyCode
            );
        }

        if ($boundary) {
            $data['polygon'] = json_encode($boundary);
        }

        return $data;
    }

    /**
     * Pick the city `code` by OSM-type priority: N (node) > R (relation)
     * > W (way) > anything else. Used during upsert so a stored code is
     * only replaced when the incoming code is of a higher-priority type.
     * When the two codes share the same priority prefix, keep the
     * existing code to avoid churn.
     */
    private function pickCityCodeByPriority(?string $existing, ?string $incoming): ?string {
        $candidates = array_filter([$existing, $incoming], fn($c) => $c !== null && $c !== '');
        if (empty($candidates))
            return null;
        if (count($candidates) === 1)
            return reset($candidates);

        $rank = ['N' => 0, 'R' => 1, 'W' => 2];
        $rankExisting = $rank[strtoupper(substr((string)$existing, 0, 1))] ?? 3;
        $rankIncoming = $rank[strtoupper(substr((string)$incoming, 0, 1))] ?? 3;

        // Higher-priority (lower rank number) wins. Tie -> keep existing.
        return $rankIncoming < $rankExisting ? $incoming : $existing;
    }

    /**
     * Collapse the Overpass response to one element per city. Overpass returns
     * node + way + relation elements for the same city, all sharing the same
     * wikidata tag but with different OSM ids (hence different city `code`s).
     * Inserting all of them would create multiple rows per city and trip the
     * unique `code` index on re-runs.
     *
     * Preference order: relation (administrative boundary polygon) > way >
     * node (point) > other. Elements without a wikidata tag pass through
     * untouched (we can't group them by city identity).
     */
    private function dedupOverpassCityElements(array $elements): array {
        $rank = ['relation' => 0, 'way' => 1, 'node' => 2];
        $byWiki  = []; // lowercased wikidata => [element, rank]
        $without = [];

        foreach ($elements as $element) {
            $wiki = strtolower(trim((string)($element['tags']['wikidata'] ?? '')));
            $type = $element['type'] ?? '';
            $r    = $rank[$type] ?? 3;

            if ($wiki === '') {
                $without[] = $element;
                continue;
            }

            if (!isset($byWiki[$wiki]) || $r < $byWiki[$wiki][1]) {
                $byWiki[$wiki] = [$element, $r];
            }
        }

        return array_merge(array_column($byWiki, 0), $without);
    }

    private function fetchNeighborhoodsFromNominatim(string $cityCode): array {
        /** @var City $city */
        $city = City::where('code', $cityCode)->first();
        if (!$city)
            return [];

        $dataList = [];

        try {
            // Prefer Overpass area query by wikidata ID (most precise)
            if ($city->wiki_data_id) {
                $elements = $this->nominatimService->overpassNeighborhoodsByWikiDataId($city->wiki_data_id);
                foreach ($elements as $element) {
                    $data = $this->nominatimService->parseOverpassNeighborhoodElement($element, $cityCode);
                    if (empty($data['name']) || empty($data['code']))
                        continue;

                    $dataList[] = $data;
                }
                return $dataList;
            }

            // Fallback to Nominatim details by place_id
            if ($city->place_id) {
                $results = $this->nominatimService->nominatimDetailsByPlaceId($city->place_id);
                foreach ($results as $result) {
                    $data = $this->nominatimService->parseNeighborhoodResult($result, $cityCode);
                    if (empty($data['name']) || empty($data['code']))
                        continue;

                    $dataList[] = $data;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Nominatim fetch failed for neighborhoods in city {$cityCode}: " . $e->getMessage());
        }

        return $dataList;
    }
}