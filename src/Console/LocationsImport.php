<?php

namespace Ionutgrecu\LaravelGeo\Console;

use Illuminate\Console\Command;
use Ionutgrecu\LaravelGeo\Models\Region;
use Ionutgrecu\LaravelGeo\Services\GeoService;
use Ionutgrecu\LaravelGeo\Services\JsonLocationsService;
use function explode;

class LocationsImport extends Command {
    protected $signature   = 'geo:import-regions {regions? : Comma separated list of regions to import. Ex.: eu,na. Default: all regions.} {--c|countries : Import countries and counties.} {--C|cities : Import cities.}';
    protected $description = 'Import regions to the database.';

    protected JsonLocationsService $jsonLocationsService;
    protected GeoService           $geoService;

    public function __construct() {
        parent::__construct();
        $this->jsonLocationsService = app(JsonLocationsService::class);
        $this->geoService           = app(GeoService::class);
    }

    public function handle() {
        foreach ($this->geoService->importRegions($this->argument('regions') ? explode(',', $this->argument('regions')) : null) as $regionModel) {
            $this->info("Imported region {$regionModel->name} ({$regionModel->iso2})");

            if ($this->option('countries'))
                $this->importCountriesForRegion($regionModel);
        }

        return 0;
    }

    protected function importCountriesForRegion(Region $region) {
        $countries = $this->jsonLocationsService->getCountries($region->code);
        foreach ($countries as $country) {
            $countryModel = $this->geoService->importCountry($country);
            $this->info("Imported country {$countryModel->name} ({$countryModel->code})");
            $this->importCountiesForCountry($country);

            if ($this->option('cities'))
                $this->importCitiesForCountry($country);
        }
    }

    protected function importCountiesForCountry(array $country) {
        $counties = $this->jsonLocationsService->getCounties($country['code']);
        foreach ($counties as $county) {
            $countyModel = $this->geoService->importCounty($county);
            $this->info("Imported county {$countyModel->name} ({$countyModel->code})");
        }
    }

    protected function importCitiesForCountry(array $country): void {
        $cities = $this->jsonLocationsService->getCities($country['code']);

        if (empty($cities)) {
            $this->warn("No city data found for country {$country['code']}");
            return;
        }

        $bar = $this->output->createProgressBar(count($cities));
        $bar->start();

        foreach ($cities as $city) {
            try {
                $this->geoService->importCity($city);
            }catch(\Exception $e){
                $this->error('Missing county '.$city['countyCode']);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Imported " . count($cities) . " cities for country {$country['code']}");
    }
}