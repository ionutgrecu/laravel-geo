<?php

namespace Ionutgrecu\LaravelGeo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ionutgrecu\LaravelGeo\Models\County;
use Ionutgrecu\LaravelGeo\Services\NominatimService;

class EnrichCountyBoundaryJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $countyCode,
        public string $osmId,
    ) {}

    public function handle(NominatimService $nominatim): void {
        $county = County::where('code', $this->countyCode)->first();
        if (!$county) {
            return;
        }

        // Already enriched with a real polygon — skip the Nominatim call.
        if ($county->polygon !== null && $county->polygon !== '') {
            return;
        }

        $geometry = $nominatim->nominatimLookupPolygon($this->osmId);
        if ($geometry) {
            $county->polygon = json_encode($geometry);
            $county->save();
        }
    }
}