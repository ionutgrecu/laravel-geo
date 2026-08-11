<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use function config;

/**
 * Class GeoAdministrativeEntityName
 * @package Ionutgrecu\LaravelGeo\Models
 * @property string id
 * @property string language
 * @property string name
 * @property string created_at
 * @property string updated_at
 */
class GeoAdministrativeEntityName extends Model {
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'language', 'created_at'];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'administrative_entity_names');
        parent::__construct($attributes);
    }

    function entity(): BelongsTo {
        return $this->belongsTo(GeoAdministrativeEntity::class, 'id', 'id');
    }

    /**
     * Idempotent upsert keyed on the composite (id, language) PK.
     * Re-running with the same key overwrites the name with the latest value.
     *
     * Uses a DB-level upsert() rather than firstOrNew + save(): id and
     * language are both guarded, so firstOrNew's constructor fill() would
     * drop them (NOT NULL violation on insert); and the composite PK has no
     * single $primaryKey, so save() UPDATE would target a non-existent row.
     */
    public static function upsertName(string $id, string $language, string $name): void {
        $instance = new static();
        $now      = now();
        $conn     = $instance->getConnection();
        $table    = $instance->getTable();

        $conn->table($table)->upsert(
            [
                'id'         => $id,
                'language'   => $language,
                'name'       => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['id', 'language'], // unique columns (composite PK)
            ['name', 'updated_at']  // columns to update on conflict
        );
    }
}