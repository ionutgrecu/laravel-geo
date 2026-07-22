<?php

namespace Ionutgrecu\LaravelGeo;

use Illuminate\Support\ServiceProvider;
use Ionutgrecu\LaravelGeo\Console\FillMissingCountryData;
use Ionutgrecu\LaravelGeo\Console\LocationsImport;
use Ionutgrecu\LaravelGeo\Services\AddressSearchService;
use Ionutgrecu\LaravelGeo\Services\NominatimService;
use Ionutgrecu\LaravelGeo\Services\ReverseGeocodeService;

class LaravelGeoServiceProvider extends ServiceProvider {
    public function register() {
        $configPath = __DIR__ . '/../config/geo.php';
        $this->mergeConfigFrom($configPath, 'geo');
        $this->publishes([$configPath => config_path('geo.php')], 'config');

        $this->app->singleton('locations.import', function ($app) {
            return new LocationsImport();
        });
        $this->commands('locations.import');

        $this->app->singleton('countries.fill-missing-data', function ($app) {
            return new FillMissingCountryData();
        });
        $this->commands('countries.fill-missing-data');

        $this->app->singleton(NominatimService::class, function ($app) {
            return new NominatimService();
        });

        $this->app->singleton(AddressSearchService::class, function ($app) {
            return new AddressSearchService();
        });

        $this->app->singleton(ReverseGeocodeService::class, function ($app) {
            return new ReverseGeocodeService();
        });
    }

    public function boot() {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
