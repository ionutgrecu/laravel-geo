<?php

namespace Ionutgrecu\LaravelGeo\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ionutgrecu\LaravelGeo\Enums\EntityType;
use function file_put_contents;
use function json_encode;
use function nl2br;
use function str_replace;
use function var_export;

class WikidataService {
    /**
     * Wikidata Q-IDs for the 7 continents. Explicit list (rather than
     * `wdt:P31 wd:Q5107`) to pin down which entity represents Oceania.
     */
    public const CONTINENT_QIDS = ['Q15', 'Q18', 'Q46', 'Q48', 'Q49', 'Q51', 'Q538'];

    /**
     * Single SPARQL request: one row per (continent, language) with the
     * rdfs:label as ?name, plus OPTIONAL osmRelation/coord/population
     * (repeated across language rows for the same continent). ORDER BY
     * ?continent ?language so the English label usually lands first per
     * continent. The OSM relation id (wdt:P402) is used as the entity id
     * (WD-prefixed); when absent we fall back to the Wikidata Q-id
     * (also WD-prefixed).
     */
    public function queryContinents(): array {
        $values = implode(' ', array_map(fn($qid) => "wd:{$qid}", self::CONTINENT_QIDS));

        $query =
            'SELECT ?continent ?continentLabel ?language ?name ?osmRelation ?coord ?population WHERE {'
          . "  VALUES ?continent { {$values} }"
          . '  ?continent rdfs:label ?name .'
          . '  BIND(LANG(?name) AS ?language)'
          . '  OPTIONAL {'
          . '    SELECT ?continent (SAMPLE(?osm) AS ?osmRelation) WHERE {'
          . "      VALUES ?continent { {$values} }"
          . '      ?continent wdt:P402 ?osm .'
          . '    } GROUP BY ?continent'
          . '  }'
          . '  OPTIONAL { ?continent wdt:P625 ?coord . }'
          . '  OPTIONAL {'
          . '    SELECT ?continent (MAX(?pop) AS ?population) WHERE {'
          . "      VALUES ?continent { {$values} }"
          . '      ?continent wdt:P1082 ?pop .'
          . '    } GROUP BY ?continent'
          . '  }'
          . '} ORDER BY ?continent ?language';

        $data = $this->sparqlRequest($query);

        return $data['results']['bindings'] ?? [];
    }

    /**
     * One SPARQL request returning each continent's entity fields plus
     * every per-language rdfs:label. Returns one entry per continent:
     *
     *   [
     *     'id' => 'WD1466586', 'name' => 'Africa', 'type' => Continent,
     *     'wikidata_id' => 'Q15', 'population' => ..., 'latitude' => ...,
     *     'longitude' => ..., 'tags' => null,
     *     'names' => [['language' => 'en', 'name' => 'Africa'], ...],
     *   ]
     *
     * `id` is the OSM relation id (wdt:P402) prefixed with 'WD' when
     * present, else 'WD' + Q-id so the row is still uniquely keyed.
     * `wikidata_id` always holds the bare Q-id. The entity `name` is the
     * English label when present, else the first label encountered (ORDER
     * BY puts ?continent first, so this is stable).
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

                // Continent id: prefer the OSM relation id (wdt:P402) prefixed
                // with 'WD' (the Wikidata-row convention for this table), else
                // fall back to 'WD' + Q-id so the row is still uniquely keyed.
                $osmRelation = $binding['osmRelation']['value'] ?? null;
                $id          = $osmRelation !== null && $osmRelation !== ''
                    ? 'WD' . $osmRelation
                    : 'WD' . $qid;

                $byQid[$qid] = [
                    'id'          => $id,
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

    /**
     * Single SPARQL request: one row per (country, language) with the
     * rdfs:label as ?name, plus OPTIONAL osmRelation (P402) / continent
     * (P30) / coord (P625) / population (P1082), repeated across language
     * rows for the same country. Targets `wd:Q6256` (sovereign state).
     * ORDER BY ?country ?language so the English label usually lands first
     * per country.
     */
    public function queryCountries(): array {
        $query =
            'SELECT ?country ?language ?name ?osmRelation ?continent ?coord ?population WHERE {'
          . '  ?country wdt:P31 wd:Q6256 .'
          . '  ?country rdfs:label ?name .'
          . '  BIND(LANG(?name) AS ?language)'
          . '  FILTER(?language != "")'
          . '  OPTIONAL { ?country wdt:P402 ?osmRelation . }'
          . '  OPTIONAL { ?country wdt:P30 ?continent . }'
          . '  OPTIONAL { ?country wdt:P625 ?coord . }'
          . '  OPTIONAL { ?country wdt:P1082 ?population . }'
          . '}'
          . 'ORDER BY ?country ?language';

        $data = $this->sparqlRequest($query);

        return $data['results']['bindings'] ?? [];
    }

    /**
     * One SPARQL request returning each country's entity fields plus every
     * per-language rdfs:label. Returns one entry per country:
     *
     *   [
     *     'id' => 'R51477', 'name' => 'Germany', 'type' => Country,
     *     'wikidata_id' => 'Q183', 'continent_qid' => 'Q46',
     *     'population' => ..., 'latitude' => ..., 'longitude' => ...,
     *     'tags' => null,
     *     'names' => [['language' => 'en', 'name' => 'Germany'], ...],
     *   ]
     *
     * `id` is 'R' + the OSM relation id (wdt:P402), matching the
     * R{id}/W{id}/N{id} Nominatim convention (countries are OSM relations).
     * Countries without P402 are skipped (not returned). `wikidata_id`
     * always holds the bare Q-id. `continent_qid` is the bare continent
     * Q-id from P30 (nullable) — callers resolve it to a `parent_id`
     * against the existing continent rows. The entity `name` is the
     * English label when present, else the first label encountered
     * (ORDER BY puts ?country first, so this is stable).
     *
     * @return array<int, array>
     */
    public function fetchCountriesWithNames(): array {
        $bindings = $this->queryCountries();
        $byQid    = [];

        foreach ($bindings as $binding) {
            $uri  = $binding['country']['value'] ?? null;
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

                // Country id: 'R' + OSM relation id (wdt:P402), matching the
                // R{id}/W{id}/N{id} Nominatim convention (countries are OSM
                // relations, so the 'R' prefix is required to disambiguate
                // from node/way ids). Countries without P402 are skipped.
                $osmRelation = $binding['osmRelation']['value'] ?? null;
                if ($osmRelation === null || $osmRelation === '') {
                    continue;
                }
                $id = 'R' . $osmRelation;

                // Continent Q-id from P30 (bare, e.g. "Q46"); nullable.
                $continentUri = $binding['continent']['value'] ?? null;
                $continentQid = $continentUri ? substr(strrchr($continentUri, '/'), 1) : null;

                $byQid[$qid] = [
                    'id'           => $id,
                    'wikidata_id'  => $qid,
                    'type'         => EntityType::Country,
                    'name'         => null, // prefer 'en'
                    'population'   => isset($binding['population']['value']) ? (int)$binding['population']['value'] : null,
                    'latitude'     => $lat,
                    'longitude'    => $lon,
                    'tags'         => null,
                    'continent_qid' => $continentQid,
                    'names'        => [],
                ];
            }

            $byQid[$qid]['names'][] = ['language' => $lang, 'name' => $name];
            if ($lang === 'en' && $byQid[$qid]['name'] === null) {
                $byQid[$qid]['name'] = $name;
            }
        }

        // Fallback: country with no English label uses the first available.
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