<?php

namespace Ionutgrecu\LaravelGeo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntityName;
use Ionutgrecu\LaravelGeo\Services\NominatimService;
use Ionutgrecu\LaravelGeo\Services\WikidataService;

class FillContinents extends Command {
    protected $signature   = 'geo:fill-continents';
    protected $description = 'Fetch continents from Wikidata SPARQL and upsert them into geo_administrative_entities.';

    protected WikidataService  $wikidataService;
    protected NominatimService $nominatimService;

    public function __construct() {
        parent::__construct();
        $this->wikidataService   = app(WikidataService::class);
        $this->nominatimService  = app(NominatimService::class);
    }

    public function handle(): int {
        $this->info('Fetching continents from Wikidata…');

        $continents = $this->wikidataService->fetchContinentsWithNames();

        if (empty($continents)) {
            $this->error('Wikidata returned no continents.');
            return 1;
        }

        $bar        = $this->output->createProgressBar(count($continents));
        $bar->start();
        $filled     = 0;
        $namesFilled = 0;
        $enriched    = 0;

        foreach ($continents as $continent) {
            try {
                $names = $continent['names'] ?? [];
                unset($continent['names']);

                // Continent id is 'R' + OSM relation id when Wikidata's P402
                // yielded one; otherwise null and we resolve it via Overpass
                // using the wikidata_id (continents currently have no P402).
                if (empty($continent['id']) && !empty($continent['wikidata_id'])) {
                    $continent['id'] = $this->nominatimService->overpassRelationOsmIdByWikidata($continent['wikidata_id']);
                }

                if (empty($continent['id']) || empty($continent['name'])) {
                    $bar->advance();
                    continue;
                }

                $id = $continent['id'];
                unset($continent['id']); // guarded on the model — set as the firstOrNew key

                $entity = GeoAdministrativeEntity::firstOrNew(['id' => $id]);
                // Only fill empty attributes so re-running the command is idempotent.
                foreach ($continent as $key => $value) {
                    if (empty($entity->{$key})) {
                        $entity->{$key} = $value;
                    }
                }
                $entity->save();
                $filled++;

                foreach ($names as $n) {
                    if (empty($n['language']) || empty($n['name'])) {
                        continue;
                    }
                    GeoAdministrativeEntityName::upsertName($id, $n['language'], $n['name']);
                    $namesFilled++;
                }

                // Inline Nominatim /lookup enrichment for bounding_box +
                // place_rank (Nominatim/OSM concepts not in Wikidata). Skips
                // rows that already have both fields set (idempotent re-runs).
                // $id is already the R{id} Nominatim osm_id form.
                if ($entity->bounding_box === null || $entity->place_rank === null) {
                    $feature = $this->nominatimService->nominatimLookupFeature($id);
                    if ($feature) {
                        if ($entity->bounding_box === null && !empty($feature['boundingbox'])) {
                            $entity->bounding_box = $feature['boundingbox'];
                        }
                        if ($entity->place_rank === null && !empty($feature['place_rank'])) {
                            $entity->place_rank = (int)$feature['place_rank'];
                        }
                        $entity->save();
                        $enriched++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to fill continent: ' . $e->getMessage());
                $this->newLine();
                $this->error('Failed for one continent: ' . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Filled {$filled} of " . count($continents) . " continent(s), {$namesFilled} name(s), {$enriched} enriched with bounding_box/place_rank.");

        return 0;
    }
}