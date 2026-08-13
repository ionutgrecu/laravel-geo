<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ionutgrecu\LaravelGeo\Enums\EntityType;
use function config;

/**
 * Class GeoAdministrativeEntity
 * @package Ionutgrecu\LaravelGeo\Models
 * @property string id
 * @property string|null parent_id
 * @property string|null country_id
 * @property string name
 * @property EntityType type
 * @property int|null admin_level
 * @property int|null place_rank
 * @property array|null bounding_box
 * @property string|null wikidata_id
 * @property int|null population
 * @property string|null latitude
 * @property string|null longitude
 * @property array|null tags
 * @property string|null polygon
 * @property string created_at
 * @property string updated_at
 */
class GeoAdministrativeEntity extends Model {
    protected $primaryKey   = 'id';
    public    $incrementing = false;
    protected $keyType      = 'string';

    protected $guarded = ['created_at'];

    protected $hidden = [
        'polygon',
    ];

    protected $casts = [
        'tags' => 'array',
        'admin_level' => 'integer',
        'place_rank' => 'integer',
        'bounding_box' => 'array',
        'population' => 'integer',
        'type' => EntityType::class,
    ];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'administrative_entities');
        parent::__construct($attributes);
    }

    function parent(): BelongsTo {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    function country(): BelongsTo {
        return $this->belongsTo(self::class, 'country_id', 'id');
    }

    function children(): HasMany {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

    function names(): HasMany {
        return $this->hasMany(GeoAdministrativeEntityName::class, 'id', 'id');
    }

    /**
     * Locale-aware name: prefer the names row matching app()->getLocale(),
     * then config('app.locale'), then the static `name` column as the final
     * fallback. Only kicks in when `names` is eager-loaded — otherwise returns
     * the static column without triggering a lazy load.
     */
    public function getNameAttribute(): ?string {
        if (!$this->relationLoaded('names')) {
            return $this->attributes['name'] ?? null;
        }

        foreach ([app()->getLocale(), config('app.locale')] as $lang) {
            $row = $this->names->firstWhere('language', $lang);
            if ($row !== null) {
                return $row->name;
            }
        }

        return $this->attributes['name'] ?? null;
    }
}