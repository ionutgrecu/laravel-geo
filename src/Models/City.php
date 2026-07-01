<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ionutgrecu\LaravelGeo\Builders\CityQueryBuilder;

/**
 * Class City
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string county_code
 * @property string name
 * @property string latitude
 * @property string longitude
 * @property string wiki_data_id
 * @property string type
 * @property int place_rank
 * @property int|null population
 * @property string place_id
 * @property string polygon
 * @property string created_at
 * @property string updated_at
 */
class City extends Model {
    protected $guarded = ['id', 'created_at'];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'cities');
        parent::__construct($attributes);
    }

    protected static function booted() {
        static::addGlobalScope('withCounty', function (Builder $builder) {
            $builder->with('county');
        });
    }

    function county(): BelongsTo {
        return $this->belongsTo(County::class, 'county_code', 'code');
    }

    function neighborhoods(): HasMany {
        return $this->hasMany(Neighborhood::class, 'city_code', 'code');
    }

    public function newEloquentBuilder($query) {
        return new CityQueryBuilder($query);
    }
}
