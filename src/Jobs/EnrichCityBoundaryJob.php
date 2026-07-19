<?php

namespace Ionutgrecu\LaravelGeo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ionutgrecu\LaravelGeo\Models\City;
use Ionutgrecu\LaravelGeo\Services\NominatimService;

class EnrichCityBoundaryJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string  $cityCode,
        public string  $countryCode,
        public ?string $countyCode,
    ) {}

    public function handle(NominatimService $nominatim): void {
        $city = City::where('code', $this->cityCode)->first();
        if (!$city) {
            return;
        }

        $polygon = $city->polygon;

        // No polygon stored (element parsed without geometry): nothing to
        // upgrade and no Point to anchor a name-based Overpass query. Mark
        // the job successful and exit — no Overpass call, no retry.
        if ($polygon === null || $polygon === '') {
            return;
        }

        // Already a real polygon (Polygon / MultiPolygon from way/relation
        // cities). Mark the job successful and exit. Continue enrichment
        // only when the stored polygon is exactly a Point (node cities).
        $geometry = json_decode($polygon, true);
        if (!is_array($geometry) || ($geometry['type'] ?? '') !== 'Point') {
            return;
        }

        $boundary = null;
        if ($city->wiki_data_id) {
            $boundary = $nominatim->overpassCityBoundaryByWikiDataId($city->wiki_data_id);
        }
        if (!$boundary && $city->name) {
            $boundary = $nominatim->overpassCityBoundaryByName(
                $city->name, $this->countryCode, $this->countyCode,
            );
        }

        if ($boundary) {
            $city->polygon = json_encode($boundary);
            $city->save();
        }
    }
}