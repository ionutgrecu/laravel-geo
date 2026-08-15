<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use function config;

/**
 * Class GeoPlaceTranslation
 * @package Ionutgrecu\LaravelGeo\Models
 * @property string base_name
 * @property string language
 * @property string translation
 * @property string created_at
 * @property string updated_at
 */
class GeoPlaceTranslation extends Model {
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['base_name', 'language', 'created_at'];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'place_translations');
        parent::__construct($attributes);
    }

    /**
     * Idempotent upsert keyed on the composite (base_name, language) PK.
     * Re-running with the same key overwrites the translation with the latest value.
     *
     * Uses a DB-level upsert() rather than firstOrNew + save(): base_name and
     * language are both guarded, so firstOrNew's constructor fill() would
     * drop them (NOT NULL violation on insert); and the composite PK has no
     * single $primaryKey, so save() UPDATE would target a non-existent row.
     */
    public static function upsertTranslation(string $baseName, string $language, string $translation): void {
        $instance = new static();
        $now      = now();
        $conn     = $instance->getConnection();
        $table    = $instance->getTable();

        $conn->table($table)->upsert(
            [
                'base_name'  => $baseName,
                'language'   => $language,
                'translation' => $translation,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['base_name', 'language'],      // unique columns (composite PK)
            ['translation', 'updated_at']  // columns to update on conflict
        );
    }
}