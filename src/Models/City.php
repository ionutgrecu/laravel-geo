<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ionutgrecu\LaravelGeo\Builders\CityQueryBuilder;

/**
 * Class City
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string county_id
 * @property string name
 * @property string latitude
 * @property string longitude
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
        return $this->belongsTo(County::class, 'county_id', 'id');
    }

    public function newEloquentBuilder($query) {
        return new CityQueryBuilder($query);
    }
}
