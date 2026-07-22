<?php

namespace Ionutgrecu\LaravelGeo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ionutgrecu\LaravelGeo\Models\Neighborhood;
use Ionutgrecu\LaravelGeo\Services\NominatimService;

class EnrichNeighborhoodBoundaryJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $neighborhoodCode,
    ) {}

    public function handle(NominatimService $nominatim): void {
        $neighborhood = Neighborhood::where('code', $this->neighborhoodCode)->first();
        if (!$neighborhood) {
            return;
        }

        // Already enriched with a real polygon — skip the Nominatim call.
        if ($neighborhood->polygon !== null && $neighborhood->polygon !== '') {
            return;
        }

        if (!$neighborhood->osm_id) {
            return;
        }

        $geometry = $nominatim->nominatimLookupPolygon($neighborhood->osm_id);
        if ($geometry) {
            $neighborhood->polygon = json_encode($geometry);
            $neighborhood->save();
        }
    }
}