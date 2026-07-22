<?php

namespace Ionutgrecu\LaravelGeo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ionutgrecu\LaravelGeo\Models\Country;
use Ionutgrecu\LaravelGeo\Services\NominatimService;

class FillMissingCountryData extends Command {
    protected $signature   = 'geo:fill-countries {--region= : Region code (e.g. EU) to limit scope} {--limit= : Max countries to process}';
    protected $description = 'Fill missing country data (osm_id, polygon) from Nominatim.';

    protected NominatimService $nominatimService;

    public function __construct() {
        parent::__construct();
        $this->nominatimService = app(NominatimService::class);
    }

    public function handle(): int {
        $region = $this->option('region') ? strtoupper(trim($this->option('region'))) : null;
        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // Drop the withRegion global scope to avoid N+1 eager-loading regions
        // for a potentially large batch. Select any country that is missing
        // its osm_id or its polygon.
        $query = Country::withoutGlobalScope('withRegion')
            ->where(function ($q) {
                $q->whereNull('osm_id')->orWhere('osm_id', '')
                    ->orWhereNull('polygon')->orWhere('polygon', '');
            });

        if ($region) {
            $query->where('region_code', $region);
        }

        if ($limit && $limit > 0) {
            $query->take($limit);
        }

        $countries = $query->get();

        if ($countries->isEmpty()) {
            $this->info('No countries with missing data found.');
            return 0;
        }

        $this->info("Filling missing data for {$countries->count()} country(ies).");

        $bar = $this->output->createProgressBar($countries->count());
        $bar->start();

        $osmFilled  = 0;
        $polyFilled = 0;

        foreach ($countries as $country) {
            try {
                // Step 1: resolve the OSM id when missing.
                if (empty($country->osm_id)) {
                    $osmId = $this->nominatimService->overpassCountryOsmId($country->code);

                    if ($osmId) {
                        $country->osm_id = $osmId;
                        $country->save();
                        $osmFilled++;
                    } else {
                        $this->newLine();
                        $this->warn("No OSM id for {$country->code} ({$country->name}) — skipping polygon.");
                        $bar->advance();
                        continue;
                    }
                }

                // Step 2: fetch the boundary polygon via Nominatim /lookup
                // using the (now stored) osm_id.
                if (empty($country->polygon)) {
                    $geometry = $this->nominatimService->nominatimLookupPolygon($country->osm_id);

                    if ($geometry) {
                        $country->polygon = json_encode($geometry);
                        $country->save();
                        $polyFilled++;
                    } else {
                        $this->newLine();
                        $this->warn("No polygon for {$country->code} ({$country->name}).");
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to fill data for country {$country->code}: " . $e->getMessage());
                $this->newLine();
                $this->error("Failed for {$country->code}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Filled osm_id for {$osmFilled} and polygon for {$polyFilled} of {$countries->count()} country(ies).");

        return 0;
    }
}