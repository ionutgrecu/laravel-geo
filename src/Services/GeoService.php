<?php

namespace Ionutgrecu\LaravelGeo\Services;

use Illuminate\Database\Eloquent\Collection;
use Ionutgrecu\LaravelGeo\Models\City;
use Ionutgrecu\LaravelGeo\Models\Country;
use Ionutgrecu\LaravelGeo\Models\County;
use Ionutgrecu\LaravelGeo\Models\Region;
use Websea\Iqapp\Helpers\iq;

/**
 * Class GeoService
 * @package Ionutgrecu\LaravelGeo\Services
 * @description This class is responsible for working with app available geo locations (regions, countries, counties).
 */
class GeoService {
    protected JsonLocationsService $jsonLocationsService;

    public function __construct() {
        $this->jonLocationsService = app(JsonLocationsService::class);
    }

    /**
     * @param array|null $regions
     * @return \Generator
     * @description This method imports json regions (known region) into the database (available regions). If the $regions parameter is provided, only the regions with the specified ISO2 codes will be imported.
     */
    function importRegions(?array $regions = null) {
        $staticRegions = $this->jonLocationsService->getRegions();
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

    function importCity(City $city) {
        $cityModel = City::firstOrCreate([
            'code' => $city->getCode(),
        ], [
            'county_iso_code' => $city->parent()->getIsoCode(),
            'name' => $city->getName(),
            'latitude' => $city->getLatitude(),
            'longitude' => $city->getLongitude(),
        ]);

        return $cityModel;
    }

    function getRegions(): Collection {
        return Region::all();
    }

    function getCountries(): Collection {
        return Country::all();
    }

    function getLocationsTree(): Collection {
        return Region::with('countries', 'countries.counties')->get();
    }
}