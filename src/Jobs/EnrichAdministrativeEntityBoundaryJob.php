<?php

namespace Ionutgrecu\LaravelGeo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;
use Ionutgrecu\LaravelGeo\Services\NominatimService;

class EnrichAdministrativeEntityBoundaryJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $id,
    ) {
    }

    public function handle(): void {
        $nominatimService = app(NominatimService::class);

        $entity = GeoAdministrativeEntity::find($this->id);
        if (!$entity) {
            return;
        }

        // Idempotent: skip the Nominatim call when both fields are already set.
        if (!empty($entity->polygon) && $entity->admin_level !== null) {
            return;
        }

        $feature = $nominatimService->nominatimLookupFeature($this->id);
        if (!$feature) {
            return;
        }

        $geometry    = $feature['geojson'] ?? null;
        $placeRank   = $feature['place_rank'] ?? null;
        $boundingBox = $feature['boundingbox'] ??  null;
        $adminLevel  = $nominatimService->overpassAdminLevel($this->id);

        if ($geometry && empty($entity->polygon)) {
            $entity->polygon = json_encode($geometry);
        }
        if ($adminLevel !== null && $entity->admin_level === null) {
            $entity->admin_level = (int)$adminLevel;
        }
        if ($placeRank !== null && $entity->place_rank === null) {
            $entity->place_rank = (int)$placeRank;
        }
        if ($boundingBox !== null && $entity->bounding_box === null) {
            $entity->bounding_box = $boundingBox;
        }
        $entity->save();
    }
}