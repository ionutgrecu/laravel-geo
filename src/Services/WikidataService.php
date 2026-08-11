<?php

namespace Ionutgrecu\LaravelGeo\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ionutgrecu\LaravelGeo\Enums\EntityType;

class WikidataService {
    /**
     * Wikidata Q-IDs for the 7 continents. Explicit list (rather than
     * `wdt:P31 wd:Q5107`) to pin down which entity represents Oceania.
     */
    public const CONTINENT_QIDS = ['Q15', 'Q18', 'Q46', 'Q48', 'Q49', 'Q51', 'Q538'];

    /**
     * Single SPARQL request: one row per (continent, language) with the
     * rdfs:label as ?name, plus OPTIONAL coord/population (repeated across
     * language rows for the same continent). ORDER BY ?continent ?language
     * so the English label usually lands first per continent.
     */
    public function queryContinents(): array {
        $values = implode(' ', array_map(fn($qid) => "wd:{$qid}", self::CONTINENT_QIDS));

        $query =
            'SELECT ?continent ?language ?name ?coord ?population WHERE {'
          . '  VALUES ?continent { ' . $values . ' }'
          . '  ?continent rdfs:label ?name .'
          . '  BIND(LANG(?name) AS ?language)'
          . '  FILTER(?language != "")'
          . '  OPTIONAL { ?continent wdt:P625 ?coord. }'
          . '  OPTIONAL { ?continent wdt:P1082 ?population. }'
          . '}'
          . 'ORDER BY ?continent ?language';

        $data = $this->sparqlRequest($query);

        return $data['results']['bindings'] ?? [];
    }

    /**
     * One SPARQL request returning each continent's entity fields plus
     * every per-language rdfs:label. Returns one entry per continent:
     *
     *   [
     *     'osm_id' => 'WDQ15', 'name' => 'Africa', 'type' => Continent,
     *     'wikidata_id' => 'Q15', 'population' => ..., 'latitude' => ...,
     *     'longitude' => ..., 'tags' => null,
     *     'names' => [['language' => 'en', 'name' => 'Africa'], ...],
     *   ]
     *
     * The entity `name` is the English label when present, else the first
     * label encountered (ORDER BY puts ?continent first, so this is stable).
     *
     * @return array<int, array>
     */
    public function fetchContinentsWithNames(): array {
        $bindings = $this->queryContinents();
        $byQid    = [];

        foreach ($bindings as $binding) {
            $uri  = $binding['continent']['value'] ?? null;
            $qid  = $uri ? substr(strrchr($uri, '/'), 1) : null;
            $lang = $binding['language']['value'] ?? null;
            $name = $binding['name']['value'] ?? null;
            if (!$qid || !$lang || !$name) {
                continue;
            }

            if (!isset($byQid[$qid])) {
                $lat = $lon = null;
                if (($wkt = $binding['coord']['value'] ?? null)
                    && preg_match('/Point\(\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\)/', $wkt, $m)) {
                    $lon = $m[1];
                    $lat = $m[2];
                }

                $byQid[$qid] = [
                    'osm_id'      => 'WD' . $qid,
                    'wikidata_id' => $qid,
                    'type'        => EntityType::Continent,
                    'name'        => null, // prefer 'en'
                    'population'  => isset($binding['population']['value']) ? (int)$binding['population']['value'] : null,
                    'latitude'    => $lat,
                    'longitude'   => $lon,
                    'tags'        => null,
                    'names'       => [],
                ];
            }

            $byQid[$qid]['names'][] = ['language' => $lang, 'name' => $name];
            if ($lang === 'en' && $byQid[$qid]['name'] === null) {
                $byQid[$qid]['name'] = $name;
            }
        }

        // Fallback: continent with no English label uses the first available.
        foreach ($byQid as $qid => &$data) {
            if ($data['name'] === null && !empty($data['names'])) {
                $data['name'] = $data['names'][0]['name'];
            }
        }

        return array_values($byQid);
    }

    protected function sparqlRequest(string $query): ?array {
        $url = rtrim(config('geo.wikidata.base_url'), '/');
        $ua  = config('geo.wikidata.user_agent');
        $to  = (int) config('geo.wikidata.timeout', 60);

        try {
            $response = Http::withHeaders([
                'Accept'     => 'application/sparql-results+json',
                'User-Agent' => $ua,
            ])->timeout($to)->asForm()->post($url, ['query' => $query]);

            if (!$response->successful()) {
                Log::warning('Wikidata SPARQL failed: HTTP ' . $response->status());
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('Wikidata SPARQL exception: ' . $e->getMessage());
            return null;
        }
    }
}