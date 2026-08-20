<?php

namespace Ionutgrecu\LaravelGeo\Tests;

use Ionutgrecu\LaravelGeo\Enums\EntityType;

class EntityTypeTest extends TestCase
{
    public function test_it_returns_all_case_keys(): void
    {
        $this->assertSame(
            array_map(static fn (EntityType $type): string => $type->name, EntityType::cases()),
            EntityType::getKeys()
        );
    }

    public function test_it_returns_all_case_values(): void
    {
        $this->assertSame(
            array_map(static fn (EntityType $type): string => $type->value, EntityType::cases()),
            EntityType::getValues()
        );
    }
}
