<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use function config;

/**
 * Class GeoAdministrativeEntity
 * @package Ionutgrecu\LaravelGeo\Models
 * @property string osm_id
 * @property string|null parent_osm_id
 * @property string|null country_osm_id
 * @property string name
 * @property string type
 * @property int|null admin_level
 * @property string|null wikidata_id
 * @property int|null population
 * @property string|null latitude
 * @property string|null longitude
 * @property array|null tags
 * @property string created_at
 * @property string updated_at
 */
class GeoAdministrativeEntity extends Model {
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['osm_id', 'created_at'];

    protected $casts = [
        'tags'        => 'array',
        'admin_level' => 'integer',
        'population'  => 'integer',
    ];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'administrative_entities');
        parent::__construct($attributes);
    }

    function parent(): BelongsTo {
        return $this->belongsTo(self::class, 'parent_osm_id', 'osm_id');
    }

    function country(): BelongsTo {
        return $this->belongsTo(self::class, 'country_osm_id', 'osm_id');
    }

    function children(): HasMany {
        return $this->hasMany(self::class, 'parent_osm_id', 'osm_id');
    }

    function names(): HasMany {
        return $this->hasMany(GeoAdministrativeEntityName::class, 'osm_id', 'osm_id');
    }
}