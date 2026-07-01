<?php

namespace Ionutgrecu\LaravelGeo;

use Illuminate\Support\ServiceProvider;
use Ionutgrecu\LaravelGeo\Console\LocationsImport;
use Ionutgrecu\LaravelGeo\Services\NominatimService;

class LaravelGeoServiceProvider extends ServiceProvider {
    public function register() {
        $configPath = __DIR__ . '/../config/geo.php';
        $this->mergeConfigFrom($configPath, 'geo');
        $this->publishes([$configPath => config_path('geo.php')], 'config');

        $this->app->singleton('locations.import', function ($app) {
            return new LocationsImport();
        });
        $this->commands('locations.import');

        $this->app->singleton(NominatimService::class, function ($app) {
            return new NominatimService();
        });
    }

    public function boot() {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
