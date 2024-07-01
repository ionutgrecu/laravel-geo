<?php

namespace Ionutgrecu\LaravelGeo\Services;

use function is_file;
use function strtoupper;

/**
 * Class StaticLocationsService
 * @package Ionutgrecu\LaravelGeo\Services
 * @description This class is responsible for working with static locations (regions, countries, counties).
 */
class JsonLocationsService {
    public function __construct() {
    }

    function getRegions(): array {
        return json_decode(file_get_contents(__DIR__ . '/../../data/regions.json'), true);
    }

    function getCountries(?string $regionCode = null): array {
        $countries = json_decode(file_get_contents(__DIR__ . '/../../data/countries.json'), true);

        if (!$regionCode)
            return $countries;

        return array_filter($countries, function ($country) use ($regionCode) {
            return $country['regionCode'] === $regionCode;
        });
    }

    function getCounties(?string $countryCode = null): array {
        if ($countryCode) {
            $countryCode = strtoupper($countryCode);

            if (is_file(__DIR__ . '/../../data/counties/' . $countryCode . '.json'))
                return json_decode(file_get_contents(__DIR__ . '/../../data/counties/' . $countryCode . '.json'), true);
            else
                return [];
        }

        $counties = [];
        foreach (glob(__DIR__ . '/../../data/counties/*.json') as $filename) {
            $counties = array_merge($counties, json_decode(file_get_contents($filename), true));
        }

        return $counties;
    }
}