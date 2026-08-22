<?php

namespace Ionutgrecu\LaravelGeo\Tests;

use Ionutgrecu\LaravelGeo\Services\NominatimService;

class OverpassChildrenTest extends TestCase
{
    public function test_it_builds_an_exact_area_query_and_returns_only_candidates(): void
    {
        $service = new FakeOverpassNominatimService();
        $child = [
            'type' => 'relation',
            'id' => 123,
            'tags' => [
                'name' => 'Sector 1',
                'boundary' => 'administrative',
                'admin_level' => '8',
            ],
        ];
        $service->response = [
            'elements' => [
                ['type' => 'area', 'id' => 3600377733],
                $child,
            ],
        ];

        $result = $service->overpassChildCandidates('R377733', true);

        $this->assertSame(NominatimService::OVERPASS_CHILD_STATUS_OK, $result['status']);
        $this->assertSame([$child], $result['elements']);
        $this->assertCount(1, $service->queries);
        $this->assertStringContainsString('relation(377733)->.parent;', $service->queries[0]);
        $this->assertStringContainsString('.parent map_to_area->.parentArea;', $service->queries[0]);
        $this->assertStringContainsString('relation["boundary"="administrative"]["admin_level"]', $service->queries[0]);
        $this->assertStringContainsString('nwr["place"~"^(district|borough|suburb|quarter|neighbou?rhood)$"]["name"]', $service->queries[0]);
    }

    public function test_it_distinguishes_missing_areas_from_provider_failures(): void
    {
        $service = new FakeOverpassNominatimService();
        $service->response = ['elements' => []];

        $noArea = $service->overpassChildCandidates('R123');

        $this->assertSame(NominatimService::OVERPASS_CHILD_STATUS_NO_AREA, $noArea['status']);
        $this->assertSame([], $noArea['elements']);

        $service->response = null;
        $failed = $service->overpassChildCandidates('R123');

        $this->assertSame(NominatimService::OVERPASS_CHILD_STATUS_FAILED, $failed['status']);
        $this->assertSame([], $failed['elements']);
    }

    public function test_node_parents_do_not_make_an_approximate_request(): void
    {
        $service = new FakeOverpassNominatimService();

        $result = $service->overpassChildCandidates('N123', true);

        $this->assertSame(NominatimService::OVERPASS_CHILD_STATUS_NO_AREA, $result['status']);
        $this->assertSame([], $result['elements']);
        $this->assertSame([], $service->queries);
    }

    public function test_closed_way_parent_is_accepted_as_an_exact_area(): void
    {
        $service = new FakeOverpassNominatimService();
        $child = [
            'type' => 'node',
            'id' => 456,
            'tags' => ['name' => 'Centru', 'place' => 'suburb'],
        ];
        $service->response = [
            'elements' => [
                ['type' => 'way', 'id' => 123],
                $child,
            ],
        ];

        $result = $service->overpassChildCandidates('W123', true);

        $this->assertSame(NominatimService::OVERPASS_CHILD_STATUS_OK, $result['status']);
        $this->assertSame([$child], $result['elements']);
        $this->assertStringContainsString('way(123)->.parent;', $service->queries[0]);
    }

    public function test_next_administrative_level_takes_precedence_without_place_tags(): void
    {
        $service = new FakeOverpassNominatimService();
        $elements = [
            $this->administrativeElement(1, 4, 'Parent'),
            $this->administrativeElement(2, 9, 'Deeper child'),
            $this->administrativeElement(3, 8, 'First child'),
            $this->administrativeElement(4, 8, 'Second child'),
            ['type' => 'node', 'id' => 5, 'tags' => ['name' => 'Suburb', 'place' => 'suburb']],
        ];

        $selection = $service->selectOverpassChildCandidates($elements, 'R1', 4, true);

        $this->assertSame('administrative', $selection['mode']);
        $this->assertSame([3, 4], array_column($selection['elements'], 'id'));
    }

    public function test_all_named_urban_places_are_returned_as_a_flat_fallback(): void
    {
        $service = new FakeOverpassNominatimService();
        $elements = [
            ['type' => 'node', 'id' => 10, 'tags' => ['name' => 'Suburb', 'place' => 'suburb']],
            ['type' => 'way', 'id' => 11, 'tags' => ['name' => 'Quarter', 'place' => 'quarter']],
            ['type' => 'relation', 'id' => 12, 'tags' => ['name' => 'Neighbourhood', 'place' => 'neighbourhood']],
            ['type' => 'node', 'id' => 10, 'tags' => ['name' => 'Suburb duplicate', 'place' => 'suburb']],
            ['type' => 'node', 'id' => 13, 'tags' => ['name' => 'Hamlet', 'place' => 'hamlet']],
            ['type' => 'node', 'id' => 14, 'tags' => ['place' => 'suburb']],
        ];

        $selection = $service->selectOverpassChildCandidates($elements, 'R1', 8, true);

        $this->assertSame('settlement', $selection['mode']);
        $this->assertSame([10, 11, 12], array_column($selection['elements'], 'id'));
    }

    public function test_settlement_fallback_can_be_disabled(): void
    {
        $service = new FakeOverpassNominatimService();
        $elements = [
            ['type' => 'node', 'id' => 10, 'tags' => ['name' => 'Suburb', 'place' => 'suburb']],
        ];

        $selection = $service->selectOverpassChildCandidates($elements, 'R1', 8, false);

        $this->assertNull($selection['mode']);
        $this->assertSame([], $selection['elements']);
    }

    /**
     * @dataProvider osmElementProvider
     */
    public function test_it_preserves_the_osm_element_type_in_entity_ids(array $element, string $expectedId, string $expectedType): void
    {
        $service = new FakeOverpassNominatimService();

        $parsed = $service->parseOverpassAdministrativeChildElement($element, 'R1');

        $this->assertSame($expectedId, $parsed['id']);
        $this->assertSame($expectedType, $parsed['type']);
        $this->assertSame('R1', $parsed['parent_id']);
    }

    public function osmElementProvider(): array
    {
        return [
            'node' => [
                ['type' => 'node', 'id' => 10, 'tags' => ['name' => 'Node child', 'place' => 'neighbourhood']],
                'N10',
                'neighborhood',
            ],
            'way' => [
                ['type' => 'way', 'id' => 11, 'tags' => ['name' => 'Way child', 'place' => 'quarter']],
                'W11',
                'quarter',
            ],
            'relation without place' => [
                [
                    'type' => 'relation',
                    'id' => 12,
                    'tags' => ['name' => 'Administrative child', 'boundary' => 'administrative', 'admin_level' => '8'],
                ],
                'R12',
                'municipality',
            ],
        ];
    }

    private function administrativeElement(int $id, int $level, string $name): array
    {
        return [
            'type' => 'relation',
            'id' => $id,
            'tags' => [
                'name' => $name,
                'boundary' => 'administrative',
                'admin_level' => (string)$level,
            ],
        ];
    }
}

class FakeOverpassNominatimService extends NominatimService
{
    public ?array $response = ['elements' => []];
    public array $queries = [];

    public function __construct()
    {
    }

    protected function overpassRequest(string $query, string $context): ?array
    {
        $this->queries[] = $query;

        return $this->response;
    }
}
