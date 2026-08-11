<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use function config;

/**
 * Class GeoAdministrativeEntityName
 * @package Ionutgrecu\LaravelGeo\Models
 * @property string osm_id
 * @property string language
 * @property string name
 * @property string created_at
 * @property string updated_at
 */
class GeoAdministrativeEntityName extends Model {
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['osm_id', 'language', 'created_at'];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'administrative_entity_names');
        parent::__construct($attributes);
    }

    function entity(): BelongsTo {
        return $this->belongsTo(GeoAdministrativeEntity::class, 'osm_id', 'osm_id');
    }

    /**
     * Idempotent upsert keyed on the composite (osm_id, language) PK.
     * Re-running with the same key overwrites the name with the latest value.
     *
     * Uses a DB-level upsert() rather than firstOrNew + save(): osm_id and
     * language are both guarded, so firstOrNew's constructor fill() would
     * drop them (NOT NULL violation on insert); and the composite PK has no
     * single $primaryKey, so save() UPDATE would target a non-existent id.
     */
    public static function upsertName(string $osmId, string $language, string $name): void {
        $instance = new static();
        $now      = now();
        $conn     = $instance->getConnection();
        $table    = $instance->getTable();

        $conn->table($table)->upsert(
            [
                'osm_id'     => $osmId,
                'language'   => $language,
                'name'       => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['osm_id', 'language'], // unique columns (composite PK)
            ['name', 'updated_at']  // columns to update on conflict
        );
    }
}