<?php

namespace Ionutgrecu\LaravelGeo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ionutgrecu\LaravelGeo\Enums\EntityType;
use Ionutgrecu\LaravelGeo\Jobs\EnrichAdministrativeEntityBoundaryJob;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntityName;
use Ionutgrecu\LaravelGeo\Services\WikidataService;

class FillCountries extends Command {
    protected $signature   = 'geo:fill-countries';
    protected $description = 'Fetch sovereign states (Wikidata Q6256) from Wikidata SPARQL and upsert them into geo_administrative_entities.';

    protected WikidataService $wikidataService;

    public function __construct() {
        parent::__construct();
        $this->wikidataService = app(WikidataService::class);
    }

    public function handle(): int {
        // Build a wikidata_id => id map of existing continent rows so each
        // country's P30 continent Q-id can be resolved to a parent_id.
        $continentMap = GeoAdministrativeEntity::query()
            ->where('type', EntityType::Continent->value)
            ->pluck('id', 'wikidata_id');

        if ($continentMap->isEmpty()) {
            $this->warn('No continents found in geo_administrative_entities — parent_id will be null. Run geo:fill-continents first.');
        }

        $this->info('Fetching countries from Wikidata…');

        $countries = $this->wikidataService->fetchCountriesWithNames();

        if (empty($countries)) {
            $this->error('Wikidata returned no countries.');
            return 1;
        }

        $bar         = $this->output->createProgressBar(count($countries));
        $bar->start();
        $filled      = 0;
        $namesFilled = 0;

        foreach ($countries as $country) {
            try {
                $names = $country['names'] ?? [];
                unset($country['names']);

                $continentQid = $country['continent_qid'] ?? null;
                unset($country['continent_qid']);

                if (empty($country['id']) || empty($country['name'])) {
                    $bar->advance();
                    continue;
                }

                $id = $country['id'];
                unset($country['id']); // guarded on the model — set as the firstOrNew key

                // Resolve parent_id from the continent map (nullable when
                // missing or when no continents are loaded yet).
                $country['parent_id'] = $continentQid && $continentMap->has($continentQid)
                    ? $continentMap->get($continentQid)
                    : null;

                $entity = GeoAdministrativeEntity::firstOrNew(['id' => $id]);
                // Only fill empty attributes so re-running the command is idempotent.
                foreach ($country as $key => $value) {
                    if (empty($entity->{$key})) {
                        $entity->{$key} = $value;
                    }
                }
                $entity->save();
                $filled++;

                EnrichAdministrativeEntityBoundaryJob::dispatch($id);

                foreach ($names as $n) {
                    if (empty($n['language']) || empty($n['name'])) {
                        continue;
                    }
                    GeoAdministrativeEntityName::upsertName($id, $n['language'], $n['name']);
                    $namesFilled++;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to fill country: ' . $e->getMessage());
                $this->newLine();
                $this->error('Failed for one country: ' . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Filled {$filled} of " . count($countries) . " country(ies), {$namesFilled} name(s).");

        return 0;
    }
}