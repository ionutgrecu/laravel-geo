<?php

namespace Ionutgrecu\LaravelGeo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntityName;
use Ionutgrecu\LaravelGeo\Services\WikidataService;

class FillContinents extends Command {
    protected $signature   = 'geo:fill-continents';
    protected $description = 'Fetch continents from Wikidata SPARQL and upsert them into geo_administrative_entities.';

    protected WikidataService $wikidataService;

    public function __construct() {
        parent::__construct();
        $this->wikidataService = app(WikidataService::class);
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

        foreach ($continents as $continent) {
            try {
                $names = $continent['names'] ?? [];
                unset($continent['names']);

                if (empty($continent['osm_id']) || empty($continent['name'])) {
                    $bar->advance();
                    continue;
                }

                $osmId = $continent['osm_id'];
                unset($continent['osm_id']); // guarded on the model — set as the firstOrNew key

                $entity = GeoAdministrativeEntity::firstOrNew(['osm_id' => $osmId]);
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
                    GeoAdministrativeEntityName::upsertName($osmId, $n['language'], $n['name']);
                    $namesFilled++;
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
        $this->info("Done. Filled {$filled} of " . count($continents) . " continent(s), {$namesFilled} name(s).");

        return 0;
    }
}