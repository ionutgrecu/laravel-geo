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
}